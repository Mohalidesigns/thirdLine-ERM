<?php

namespace App\Enums\Tprm;

use App\Enums\Tprm\Concerns\EnumHelpers;

/**
 * How an answer is evidenced — the differentiator in TRD §7.4.
 *
 * Two vendors giving identical answers score differently because these
 * coefficients differ: 0.35 for "the vendor said so" against 0.85 for a SOC 2
 * that covers the period and the control. AC-03 requires that a 23.8-point
 * spread between two otherwise identical engagements be attributable entirely
 * to this enum, and that the explanation panel say so.
 *
 * The coefficients live in config, not here: TRD §7.9 requires a score to
 * carry the engine version that produced it, and a constant compiled into an
 * enum cannot be version-stamped.
 */
enum AssuranceLevel: string
{
    use EnumHelpers;

    case SelfAttested = 'self_attested';
    case Documented = 'documented';
    case IndependentlyAssured = 'independently_assured';
    case Validated = 'validated';

    public function label(): string
    {
        return match ($this) {
            self::SelfAttested => 'Self-attested',
            self::Documented => 'Documented',
            self::IndependentlyAssured => 'Independently assured',
            self::Validated => 'Validated',
        };
    }

    /**
     * What the level actually means, shown beside the chip. This text is what
     * stops a reviewer marking a policy PDF as independently assured.
     */
    public function definition(): string
    {
        return match ($this) {
            self::SelfAttested => 'The vendor said so. Nothing attached.',
            self::Documented => 'A policy, procedure or standard is attached and covers the control.',
            self::IndependentlyAssured => 'A SOC 2 Type II covering the period and the control, an ISO certificate whose scope includes the service, an external audit, or a current PCI AOC.',
            self::Validated => 'We inspected it — on-site visit, technical test, live demonstration, or an analyst review.',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::SelfAttested => 'critical',
            self::Documented => 'high',
            self::IndependentlyAssured => 'medium',
            self::Validated => 'low',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::SelfAttested => 0,
            self::Documented => 1,
            self::IndependentlyAssured => 2,
            self::Validated => 3,
        };
    }

    /** The conf_q coefficient for this level (TRD §7.4). */
    public function confidence(): float
    {
        /** @var array<string, float> $coefficients */
        $coefficients = config('tprm.scoring.confidence');

        return (float) $coefficients[$this->value];
    }

    /**
     * Cap this level at another — the bridge-letter rule (AC-05): a control
     * evidenced only by a bridge letter for the gap period cannot exceed
     * `documented`, so it scores 0.60 and not 0.85.
     */
    public function cappedAt(self $ceiling): self
    {
        return $this->rank() > $ceiling->rank() ? $ceiling : $this;
    }
}
