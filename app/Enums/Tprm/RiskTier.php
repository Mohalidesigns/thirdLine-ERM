<?php

namespace App\Enums\Tprm;

use App\Enums\Tprm\Concerns\EnumHelpers;

/**
 * The tier an engagement sits in — the single value that drives assessment
 * cadence, screening cadence, approval chain, clause set, exit-plan
 * requirement and board reportability, all of which are read from the
 * matching `tp_tier_policies` row.
 *
 * Tier is ORDERED, and the order is load-bearing: TRD §7.3 says the final tier
 * is `max(tier_from_score, highest_knockout_floor, manual_override_floor)`.
 * `atLeast()` and `max()` are that max, declared once, because a comparison
 * written as `$tier === 'critical' || $tier === 'high'` at each call site is
 * how a new tier silently stops being covered.
 */
enum RiskTier: string
{
    use EnumHelpers;

    case Low = 'low';
    case Moderate = 'moderate';
    case High = 'high';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low',
            self::Moderate => 'Moderate',
            self::High => 'High',
            self::Critical => 'Critical',
        };
    }

    /** Maps onto the RatingBadge vocabulary in @thirdline/ui. */
    public function color(): string
    {
        return match ($this) {
            self::Low => 'low',
            self::Moderate => 'medium',
            self::High => 'high',
            self::Critical => 'critical',
        };
    }

    /** 0 for Low through 3 for Critical. */
    public function rank(): int
    {
        return match ($this) {
            self::Low => 0,
            self::Moderate => 1,
            self::High => 2,
            self::Critical => 3,
        };
    }

    public function atLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }

    /**
     * The higher of two tiers. A knockout floor combines with a computed tier
     * through this and nowhere else.
     */
    public function max(?self $other): self
    {
        if ($other === null) {
            return $this;
        }

        return $other->rank() > $this->rank() ? $other : $this;
    }

    /**
     * The tier a weighted inherent score falls in, before knockouts.
     *
     * Edges come from config so the band table has one definition shared with
     * the residual bands and the ruleset editor.
     */
    public static function fromInherentScore(float $score): self
    {
        /** @var array<string, array{int, int}> $bands */
        $bands = config('tprm.scoring.inherent_tiers');

        foreach ($bands as $value => [$lower, $upper]) {
            if ($score >= $lower && $score <= $upper) {
                return self::from($value);
            }
        }

        // Above the top edge is Critical; below the bottom is Low. Neither is
        // reachable from a 0–100 score, but a mis-edited band table must not
        // return null into a NOT NULL column.
        return $score > 0 ? self::Critical : self::Low;
    }
}
