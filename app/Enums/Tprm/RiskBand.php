<?php

namespace App\Enums\Tprm;

use App\Enums\Tprm\Concerns\EnumHelpers;

/**
 * The band a RESIDUAL score falls in — TRD §7.5, Low 0–24, Moderate 25–49,
 * High 50–74, Critical 75–100.
 *
 * Deliberately a separate enum from RiskTier even though the four names match.
 * A tier is what we decided the engagement is; a band is what the score came
 * out at. They routinely disagree — a Critical-tier engagement with strong
 * evidence bands Moderate, which is the whole point of TRD §7.5's worked
 * example — and one enum used for both invites code that compares them as if
 * a disagreement were an error.
 */
enum RiskBand: string
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

    public function color(): string
    {
        return match ($this) {
            self::Low => 'low',
            self::Moderate => 'medium',
            self::High => 'high',
            self::Critical => 'critical',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Low => 0,
            self::Moderate => 1,
            self::High => 2,
            self::Critical => 3,
        };
    }

    /**
     * The band a residual score falls in.
     *
     * The score is rounded to an integer FIRST. The bands are closed integer
     * intervals (24 is Low, 25 is Moderate) and a raw 24.6 belongs to whichever
     * band 25 belongs to, not to the gap between them.
     */
    public static function fromScore(float $score): self
    {
        $rounded = (int) round($score);

        /** @var array<string, array{int, int}> $bands */
        $bands = config('tprm.scoring.bands');

        foreach ($bands as $value => [$lower, $upper]) {
            if ($rounded >= $lower && $rounded <= $upper) {
                return self::from($value);
            }
        }

        return $rounded > 0 ? self::Critical : self::Low;
    }
}
