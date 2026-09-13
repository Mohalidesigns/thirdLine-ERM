<?php

namespace App\Services\Tprm;

use App\Enums\Tprm\RiskTier;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\Waiver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Manual tier overrides — FR-TIER-04.
 *
 * AN OVERRIDE MAY ONLY RAISE A TIER. TRD §7.3 defines the final tier as
 * `max(tier_from_score, knockout_floor, override_floor)`, so an override set
 * below the computed tier has no arithmetic effect at all — and a form that
 * accepts one silently is the "screen that saves nothing" defect this codebase
 * has found repeatedly. `override()` refuses it with the reason instead.
 *
 * That is not a limitation, it is the semantics: the risk function may decide
 * a vendor deserves MORE scrutiny than the model gives it. Deciding it deserves
 * less means the model is wrong, and the answer to a wrong model is a ruleset
 * change with a sandbox run behind it, not a per-vendor exception nobody else
 * can see.
 *
 * EVERY OVERRIDE EXPIRES. The expiry is mandatory in the Form Request and the
 * scoring service ignores a lapsed one, so a tier raised in March is back to
 * the computed value when the override runs out rather than persisting because
 * whoever set it has since left. Each one is written to `tp_waivers`, so the
 * override register is the same report as every other exception in the module.
 */
class TierOverrideService
{
    /**
     * @return array{applied: bool, reason: string|null}
     */
    public function override(
        Engagement $engagement,
        RiskTier $tier,
        string $rationale,
        Carbon $expiresAt,
        int $approverId,
        ?string $approverRole = null,
    ): array {
        $computed = $engagement->inherent_tier;

        if ($computed !== null && ! $tier->atLeast($computed)) {
            return [
                'applied' => false,
                'reason' => "The computed tier is {$computed->label()}. An override may raise a tier but never "
                    ."lower it (TRD §7.3), so setting {$tier->label()} would have no effect. If the model is "
                    .'giving this engagement the wrong tier, change the ruleset — the sandbox will show what that '
                    .'does to the rest of the portfolio first.',
            ];
        }

        if ($expiresAt->isPast()) {
            return ['applied' => false, 'reason' => 'The expiry date must be in the future.'];
        }

        DB::transaction(function () use ($engagement, $tier, $rationale, $expiresAt, $approverId, $approverRole) {
            $before = [
                'tier_override' => $engagement->tier_override?->value,
                'effective_tier' => $engagement->effective_tier?->value,
            ];

            $engagement->forceFill([
                'tier_override' => $tier->value,
                'tier_override_reason' => $rationale,
                'tier_override_approver_id' => $approverId,
                'tier_override_expires_at' => $expiresAt->toDateString(),
                // The stored effective tier moves with it, so the register and
                // every dashboard reflect the override immediately rather than
                // waiting for the next scheduled recomputation.
                'effective_tier' => $tier->max($engagement->inherent_tier)->value,
                'updated_by' => $approverId,
            ])->save();

            Waiver::create([
                'organization_id' => $engagement->organization_id,
                'waivable_type' => Waiver::TYPE_TIER_OVERRIDE,
                'waivable_id' => $engagement->getKey(),
                'engagement_id' => $engagement->getKey(),
                'rationale' => $rationale,
                'requested_by' => $approverId,
                'requested_at' => now(),
                'approver_id' => $approverId,
                'approver_role' => $approverRole,
                'approved_at' => now(),
                'expires_at' => $expiresAt->toDateString(),
                'status' => Waiver::STATUS_APPROVED,
            ]);

            $engagement->writeAuditRow('tier_overridden', $before, [
                'tier_override' => $tier->value,
                'rationale' => $rationale,
                'expires_at' => $expiresAt->toDateString(),
                'approver_id' => $approverId,
            ]);
        });

        return ['applied' => true, 'reason' => null];
    }

    /**
     * Remove an override and restore the computed tier.
     */
    public function clear(Engagement $engagement, int $userId, string $reason = 'Withdrawn'): void
    {
        DB::transaction(function () use ($engagement, $userId, $reason) {
            $before = ['tier_override' => $engagement->tier_override?->value];

            $engagement->forceFill([
                'tier_override' => null,
                'tier_override_reason' => null,
                'tier_override_approver_id' => null,
                'tier_override_expires_at' => null,
                'effective_tier' => $engagement->inherent_tier?->value,
                'updated_by' => $userId,
            ])->save();

            Waiver::query()
                ->where('waivable_type', Waiver::TYPE_TIER_OVERRIDE)
                ->where('waivable_id', $engagement->getKey())
                ->where('status', Waiver::STATUS_APPROVED)
                ->update([
                    'status' => Waiver::STATUS_REVOKED,
                    'revoked_at' => now(),
                    'revocation_reason' => $reason,
                ]);

            $engagement->writeAuditRow('tier_override_cleared', $before, ['reason' => $reason]);
        });
    }

    /**
     * Restore the computed tier on every engagement whose override has lapsed.
     *
     * Run nightly. Without it an expiry date is decoration: the scoring
     * service already ignores a lapsed override when it recomputes, but an
     * engagement that is not recomputed would keep showing the raised tier on
     * the register indefinitely.
     *
     * @return int the number restored
     */
    public function restoreExpired(): int
    {
        $expired = Engagement::query()
            ->whereNotNull('tier_override')
            ->whereNotNull('tier_override_expires_at')
            ->whereDate('tier_override_expires_at', '<', now()->toDateString())
            ->get();

        foreach ($expired as $engagement) {
            $this->clear($engagement, $engagement->updated_by ?? 0, 'Override expired');
        }

        return $expired->count();
    }
}
