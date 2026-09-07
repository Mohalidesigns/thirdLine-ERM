<?php

namespace App\Services\Tprm\Assessment;

use App\Enums\Tprm\AssuranceLevel;

/**
 * What one answer contributed, and the arithmetic that got it there.
 *
 * `modifiers` lists every adjustment by name with the factor it applied, so a
 * vendor asking why an answer it believes is fully evidenced scored 0.30 can
 * be shown "independently assured 0.85, evidence expired ×0.5, scope mismatch
 * ×0.7" rather than a bare number. That conversation is the difference between
 * a score a vendor argues with and one it acts on.
 */
class AnswerScore
{
    /**
     * @param  list<array{name: string, factor: float|string, note: string}>  $modifiers
     */
    public function __construct(
        public readonly string $questionCode,
        public readonly float $weight,
        public readonly ?float $complianceWeight,
        public readonly ?AssuranceLevel $effectiveLevel,
        public readonly float $baseConfidence,
        public readonly float $confidence,
        public readonly array $modifiers = [],
        public readonly bool $excluded = false,
        public readonly ?string $exclusionReason = null,
    ) {}

    /** The answer's contribution to the AC numerator. */
    public function coverageContribution(): float
    {
        return $this->excluded ? 0.0 : $this->weight * (float) $this->complianceWeight;
    }

    /** The answer's contribution to the EC numerator. */
    public function confidenceContribution(): float
    {
        return $this->coverageContribution() * $this->confidence;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'question' => $this->questionCode,
            'weight' => $this->weight,
            'compliance' => $this->complianceWeight,
            'assurance_level' => $this->effectiveLevel?->value,
            'base_confidence' => $this->baseConfidence,
            'confidence' => round($this->confidence, 4),
            'modifiers' => $this->modifiers,
            'excluded' => $this->excluded,
            'exclusion_reason' => $this->exclusionReason,
            'coverage_contribution' => round($this->coverageContribution(), 4),
            'confidence_contribution' => round($this->confidenceContribution(), 4),
        ];
    }
}
