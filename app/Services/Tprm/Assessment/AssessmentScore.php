<?php

namespace App\Services\Tprm\Assessment;

/**
 * The result of scoring one assessment — AC, EC and the derivation behind both.
 *
 * `criticalFailures` is separate from the rest because it is the only thing on
 * here that changes the arithmetic non-linearly: a critical question answered
 * non-compliant caps AC at 0.5 no matter how well everything else scored
 * (TRD §7.4). A reader looking at an assessment where 94% of the weight is
 * compliant and AC reads 0.5 needs that list on the same screen.
 */
class AssessmentScore
{
    /**
     * @param  list<AnswerScore>  $answers
     * @param  array<string, array{ac: float, ec: float|null, weight: float}>  $sections
     * @param  array<string, array{ac: float, ec: float|null, weight: float}>  $domains
     * @param  list<string>  $criticalFailures
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly float $assuranceCoverage,
        public readonly ?float $evidenceConfidence,
        public readonly array $answers,
        public readonly array $sections,
        public readonly array $domains,
        public readonly array $criticalFailures = [],
        public readonly bool $coverageCapped = false,
        public readonly int $applicableCount = 0,
        public readonly int $scoredCount = 0,
        public readonly array $warnings = [],
    ) {}

    /**
     * Mitigation — `M = AC × EC × Kmax` (TRD §7.5).
     *
     * Null when EC is null, which happens when nothing scored above zero
     * compliance: there is no evidence confidence to speak of because there is
     * nothing evidenced. Returning 0 would say the opposite of what is true —
     * that we measured the confidence and it was none — and it would then flow
     * into a residual score as though it had been measured.
     */
    public function mitigation(?float $kmax = null): ?float
    {
        if ($this->evidenceConfidence === null) {
            return null;
        }

        $kmax ??= (float) config('tprm.scoring.kmax');

        return $this->assuranceCoverage * $this->evidenceConfidence * $kmax;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ac' => round($this->assuranceCoverage, 4),
            'ec' => $this->evidenceConfidence === null ? null : round($this->evidenceConfidence, 4),
            'm' => $this->mitigation() === null ? null : round((float) $this->mitigation(), 4),
            'applicable_count' => $this->applicableCount,
            'scored_count' => $this->scoredCount,
            'coverage_capped' => $this->coverageCapped,
            'critical_failures' => $this->criticalFailures,
            'sections' => $this->sections,
            'domains' => $this->domains,
            'answers' => array_map(fn (AnswerScore $a) => $a->toArray(), $this->answers),
            'warnings' => $this->warnings,
        ];
    }
}
