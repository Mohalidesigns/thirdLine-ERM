<?php

namespace App\Services\Tprm\Screening;

use App\Enums\Tprm\EngagementStatus;
use App\Enums\Tprm\FindingSeverity;
use App\Enums\Tprm\ScreeningDecision;
use App\Enums\Tprm\SignalSeverity;
use App\Enums\Tprm\SignalType;
use App\Events\Tprm\EngagementScoreInvalidated;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\MonitoringSignal;
use App\Models\Tprm\ScreeningMatch;
use App\Models\Tprm\ThirdParty;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\Tprm\Findings\FindingService;
use Illuminate\Support\Facades\DB;

/**
 * What happens when somebody confirms a sanctions match — FR-MON-05, AC-08.
 *
 * FOUR THINGS, AND THEY ARE NOT NEGOTIABLE.
 *
 *   EVERY ENGAGEMENT WITH THE VENDOR IS SUSPENDED. Not flagged, not scored
 *   higher — stopped. Continuing to transact with a designated party is a
 *   criminal offence, and a module that merely raised the risk rating would be
 *   describing the offence rather than preventing it.
 *
 *   THE RESIDUAL SCORE IS FORCED TO 100 through the SU override, so every
 *   report, board pack and dashboard shows the same thing without anybody
 *   remembering to.
 *
 *   THE AML FUNCTION IS NOTIFIED, because the person who confirmed the match
 *   in a vendor register is not necessarily the person who owes the regulator
 *   a report.
 *
 *   AN STR TASK OPENS WITH A 24-HOUR DEADLINE — CBN AML/CFT Reg. 38. The
 *   deadline is the point: a suspicious transaction report filed a week later
 *   is a breach in its own right, and a task with no clock is a task that
 *   arrives late.
 *
 * IT IS IDEMPOTENT. Confirming the same match twice, or a second match on the
 * same vendor, must not suspend an already-suspended engagement again or open
 * a second STR clock. `escalated` on the match row is what makes that true.
 *
 * A FALSE POSITIVE UNWINDS NOTHING AUTOMATICALLY. Deciding that a match was
 * wrong does not resume the engagements — somebody has to do that deliberately,
 * with the suspension on the record, because an automatic resume would make
 * the whole chain reversible by a single mis-click.
 */
class SanctionsEscalation
{
    public function __construct(private readonly FindingService $findings) {}

    /**
     * Record a decision on a match and, where it is a true match, escalate.
     *
     * @return array{decided: bool, escalated: bool, engagements_suspended: int, reason: string|null}
     */
    public function decide(
        ScreeningMatch $match,
        ScreeningDecision $decision,
        string $rationale,
        User $decider,
    ): array {
        if (trim($rationale) === '') {
            return [
                'decided' => false,
                'escalated' => false,
                'engagements_suspended' => 0,
                // Required on a dismissal as much as a confirmation: "different
                // date of birth, no connection to the entity" is what makes a
                // dismissal reviewable by an examiner who cannot re-run the
                // search as it was.
                'reason' => 'Every screening decision needs a rationale, including a false positive.',
            ];
        }

        $match->forceFill([
            'decision' => $decision->value,
            'decided_by' => $decider->getKey(),
            'decided_at' => now(),
            'rationale' => $rationale,
        ])->save();

        if ($decision !== ScreeningDecision::TrueMatch) {
            return ['decided' => true, 'escalated' => false, 'engagements_suspended' => 0, 'reason' => null];
        }

        if ($match->escalated) {
            return [
                'decided' => true,
                'escalated' => false,
                'engagements_suspended' => 0,
                'reason' => 'This match has already been escalated.',
            ];
        }

        $suspended = $this->escalate($match, $decider);

        return [
            'decided' => true,
            'escalated' => true,
            'engagements_suspended' => $suspended,
            'reason' => null,
        ];
    }

    /**
     * The chain itself.
     *
     * @return int engagements suspended
     */
    public function escalate(ScreeningMatch $match, ?User $actor = null): int
    {
        $thirdPartyId = $match->check?->thirdPartyId();

        if ($thirdPartyId === null) {
            return 0;
        }

        $thirdParty = ThirdParty::query()->find($thirdPartyId);

        if ($thirdParty === null) {
            return 0;
        }

        $engagements = Engagement::query()
            ->where('third_party_id', $thirdParty->getKey())
            ->whereNotIn('status', [
                EngagementStatus::Terminated->value,
                EngagementStatus::Archived->value,
            ])
            ->get();

        DB::transaction(function () use ($match, $thirdParty, $engagements, $actor) {
            foreach ($engagements as $engagement) {
                // `forceFill`, not the transition service. The lifecycle does
                // not permit every status to reach `monitoring_exception`, and
                // a legal prohibition does not wait for a state machine to
                // allow it — the vendor is stopped and the audit row records
                // that it was stopped by a sanctions confirmation.
                $engagement->forceFill([
                    'status' => EngagementStatus::MonitoringException->value,
                    'updated_by' => $actor?->getKey(),
                ])->save();

                $engagement->writeAuditRow('suspended_sanctions_match', null, [
                    'match_id' => $match->getKey(),
                    'matched_name' => $match->matched_name,
                    'list' => $match->list_name,
                    'citation' => 'CBN AML/CFT Regulations 2022, Reg. 29 and Reg. 38',
                    'note' => 'Suspended automatically on confirmation of a sanctions match. Resuming is a '
                        .'deliberate act and is not undone by reversing the screening decision.',
                ]);
            }

            $thirdParty->forceFill([
                'status' => 'blacklisted',
                'blacklisted_at' => now(),
                'blacklist_reason' => sprintf(
                    'Confirmed sanctions match: %s on %s.',
                    $match->matched_name,
                    $match->list_name,
                ),
            ])->save();

            $match->forceFill(['escalated' => true])->save();
        });

        // The SU override's input. Written as a signal so the residual
        // calculator reads it the same way it reads every other signal, rather
        // than the scoring service growing a special case for sanctions.
        $this->recordSignal($match, $thirdParty, $engagements->first());

        foreach ($engagements as $engagement) {
            EngagementScoreInvalidated::dispatch($engagement, EngagementScoreInvalidated::MONITORING_SIGNAL);
        }

        $this->openStrTask($match, $thirdParty, $engagements->first(), $actor);
        $this->notifyAml($match, $thirdParty, $engagements->count());

        return $engagements->count();
    }

    /**
     * The signal that forces RR to 100.
     */
    private function recordSignal(ScreeningMatch $match, ThirdParty $thirdParty, ?Engagement $engagement): void
    {
        $key = MonitoringSignal::keyFor(
            SignalType::SanctionsMatch->value,
            $thirdParty->getKey(),
            null,
            'match'.$match->getKey(),
        );

        if (MonitoringSignal::query()->where('dedupe_key', $key)->exists()) {
            return;
        }

        MonitoringSignal::create([
            'organization_id' => $thirdParty->organization_id,
            'third_party_id' => $thirdParty->getKey(),
            'engagement_id' => null,
            'signal_type' => SignalType::SanctionsMatch->value,
            'severity' => SignalSeverity::Critical->value,
            'title' => sprintf('Confirmed sanctions match: %s on %s', $match->matched_name, $match->list_name),
            'payload' => [
                'match_id' => $match->getKey(),
                'list_name' => $match->list_name,
                'matched_name' => $match->matched_name,
                'decided_by' => $match->decided_by,
                'rationale' => $match->rationale,
            ],
            'observed_at' => now(),
            'ingested_at' => now(),
            'dedupe_key' => $key,
        ]);

        unset($engagement);
    }

    /**
     * The 24-hour STR task — CBN AML/CFT Reg. 38.
     *
     * Raised as a finding rather than a generic task, so it inherits an owner,
     * a target date, an escalation ladder and a place on the board a
     * supervisor already reads. A separate task list would be one more thing
     * to remember to look at.
     */
    private function openStrTask(ScreeningMatch $match, ThirdParty $thirdParty, ?Engagement $engagement, ?User $actor): void
    {
        if ($engagement === null) {
            return;
        }

        $finding = $this->findings->raise(
            $engagement,
            'monitoring',
            FindingSeverity::Critical,
            'File a suspicious transaction report — confirmed sanctions match',
            [
                'source_id' => $match->getKey(),
                'description' => sprintf(
                    "A screening match against %s was confirmed as a true match on %s.\n\n"
                    .'CBN AML/CFT Regulations 2022 Reg. 38 requires a suspicious transaction report within '
                    .'24 hours. Every engagement with this third party has been suspended and its residual '
                    ."score forced to the maximum.\n\nRationale recorded by the decider: %s",
                    $match->matched_name,
                    $match->list_name,
                    $match->rationale ?: 'none recorded',
                ),
                'regulatory_citation' => 'CBN AML/CFT Regulations 2022, Reg. 38',
                'identified_at' => now(),
            ],
            $actor?->getKey(),
        );

        // The SLA is 24 HOURS, not the tier policy's Critical default. This is
        // the one place in the module where a finding's target date is set by
        // a regulation rather than by a policy, and the override is explicit
        // rather than hidden in a config lookup.
        $finding->forceFill([
            'sla_days' => 1,
            'target_date' => now()->addDay()->toDateString(),
        ])->save();

        $match->forceFill(['str_task_id' => $finding->getKey()])->save();
    }

    /**
     * Tell the AML function, not only the person who clicked.
     */
    private function notifyAml(ScreeningMatch $match, ThirdParty $thirdParty, int $suspended): void
    {
        $recipients = User::query()
            ->where('organization_id', $thirdParty->organization_id)
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $user) => $user->can('tprm.screening.decide'))
            ->pluck('id')
            ->all();

        if ($recipients === []) {
            logger()->warning('TPRM sanctions escalation found nobody to notify', [
                'third_party_id' => $thirdParty->getKey(),
                'permission' => 'tprm.screening.decide',
            ]);

            return;
        }

        foreach ($recipients as $userId) {
            try {
                NotificationService::send(
                    organizationId: (int) $thirdParty->organization_id,
                    userId: $userId,
                    type: 'tprm.sanctions.true_match',
                    subject: 'Confirmed sanctions match: '.$thirdParty->legal_name,
                    body: sprintf(
                        'A screening match against %s (%s) has been confirmed. %d engagement(s) with this '
                        .'third party have been suspended and its residual risk forced to the maximum. A '
                        .'suspicious transaction report is due within 24 hours under CBN AML/CFT Reg. 38.',
                        $match->matched_name,
                        $match->list_name,
                        $suspended,
                    ),
                    metadata: [
                        'third_party_id' => $thirdParty->getKey(),
                        'match_id' => $match->getKey(),
                        'str_finding_id' => $match->str_task_id,
                    ],
                    actionUrl: route('tprm.third-parties.show', $thirdParty, absolute: false),
                    priority: 'high',
                    category: 'compliance',
                );
            } catch (\Throwable $exception) {
                logger()->error('TPRM sanctions notification failed', [
                    'user_id' => $userId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }
}
