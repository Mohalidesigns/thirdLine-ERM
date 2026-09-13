<?php

namespace App\Services\Tprm\Findings;

use App\Enums\Tprm\FindingStatus;
use App\Events\Tprm\EngagementScoreInvalidated;
use App\Models\Tprm\Finding;
use App\Models\Tprm\RiskAcceptance;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Accepting a risk rather than remediating it — FR-FND-04.
 *
 * FOUR THINGS ARE REQUIRED AND EACH REMOVES A WAY THIS GOES WRONG.
 *
 *   A JUSTIFICATION, because an acceptance with no stated reason cannot be
 *   reviewed by the committee it is reported to, and cannot be revisited by
 *   whoever inherits it.
 *
 *   AN EXPIRY, because an acceptance without one is a deletion with a paper
 *   trail. Nobody revisits it, the finding leaves the board pack, and two
 *   years later the institution is carrying a risk no living person decided
 *   to keep. The nightly job reopens the finding when the date passes.
 *
 *   AN APPROVER WITH THE RIGHT AUTHORITY. A Critical acceptance needs
 *   `tprm.finding.accept_risk`, which sits with the risk function. A register
 *   in which any analyst can accept a Critical finding is a register with no
 *   Critical findings in it.
 *
 *   A MAXIMUM PERIOD BY SEVERITY. A Critical risk accepted for three years is
 *   not a decision, it is a decision avoided.
 *
 * THE FINDING STILL COUNTS IN THE SCORE, at half. Accepting a risk decides
 * whether to remediate it, not whether it exists — and a score that dropped to
 * zero on acceptance would make acceptance the cheapest way to improve a
 * rating.
 */
class RiskAcceptanceService
{
    /**
     * Record and approve an acceptance.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{accepted: bool, reason: string|null, acceptance: RiskAcceptance|null}
     */
    public function accept(Finding $finding, User $approver, array $attributes): array
    {
        $required = RiskAcceptance::requiredPermissionFor($finding->severity);

        if (! $approver->can($required)) {
            return [
                'accepted' => false,
                'reason' => sprintf(
                    'Accepting a %s finding requires the "%s" permission, which sits with the risk function. '
                    .'This is deliberate: a register in which anybody can accept a %s finding is a register '
                    .'with none in it.',
                    strtolower($finding->severity->label()),
                    $required,
                    strtolower($finding->severity->label()),
                ),
                'acceptance' => null,
            ];
        }

        $expiresAt = Carbon::parse($attributes['expires_at']);
        $maximum = RiskAcceptance::maximumMonthsFor($finding->severity);

        if ($expiresAt->isAfter(now()->addMonths($maximum))) {
            return [
                'accepted' => false,
                'reason' => sprintf(
                    'A %s finding may be accepted for at most %d months. A risk accepted for longer than that '
                    .'is not a decision, it is a decision avoided — somebody has to look at it again and say '
                    .'the same thing out loud.',
                    strtolower($finding->severity->label()),
                    $maximum,
                ),
                'acceptance' => null,
            ];
        }

        if ($expiresAt->isBefore(now()->addDay())) {
            return [
                'accepted' => false,
                'reason' => 'The acceptance has to run at least until tomorrow.',
                'acceptance' => null,
            ];
        }

        $acceptance = DB::transaction(function () use ($finding, $approver, $attributes, $expiresAt) {
            $acceptance = RiskAcceptance::create([
                'organization_id' => $finding->organization_id,
                'finding_id' => $finding->getKey(),
                'justification' => $attributes['justification'],
                'compensating_controls' => $attributes['compensating_controls'] ?? null,
                'residual_impact' => $attributes['residual_impact'] ?? null,
                'approver_id' => $approver->getKey(),
                'approver_role' => $attributes['approver_role'] ?? null,
                'approved_at' => now(),
                'expires_at' => $expiresAt->toDateString(),
                'created_by' => $approver->getKey(),
            ]);

            $acceptance->forceFill(['status' => RiskAcceptance::STATUS_APPROVED])->save();

            $finding->forceFill([
                'status' => FindingStatus::ClosedRiskAccepted->value,
                'closure_type' => 'risk_accepted',
                'risk_acceptance_id' => $acceptance->getKey(),
                'updated_by' => $approver->getKey(),
            ])->save();

            return $acceptance;
        });

        if ($finding->engagement !== null) {
            EngagementScoreInvalidated::dispatch($finding->engagement, EngagementScoreInvalidated::RISK_ACCEPTED);
        }

        return ['accepted' => true, 'reason' => null, 'acceptance' => $acceptance->refresh()];
    }

    /**
     * Reopen the findings whose acceptances have lapsed.
     *
     * The whole point of a mandatory expiry. The finding returns to `open`
     * rather than to whatever it was before, because an accepted risk that has
     * run out is a live gap somebody has to decide about again — not a
     * half-finished remediation.
     *
     * @return \Illuminate\Support\Collection<int, Finding>
     */
    public function reopenLapsed(?int $organizationId = null)
    {
        $lapsed = RiskAcceptance::query()
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->lapsed()
            ->with('finding')
            ->get();

        $reopened = collect();

        foreach ($lapsed as $acceptance) {
            $finding = $acceptance->finding;

            if ($finding === null) {
                continue;
            }

            DB::transaction(function () use ($acceptance, $finding, $reopened) {
                $acceptance->forceFill(['status' => RiskAcceptance::STATUS_EXPIRED])->save();

                if ($finding->status === FindingStatus::ClosedRiskAccepted) {
                    $finding->forceFill([
                        'status' => FindingStatus::Open->value,
                        'closure_type' => null,
                        'risk_acceptance_id' => null,
                    ])->save();

                    $finding->writeAuditRow('risk_acceptance_expired', null, [
                        'acceptance_id' => $acceptance->getKey(),
                        'expired_on' => $acceptance->expires_at?->toDateString(),
                        'note' => 'The acceptance ran out and the finding is open again. It has not been '
                            .'remediated; the decision to tolerate it simply expired.',
                    ]);

                    $reopened->push($finding);
                }
            });

            if ($finding->engagement !== null) {
                EngagementScoreInvalidated::dispatch(
                    $finding->engagement,
                    EngagementScoreInvalidated::RISK_ACCEPTANCE_EXPIRED,
                );
            }
        }

        return $reopened;
    }

    /**
     * Withdraw an acceptance before it expires.
     *
     * Used when circumstances change — the compensating control that justified
     * it has gone away, or the vendor has offered a fix after all.
     */
    public function withdraw(RiskAcceptance $acceptance, string $reason, ?int $userId = null): Finding
    {
        $finding = DB::transaction(function () use ($acceptance, $reason, $userId) {
            $acceptance->forceFill([
                'status' => RiskAcceptance::STATUS_WITHDRAWN,
                'review_notes' => $reason,
            ])->save();

            $finding = $acceptance->finding;

            $finding->forceFill([
                'status' => FindingStatus::Open->value,
                'closure_type' => null,
                'risk_acceptance_id' => null,
                'updated_by' => $userId,
            ])->save();

            return $finding->refresh();
        });

        // Withdrawing an acceptance takes the finding back to full weight in
        // the score, so it recomputes the same way accepting it did.
        if ($finding->engagement !== null) {
            EngagementScoreInvalidated::dispatch($finding->engagement, EngagementScoreInvalidated::RISK_ACCEPTED);
        }

        return $finding;
    }

    /**
     * The acceptance register — FR-FND-04's reportable artefact.
     *
     * Expiring-soonest first, because that is the column a committee acts on,
     * and lapsed ones included so the report shows what has already fallen
     * over rather than only what is about to.
     *
     * @return array<string, mixed>
     */
    public function register(?int $organizationId = null): array
    {
        $base = fn () => RiskAcceptance::query()
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId));

        return [
            'in_force' => $base()->inForce()->count(),
            'expiring_30' => $base()->expiringWithin(30)->count(),
            'lapsed' => $base()->lapsed()->count(),
            'critical_in_force' => $base()->inForce()
                ->whereIn('finding_id', Finding::query()->where('severity', 'critical')->select('id'))
                ->count(),
        ];
    }
}
