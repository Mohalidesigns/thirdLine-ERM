<?php

namespace App\Services\Tprm\Scoring;

use App\Enums\Tprm\RiskTier;

/**
 * One knockout rule that fired, with everything the screen has to show.
 *
 * The citation travels with the fired rule rather than being looked up when
 * rendering. AC-02 requires the rule name AND its citation to be displayed;
 * a rendering-time lookup against a ruleset that has since been superseded
 * would show today's citation beside a score computed under yesterday's rules.
 */
class FiredKnockout
{
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly RiskTier $floor,
        public readonly string $citation,
        public readonly bool $suspends = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'floor' => $this->floor->value,
            'citation' => $this->citation,
            'suspends' => $this->suspends,
        ];
    }
}
