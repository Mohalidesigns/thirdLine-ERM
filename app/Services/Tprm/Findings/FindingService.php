<?php

namespace App\Services\Tprm\Findings;

use App\Enums\Tprm\FindingSeverity;
use App\Enums\Tprm\FindingStatus;
use App\Events\Tprm\EngagementScoreInvalidated;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\Finding;
use App\Models\Tprm\TierPolicy;
use App\Services\Tprm\Integration\ErmBridge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Raising, progressing and closing findings — FR-FND-01 through FR-FND-03.
 *
 * THE SLA COMES FROM THE TIER POLICY AND IS THEN FROZEN. A Medium finding
 * against a Critical vendor is not the same ninety days as a Medium against a
 * Low one, so the days are read from the engagement's tier policy — and once
 * `target_date` is written it is never recomputed. A later policy change moves
 * no historic finding's overdue status, because "we met the policy in force at
 * the time" is a claim a supervisor is entitled to test.
 *
 * RAISING IS IDEMPOTENT ON ITS SOURCE. A finding carries the source and the id
 * of whatever produced it, and re-running an assessment scoring pass, a clause
 * analysis or a SOC 2 cascade must not raise the same gap twice. Without this
 * the register fills with duplicates on the second run and the residual score
 * doubles for a reason nobody can see.
 *
 * CLOSURE REQUIRES A CLOSURE TYPE AND, FOR REMEDIATION, A VERIFIER. A finding
 * that can be closed by the person who owns it, with no evidence and no
 * verification, is a finding that gets closed. The status machine and this
 * service together are what stop that being the path of least resistance.
 */
class FindingService
{
    /**
     * Raise a finding, or return the one already raised for this source.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function raise(
        Engagement $engagement,
        string $source,
        FindingSeverity $severity,
        string $title,
        array $attributes = [],
        ?int $userId = null,
    ): Finding {
        $sourceId = $attributes['source_id'] ?? null;

        $existing = $this->existingFor($engagement, $source, $sourceId, $title);

        if ($existing !== null) {
            return $existing;
        }

        $finding = DB::transaction(function () use ($engagement, $source, $severity, $title, $attributes, $userId) {
            $slaDays = $this->slaDaysFor($engagement, $severity);
            $identifiedAt = $attributes['identified_at'] ?? now();

            $finding = Finding::create([
                'organization_id' => $engagement->organization_id,
                'engagement_id' => $engagement->getKey(),
                'third_party_id' => $engagement->third_party_id,
                'source' => $source,
                'source_id' => $attributes['source_id'] ?? null,
                'reference' => $this->nextReference(),
                'title' => $title,
                'description' => $attributes['description'] ?? null,
                'severity' => $severity->value,
                'control_refs' => $attributes['control_refs'] ?? null,
                'regulatory_citation' => $attributes['regulatory_citation'] ?? null,
                'identified_at' => $identifiedAt,
                // The relationship owner by default: the person who can chase
                // the vendor. An unowned finding is a finding nobody works.
                'owner_id' => $attributes['owner_id'] ?? $engagement->relationship_owner_id,
                'sla_days' => $slaDays,
                'target_date' => \Illuminate\Support\Carbon::parse($identifiedAt)->addDays($slaDays)->toDateString(),
                'created_by' => $userId,
            ]);

            return $finding->refresh();
        });

        // Dispatched OUTSIDE the transaction. The listener recomputes and
        // writes a score run of its own; nesting that inside the finding's
        // write would hold a transaction open across a second set of queries,
        // and would roll the finding back if the scoring threw.
        EngagementScoreInvalidated::dispatch($engagement, EngagementScoreInvalidated::FINDING_RAISED);

        // TRD §15: the finding appears in the ERM issue register too. Failing
        // to mirror must not fail the raise — a bank unable to record a
        // third-party finding because its issue register rejected something is
        // the worse outage by a distance.
        try {
            app(ErmBridge::class)->mirrorFinding($finding);
        } catch (\Throwable $exception) {
            logger()->error('TPRM finding could not be mirrored to the ERM issue register', [
                'finding_id' => $finding->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }

        return $finding->refresh();
    }

    /**
     * Move a finding through its lifecycle.
     *
     * Refuses a transition the machine does not allow rather than throwing:
     * the caller is usually a screen, and "you cannot go straight from open to
     * closed" is a message a user acts on, not an error.
     *
     * @return array{moved: bool, reason: string|null}
     */
    public function transition(Finding $finding, FindingStatus $target, ?int $userId = null): array
    {
        if (! $finding->status->canTransitionTo($target)) {
            return [
                'moved' => false,
                'reason' => sprintf(
                    'A finding that is %s cannot move straight to %s.',
                    strtolower($finding->status->label()),
                    strtolower($target->label()),
                ),
            ];
        }

        $finding->forceFill(['status' => $target->value, 'updated_by' => $userId])->save();

        // Moving into remediation with a plan earns the ×0.5 discount, and
        // moving out of it loses it — both change FU, so both recompute.
        if ($finding->engagement !== null) {
            EngagementScoreInvalidated::dispatch(
                $finding->engagement,
                EngagementScoreInvalidated::FINDING_RAISED,
            );
        }

        return ['moved' => true, 'reason' => null];
    }

    /**
     * Record the remediation plan the vendor has agreed.
     *
     * This is what earns the ×0.5 discount in TRD §7.5, and it is why the plan
     * is required to move into remediation: the discount is for a finding
     * somebody has decided how to fix, not for one whose clock has simply not
     * run out.
     */
    public function recordPlan(Finding $finding, string $plan, ?string $vendorResponse = null, ?int $userId = null): Finding
    {
        $finding->forceFill([
            'remediation_plan' => $plan,
            'vendor_response' => $vendorResponse ?? $finding->vendor_response,
            'updated_by' => $userId,
        ])->save();

        // Walks the machine rather than jumping. `Open` cannot reach
        // `InRemediation` in one step — a finding has to be assigned to
        // somebody before it can be worked — and the caller recording a plan
        // should not have to know that.
        if ($finding->status === FindingStatus::Open) {
            $this->transition($finding, FindingStatus::Assigned, $userId);
            $finding->refresh();
        }

        if ($finding->status === FindingStatus::Assigned) {
            $this->transition($finding, FindingStatus::InRemediation, $userId);
        }

        return $finding->refresh();
    }

    /**
     * Submit the vendor's evidence and put the finding in front of a verifier.
     *
     * The two steps are one call because they are one act from the outside: a
     * vendor attaches its evidence, and the finding is now somebody's to
     * check. Keeping them as separate statuses matters for the board — "eleven
     * findings awaiting our verification" is a different number from "eleven
     * awaiting the vendor" — but nothing outside this service needs to walk
     * them one at a time.
     *
     * @return array{moved: bool, reason: string|null}
     */
    public function submitForVerification(Finding $finding, ?int $documentId = null, ?int $userId = null): array
    {
        if ($documentId !== null) {
            $finding->forceFill(['evidence_document_id' => $documentId])->save();
        }

        if ($finding->status === FindingStatus::InRemediation) {
            $moved = $this->transition($finding, FindingStatus::EvidenceSubmitted, $userId);

            if (! $moved['moved']) {
                return $moved;
            }

            $finding->refresh();
        }

        if ($finding->status === FindingStatus::EvidenceSubmitted) {
            return $this->transition($finding, FindingStatus::UnderVerification, $userId);
        }

        return [
            'moved' => false,
            'reason' => sprintf(
                'A finding that is %s is not ready for verification. Record the remediation plan first.',
                strtolower($finding->status->label()),
            ),
        ];
    }

    /**
     * Close a finding as remediated.
     *
     * REQUIRES A VERIFIER OTHER THAN NOBODY, and evidence where the severity
     * warrants it. A Critical or High finding closed on an assertion is the
     * commonest way a remediation register becomes fiction — the work was
     * described, not checked.
     *
     * @return array{closed: bool, reason: string|null}
     */
    public function close(Finding $finding, string $closureType, ?int $verifierId, ?int $documentId = null, ?int $userId = null): array
    {
        $target = match ($closureType) {
            'remediated' => FindingStatus::ClosedRemediated,
            'risk_accepted' => FindingStatus::ClosedRiskAccepted,
            'false_positive' => FindingStatus::ClosedFalsePositive,
            default => null,
        };

        if ($target === null) {
            return ['closed' => false, 'reason' => 'That is not a closure type this register recognises.'];
        }

        if ($closureType === 'remediated') {
            // The machine refuses a jump from open to closed, and the message
            // has to say what to do instead rather than only what is wrong.
            if (! $finding->status->canTransitionTo(FindingStatus::ClosedRemediated)) {
                return [
                    'closed' => false,
                    'reason' => sprintf(
                        'A finding that is %s cannot be closed as remediated. It has to reach verification '
                        .'first: record the remediation plan, attach the evidence, and then close it. That '
                        .'sequence is what stops a finding being closed by the person who owns it with '
                        .'nothing behind it.',
                        strtolower($finding->status->label()),
                    ),
                ];
            }

            if ($verifierId === null) {
                return [
                    'closed' => false,
                    'reason' => 'A remediated finding has to be verified by somebody. Closing it on the '
                        .'vendor\'s assurance alone is how a remediation register becomes fiction — the work '
                        .'was described, not checked.',
                ];
            }

            if (in_array($finding->severity, [FindingSeverity::Critical, FindingSeverity::High], true)
                && $documentId === null && $finding->evidence_document_id === null) {
                return [
                    'closed' => false,
                    'reason' => 'A '.strtolower($finding->severity->label()).' finding needs evidence of the '
                        .'remediation attached before it can be closed.',
                ];
            }
        }

        $moved = $this->transition($finding, $target, $userId);

        if (! $moved['moved']) {
            return ['closed' => false, 'reason' => $moved['reason']];
        }

        $finding->forceFill([
            'closure_type' => $closureType,
            'verified_by' => $verifierId,
            'verified_at' => $verifierId === null ? null : now(),
            'evidence_document_id' => $documentId ?? $finding->evidence_document_id,
        ])->save();

        if ($finding->engagement !== null) {
            EngagementScoreInvalidated::dispatch($finding->engagement, EngagementScoreInvalidated::FINDING_CLOSED);
        }

        try {
            app(ErmBridge::class)->syncClosure($finding->refresh());
        } catch (\Throwable $exception) {
            logger()->error('TPRM finding closure could not be synced to the ERM issue register', [
                'finding_id' => $finding->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }

        return ['closed' => true, 'reason' => null];
    }

    /**
     * Raise the escalation level on an overdue finding.
     *
     * The ladder is a level per elapsed SLA period, capped: level 1 at the
     * target date, level 2 at twice the SLA, level 3 beyond. A ladder that
     * climbed on a fixed schedule would escalate a 180-day Low finding at the
     * same pace as a 30-day Critical.
     */
    public function escalationLevelFor(Finding $finding): int
    {
        if (! $finding->isOpen() || $finding->sla_days === null || $finding->identified_at === null) {
            return 0;
        }

        $elapsed = (int) $finding->identified_at->copy()->startOfDay()->diffInDays(now()->startOfDay());
        $periods = (int) floor($elapsed / max(1, $finding->sla_days));

        return max(0, min(3, $periods));
    }

    /**
     * The remediation SLA in days for this severity on this engagement.
     *
     * The tier policy first, config as the fallback for an engagement with no
     * policy row. The fallback is the default, not the rule.
     */
    public function slaDaysFor(Engagement $engagement, FindingSeverity $severity): int
    {
        $tier = $engagement->effectiveTier();

        if ($tier !== null) {
            $policy = TierPolicy::query()->where('tier', $tier->value)->first();
            $sla = (array) ($policy->remediation_sla ?? []);

            if (isset($sla[$severity->value]) && (int) $sla[$severity->value] > 0) {
                return (int) $sla[$severity->value];
            }
        }

        /** @var array<string, int> $defaults */
        $defaults = config('tprm.defaults.remediation_sla_days');

        return (int) ($defaults[$severity->value] ?? 90);
    }

    /**
     * An existing finding for the same source, so a re-run does not duplicate.
     *
     * Matched on source AND source id where there is one, falling back to the
     * title — a clause gap and a SOC 2 exception both carry an id, while a
     * non-compliant answer's "source id" is the response, which changes each
     * cycle. Matching on title there is what stops the same control gap
     * appearing four years running as four separate findings.
     */
    private function existingFor(Engagement $engagement, string $source, ?int $sourceId, string $title): ?Finding
    {
        return Finding::query()
            ->where('engagement_id', $engagement->getKey())
            ->where('source', $source)
            ->when(
                $sourceId !== null,
                fn ($query) => $query->where('source_id', $sourceId),
                fn ($query) => $query->where('title', $title),
            )
            ->scoring()
            ->first();
    }

    /**
     * `TPF-{year}-{seq}`, sequential within the tenant and the year.
     *
     * From the highest existing reference rather than a count, for the reason
     * every other reference in this module gives: a reference that has
     * appeared on a board paper cannot be reissued against a different gap.
     */
    private function nextReference(): string
    {
        $year = now()->year;
        $prefix = "TPF-{$year}-";

        $highest = Finding::withTrashed()
            ->where('reference', 'like', $prefix.'%')
            ->orderByDesc('reference')
            ->value('reference');

        $next = $highest === null ? 1 : ((int) Str::afterLast($highest, '-')) + 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
