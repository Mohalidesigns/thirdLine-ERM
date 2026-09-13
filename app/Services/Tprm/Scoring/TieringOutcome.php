<?php

namespace App\Services\Tprm\Scoring;

use App\Enums\Tprm\RiskTier;

/**
 * What a tiering run produced, assembled for the screen and for the stored
 * explanation.
 *
 * `explanation()` is the payload the "Why this score" panel renders and the
 * one written onto the score run — the same structure in both places, so what
 * a user sees now is what a reviewer sees six months later.
 */
class TieringOutcome
{
    public function __construct(
        public readonly InherentRiskResult $inherent,
        public readonly KnockoutResult $knockouts,
        public readonly ?RiskTier $overrideFloor,
        public readonly RiskTier $effectiveTier,
        public readonly string $rulesetVersion,
    ) {}

    /**
     * Whether the knockouts, rather than the weighted score, decided the tier.
     * The intake preview says so in words, because "you scored 31 and you are
     * Critical" needs an explanation on the same screen.
     */
    public function tierRaisedByKnockout(): bool
    {
        return $this->knockouts->floor !== null
            && $this->knockouts->floor->rank() > $this->inherent->tier->rank();
    }

    public function tierRaisedByOverride(): bool
    {
        return $this->overrideFloor !== null
            && $this->overrideFloor->rank() > $this->inherent->tier->max($this->knockouts->floor)->rank();
    }

    /** @return array<string, mixed> */
    public function explanation(): array
    {
        return [
            'engine_version' => config('tprm.engine_version'),
            'ruleset_version' => $this->rulesetVersion,
            'inherent' => $this->inherent->toArray(),
            'tier_from_score' => $this->inherent->tier->value,
            'knockouts_fired' => $this->knockouts->toArray(),
            'knockout_floor' => $this->knockouts->floor?->value,
            'override_floor' => $this->overrideFloor?->value,
            'effective_tier' => $this->effectiveTier->value,
            'decided_by' => match (true) {
                $this->tierRaisedByOverride() => 'manual_override',
                $this->tierRaisedByKnockout() => 'knockout',
                default => 'weighted_score',
            },
            'unresolved_facts' => $this->knockouts->unresolvedFacts,
        ];
    }
}
