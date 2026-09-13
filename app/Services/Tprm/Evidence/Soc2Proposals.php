<?php

namespace App\Services\Tprm\Evidence;

/**
 * The four proposal sets one SOC 2 produces — AC-04.
 *
 * Nothing here has been written to the register. These are what a human is
 * shown for the second confirmation.
 */
class Soc2Proposals
{
    /**
     * @param  list<array<string, mixed>>  $answers
     * @param  list<array<string, mixed>>  $findings
     * @param  list<array<string, mixed>>  $obligations
     * @param  list<array<string, mixed>>  $nthPartyEdges
     */
    public function __construct(
        public readonly array $answers,
        public readonly array $findings,
        public readonly array $obligations,
        public readonly array $nthPartyEdges,
        public readonly bool $bridgeLetterCap = false,
    ) {}

    public function isEmpty(): bool
    {
        return $this->answers === []
            && $this->findings === []
            && $this->obligations === []
            && $this->nthPartyEdges === [];
    }

    public function count(): int
    {
        return count($this->answers)
            + count($this->findings)
            + count($this->obligations)
            + count($this->nthPartyEdges);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'answers' => $this->answers,
            'findings' => $this->findings,
            'obligations' => $this->obligations,
            'nth_party_edges' => $this->nthPartyEdges,
            'bridge_letter_cap' => $this->bridgeLetterCap,
            'total' => $this->count(),
        ];
    }
}
