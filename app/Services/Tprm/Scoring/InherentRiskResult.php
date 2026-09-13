<?php

namespace App\Services\Tprm\Scoring;

use App\Enums\Tprm\RiskTier;

/**
 * What `InherentRiskCalculator` returns: the score, and the whole arithmetic
 * that produced it.
 *
 * The derivation is not a debugging aid. TRD §7.2 requires that "every factor
 * answer records the question, the selected option and the derived score, so
 * the derivation renders as a table", and AC-15 requires two users looking at
 * the same score to see the same explanation. That is only achievable if the
 * explanation is a stored output of the calculation rather than something a
 * screen reconstructs afterwards.
 *
 * `unscoredAnswers` is the part worth noticing. Appendix A asks eighteen
 * questions; TRD §7.2's arithmetic uses eleven of them. The other seven are
 * captured for the record, for knockouts, or for later modules — and they are
 * listed here explicitly rather than dropped, because an input that silently
 * does nothing is the defect this codebase has found repeatedly: a question
 * the user answers, believing it matters, that nothing reads.
 */
class InherentRiskResult
{
    /**
     * @param  list<FactorScore>  $factors
     * @param  array<string, array{answer: mixed, reason: string}>  $unscoredAnswers
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly float $score,
        public readonly RiskTier $tier,
        public readonly array $factors,
        public readonly float $totalWeight,
        public readonly string $rulesetVersion,
        public readonly array $unscoredAnswers = [],
        public readonly array $warnings = [],
    ) {}

    /**
     * The derivation as a plain array, for the `tp_inherent_assessments`
     * snapshot and the "Why this score" panel.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'score' => round($this->score, 2),
            'tier' => $this->tier->value,
            'ruleset_version' => $this->rulesetVersion,
            'total_weight' => $this->totalWeight,
            'factors' => array_map(fn (FactorScore $f) => $f->toArray(), $this->factors),
            'unscored_answers' => $this->unscoredAnswers,
            'warnings' => $this->warnings,
        ];
    }

    /** @return array<string, float> factor code => raw 0–1 score */
    public function factorScores(): array
    {
        $scores = [];

        foreach ($this->factors as $factor) {
            $scores[$factor->code] = $factor->score;
        }

        return $scores;
    }

    /** @return array<string, float> factor code => weight */
    public function weights(): array
    {
        $weights = [];

        foreach ($this->factors as $factor) {
            $weights[$factor->code] = $factor->weight;
        }

        return $weights;
    }
}
