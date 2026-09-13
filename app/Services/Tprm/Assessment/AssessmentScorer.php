<?php

namespace App\Services\Tprm\Assessment;

use App\Enums\Tprm\AssuranceLevel;

/**
 * Assurance Coverage and Evidence Confidence — TRD §7.4, and the mechanism the
 * whole product is sold on.
 *
 *     AC = Σ (w_q × compliance_q) / Σ w_q
 *     EC = Σ (w_q × compliance_q × conf_q) / Σ (w_q × compliance_q)
 *
 * AC asks how much of the weighted control set is answered compliantly. EC asks
 * how much of THAT is actually evidenced. Two vendors giving identical answers
 * score 23.8 residual points apart when one attaches a SOC 2 and the other
 * attaches nothing (AC-03) — and the arithmetic that produces the gap is
 * entirely in this class.
 *
 * THREE THINGS ABOUT THE FORMULAE THAT ARE EASY TO GET WRONG:
 *
 *   EC IS WEIGHTED BY COMPLIANCE, NOT JUST BY WEIGHT. Its denominator is
 *   Σ(w × compliance), so a non-compliant answer contributes nothing to either
 *   side and cannot drag EC down. That is correct: EC measures the quality of
 *   the evidence behind what the vendor DOES do, and a control it admits it
 *   does not operate has no evidence to assess.
 *
 *   `na` IS EXCLUDED FROM BOTH SUMS, not scored zero. An inapplicable control
 *   is not a failed one, and scoring it zero would punish a vendor for a badly
 *   scoped questionnaire.
 *
 *   THE CRITICAL-QUESTION CAP IS APPLIED TO AC AFTER THE MEAN, not by zeroing
 *   the failed question. Zeroing it would let a large, otherwise-compliant
 *   questionnaire absorb the failure; the cap is deliberately blunt, because a
 *   vendor that fails a critical control has failed regardless of what else it
 *   does well.
 *
 * PURE. No database, no config read at computation time — every coefficient
 * arrives through the constructor, so a fixture and production run the same
 * arithmetic and a golden value cannot drift because a config file changed.
 */
class AssessmentScorer
{
    /**
     * @param  array<string, float>  $confidenceCoefficients  assurance level => conf_q
     * @param  array<string, float>  $modifiers
     */
    public function __construct(
        private readonly array $confidenceCoefficients,
        private readonly array $modifiers,
        private readonly float $modifierFloor,
        private readonly float $criticalQuestionCap,
    ) {}

    /**
     * Build from configuration. Production's entry point; the tests construct
     * it directly with explicit coefficients.
     */
    public static function fromConfig(): self
    {
        return new self(
            confidenceCoefficients: (array) config('tprm.scoring.confidence'),
            modifiers: (array) config('tprm.scoring.modifiers'),
            modifierFloor: (float) config('tprm.scoring.modifier_floor'),
            criticalQuestionCap: (float) config('tprm.scoring.critical_question_ac_cap'),
        );
    }

    /**
     * @param  list<ScoreableAnswer>  $answers
     */
    public function score(array $answers): AssessmentScore
    {
        $scored = [];
        $warnings = [];

        $coverageNumerator = 0.0;
        $weightTotal = 0.0;
        $confidenceNumerator = 0.0;
        $confidenceDenominator = 0.0;

        $criticalFailures = [];
        $applicable = 0;

        foreach ($answers as $answer) {
            $applicable++;

            $complianceWeight = $answer->compliance->weight();

            // `na` and `unanswered` leave both sums untouched.
            if ($complianceWeight === null) {
                $scored[] = new AnswerScore(
                    questionCode: $answer->questionCode,
                    weight: $answer->weight,
                    complianceWeight: null,
                    effectiveLevel: $answer->assuranceLevel,
                    baseConfidence: 0.0,
                    confidence: 0.0,
                    excluded: true,
                    exclusionReason: $answer->compliance->label(),
                );

                if ($answer->compliance->value === 'unanswered') {
                    $warnings[] = "{$answer->questionCode} is unanswered, so it is excluded from the score. "
                        .'An assessment with unanswered questions is not finished.';
                }

                continue;
            }

            if ($answer->isCritical && $complianceWeight === 0.0) {
                $criticalFailures[] = $answer->questionCode;
            }

            $resolved = $this->resolveConfidence($answer);

            $score = new AnswerScore(
                questionCode: $answer->questionCode,
                weight: $answer->weight,
                complianceWeight: $complianceWeight,
                effectiveLevel: $resolved['level'],
                baseConfidence: $resolved['base'],
                confidence: $resolved['confidence'],
                modifiers: $resolved['modifiers'],
            );

            $scored[] = $score;

            $weightTotal += $answer->weight;
            $coverageNumerator += $score->coverageContribution();
            $confidenceDenominator += $score->coverageContribution();
            $confidenceNumerator += $score->confidenceContribution();
        }

        $coverage = $weightTotal > 0.0 ? $coverageNumerator / $weightTotal : 0.0;

        // EC is null, not zero, when nothing scored above zero compliance —
        // there is no evidence confidence to report because nothing is
        // evidenced, which is a different claim from "the evidence is worthless".
        $confidence = $confidenceDenominator > 0.0
            ? $confidenceNumerator / $confidenceDenominator
            : null;

        $capped = false;

        if ($criticalFailures !== [] && $coverage > $this->criticalQuestionCap) {
            $coverage = $this->criticalQuestionCap;
            $capped = true;
        }

        return new AssessmentScore(
            assuranceCoverage: $coverage,
            evidenceConfidence: $confidence,
            answers: $scored,
            sections: $this->group($scored, $answers, fn (ScoreableAnswer $a) => $a->sectionCode),
            domains: $this->group($scored, $answers, fn (ScoreableAnswer $a) => $a->domainTag),
            criticalFailures: $criticalFailures,
            coverageCapped: $capped,
            applicableCount: $applicable,
            scoredCount: count(array_filter($scored, fn (AnswerScore $s) => ! $s->excluded)),
            warnings: array_values(array_unique($warnings)),
        );
    }

    /**
     * conf_q, with every modifier in TRD §7.4 applied in order.
     *
     * ORDER MATTERS FOR ONE OF THEM. The bridge-letter rule is a CAP on the
     * assurance LEVEL, not a multiplier on the confidence, so it is applied
     * before the coefficient is looked up (AC-05: the control scores 0.60, not
     * 0.85 × something). Applying it as a multiplier would give 0.85 × 0.7 =
     * 0.595, which is close enough to look right and is not what the
     * specification says.
     *
     * @return array{level: AssuranceLevel|null, base: float, confidence: float, modifiers: list<array{name: string, factor: float|string, note: string}>}
     */
    private function resolveConfidence(ScoreableAnswer $answer): array
    {
        $level = $answer->assuranceLevel;
        $modifiers = [];

        // An answer with no stated assurance level is self-attested: the
        // vendor said so and attached nothing.
        if ($level === null) {
            $level = AssuranceLevel::SelfAttested;
            $modifiers[] = [
                'name' => 'no_assurance_level',
                'factor' => 'self_attested',
                'note' => 'No assurance level was recorded, so the answer counts as the vendor\'s own assertion.',
            ];
        }

        if ($answer->bridgeLetterOnly) {
            $capped = $level->cappedAt(AssuranceLevel::Documented);

            if ($capped !== $level) {
                $modifiers[] = [
                    'name' => 'bridge_letter_cap',
                    'factor' => AssuranceLevel::Documented->value,
                    'note' => 'Evidenced only by a bridge letter for the gap period, which is the vendor\'s '
                        .'assertion that nothing changed rather than an auditor\'s opinion that nothing did.',
                ];
                $level = $capped;
            }
        }

        $base = $this->confidenceCoefficients[$level->value] ?? 0.0;
        $confidence = $base;

        foreach ([
            ['flag' => $answer->evidenceExpired, 'key' => 'evidence_expired',
                'note' => 'The evidence has expired, or its period ended more than twelve months ago.'],
            ['flag' => $answer->scopeMismatch, 'key' => 'scope_mismatch',
                'note' => 'The certificate scope does not name the service consumed.'],
            ['flag' => $answer->qualityFlagged, 'key' => 'quality_flag',
                'note' => 'The response-quality checker flagged this answer as evasive or contradicted by its evidence.'],
        ] as $modifier) {
            if (! $modifier['flag']) {
                continue;
            }

            $factor = (float) ($this->modifiers[$modifier['key']] ?? 1.0);
            $confidence *= $factor;
            $modifiers[] = ['name' => $modifier['key'], 'factor' => $factor, 'note' => $modifier['note']];
        }

        if ($answer->carryForwardCycles > 0) {
            $perCycle = (float) ($this->modifiers['carry_forward_per_cycle'] ?? 1.0);
            $floor = (float) ($this->modifiers['carry_forward_floor'] ?? 0.0);

            // Compounded per elapsed cycle, with its OWN floor — separate from
            // the global modifier floor, because §7.4 states a different one.
            $decay = max($floor, $perCycle ** $answer->carryForwardCycles);
            $confidence *= $decay;

            $modifiers[] = [
                'name' => 'carry_forward_decay',
                'factor' => round($decay, 4),
                'note' => "Carried forward {$answer->carryForwardCycles} cycle(s) without being re-evidenced.",
            ];
        }

        $floored = max($this->modifierFloor, $confidence);

        if ($floored !== $confidence) {
            $modifiers[] = [
                'name' => 'modifier_floor',
                'factor' => $this->modifierFloor,
                'note' => 'Modifiers are floored, so an answer with evidence behind it never scores as though it '
                    .'had none.',
            ];
            $confidence = $floored;
        }

        return ['level' => $level, 'base' => $base, 'confidence' => $confidence, 'modifiers' => $modifiers];
    }

    /**
     * AC and EC per section or per domain — FR-ASM-10's "scoring at question,
     * section, domain and assessment level".
     *
     * @param  list<AnswerScore>  $scored
     * @param  list<ScoreableAnswer>  $answers
     * @param  callable(ScoreableAnswer): ?string  $key
     * @return array<string, array{ac: float, ec: float|null, weight: float}>
     */
    private function group(array $scored, array $answers, callable $key): array
    {
        $buckets = [];

        foreach ($scored as $index => $score) {
            $bucket = $key($answers[$index]);

            if ($bucket === null || $score->excluded) {
                continue;
            }

            $buckets[$bucket] ??= ['coverage' => 0.0, 'weight' => 0.0, 'conf' => 0.0];
            $buckets[$bucket]['weight'] += $score->weight;
            $buckets[$bucket]['coverage'] += $score->coverageContribution();
            $buckets[$bucket]['conf'] += $score->confidenceContribution();
        }

        $result = [];

        foreach ($buckets as $bucket => $totals) {
            $result[$bucket] = [
                'ac' => $totals['weight'] > 0 ? round($totals['coverage'] / $totals['weight'], 4) : 0.0,
                'ec' => $totals['coverage'] > 0 ? round($totals['conf'] / $totals['coverage'], 4) : null,
                'weight' => $totals['weight'],
            ];
        }

        return $result;
    }
}
