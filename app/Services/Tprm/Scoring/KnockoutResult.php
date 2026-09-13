<?php

namespace App\Services\Tprm\Scoring;

use App\Enums\Tprm\RiskTier;

/**
 * The outcome of evaluating every knockout rule against one engagement.
 */
class KnockoutResult
{
    /**
     * @param  list<FiredKnockout>  $fired
     * @param  list<string>  $unresolvedFacts
     */
    public function __construct(
        public readonly array $fired,
        public readonly ?RiskTier $floor,
        public readonly array $unresolvedFacts = [],
    ) {}

    public function suspendsEngagements(): bool
    {
        foreach ($this->fired as $knockout) {
            if ($knockout->suspends) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array<string, mixed>> */
    public function toArray(): array
    {
        return array_map(fn (FiredKnockout $k) => $k->toArray(), $this->fired);
    }
}
