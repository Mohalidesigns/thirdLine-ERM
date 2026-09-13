<?php

namespace App\Services\Tprm\Evidence;

/**
 * What the citation check found.
 *
 * `isTrustworthy()` is the gate: an extraction with ANY rejected citation is
 * not trustworthy, however many verified ones sit beside it. That is
 * deliberately unforgiving — a model that invented one field invented it in
 * the same pass that produced the rest, and "most of this is right" is not a
 * standard anybody can act on when the output becomes a regulatory answer.
 */
class CitationVerification
{
    /**
     * @param  list<array{field: string, quote: string, page: mixed}>  $verified
     * @param  list<array{field: string, quote: string, page: mixed, reason: string}>  $rejected
     */
    public function __construct(
        public readonly array $verified,
        public readonly array $rejected,
    ) {}

    public function isTrustworthy(): bool
    {
        return $this->rejected === [];
    }

    /** @return list<string> the fields whose citation failed */
    public function rejectedFields(): array
    {
        return array_values(array_unique(array_column($this->rejected, 'field')));
    }

    /**
     * The confidence an extraction keeps after verification.
     *
     * A failure does not merely reduce confidence, it collapses it to the
     * low-confidence band, because the failure is categorical: this output
     * contains at least one claim the document does not support. The number is
     * capped rather than scaled so that a high-confidence fabrication cannot
     * arrive above the human-confirmation threshold.
     */
    public function adjustedConfidence(float $reported, float $lowConfidenceCap = 0.3): float
    {
        return $this->isTrustworthy() ? $reported : min($reported, $lowConfidenceCap);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'trustworthy' => $this->isTrustworthy(),
            'verified' => $this->verified,
            'rejected' => $this->rejected,
            'verified_count' => count($this->verified),
            'rejected_count' => count($this->rejected),
        ];
    }
}
