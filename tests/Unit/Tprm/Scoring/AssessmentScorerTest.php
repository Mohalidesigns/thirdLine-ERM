<?php

namespace Tests\Unit\Tprm\Scoring;

use App\Enums\Tprm\AssuranceLevel;
use App\Enums\Tprm\ComplianceLevel;
use App\Enums\Tprm\RiskBand;
use App\Services\Tprm\Assessment\AssessmentScorer;
use App\Services\Tprm\Assessment\ScoreableAnswer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The golden-file suite for TRD §7.4 — the evidence-first mechanism.
 *
 * Every value here is stated, not recomputed. That is the point of a golden
 * suite: a change to the scorer that moves one of these numbers has changed
 * what a vendor's assurance is worth, and that has to be an explicit decision
 * with a reviewer on it rather than a refactor that happened to pass.
 *
 * The scorer is constructed with EXPLICIT coefficients rather than from config
 * in most cases, so a fixture asserts the arithmetic rather than the current
 * contents of a config file. The two exceptions are the shipped-coefficient
 * test and AC-03, which are about config being right.
 */
class AssessmentScorerTest extends TestCase
{
    private function scorer(): AssessmentScorer
    {
        return new AssessmentScorer(
            confidenceCoefficients: [
                'self_attested' => 0.35,
                'documented' => 0.60,
                'independently_assured' => 0.85,
                'validated' => 1.00,
            ],
            modifiers: [
                'evidence_expired' => 0.5,
                'scope_mismatch' => 0.7,
                'quality_flag' => 0.5,
                'carry_forward_per_cycle' => 0.9,
                'carry_forward_floor' => 0.5,
            ],
            modifierFloor: 0.2,
            criticalQuestionCap: 0.5,
        );
    }

    /* ------------------------------------------------------------------ */
    /*  AC — assurance coverage */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function all_compliant_is_full_coverage(): void
    {
        $score = $this->scorer()->score([
            $this->answer('Q1', 1, ComplianceLevel::Compliant),
            $this->answer('Q2', 1, ComplianceLevel::Compliant),
        ]);

        $this->assertSame(1.0, round($score->assuranceCoverage, 4));
    }

    #[Test]
    public function all_non_compliant_is_zero_coverage_and_a_null_confidence(): void
    {
        // EC is NULL, not zero. Nothing is evidenced, so there is no evidence
        // confidence to report — a different claim from "the evidence is
        // worthless", and one that must not flow into a residual as a measured
        // zero.
        $score = $this->scorer()->score([
            $this->answer('Q1', 1, ComplianceLevel::NonCompliant),
            $this->answer('Q2', 1, ComplianceLevel::NonCompliant),
        ]);

        $this->assertSame(0.0, round($score->assuranceCoverage, 4));
        $this->assertNull($score->evidenceConfidence);
        $this->assertNull($score->mitigation(0.6));
    }

    #[Test]
    public function partial_compliance_is_half_weight(): void
    {
        $score = $this->scorer()->score([
            $this->answer('Q1', 1, ComplianceLevel::Compliant),
            $this->answer('Q2', 1, ComplianceLevel::Partial),
        ]);

        // (1.0 + 0.5) / 2
        $this->assertSame(0.75, round($score->assuranceCoverage, 4));
    }

    #[Test]
    public function coverage_is_weighted_by_risk_weight_not_by_question_count(): void
    {
        // One heavy question failing outweighs three light ones passing, which
        // is the whole reason risk_weight exists.
        $score = $this->scorer()->score([
            $this->answer('HEAVY', 10, ComplianceLevel::NonCompliant),
            $this->answer('L1', 1, ComplianceLevel::Compliant),
            $this->answer('L2', 1, ComplianceLevel::Compliant),
            $this->answer('L3', 1, ComplianceLevel::Compliant),
        ]);

        // 3 of 13 weight compliant.
        $this->assertSame(round(3 / 13, 4), round($score->assuranceCoverage, 4));
    }

    #[Test]
    public function not_applicable_answers_are_excluded_from_both_sums(): void
    {
        // An inapplicable control is not a failed one. Scoring it zero would
        // punish the vendor for a badly scoped questionnaire.
        $withNa = $this->scorer()->score([
            $this->answer('Q1', 1, ComplianceLevel::Compliant),
            $this->answer('Q2', 1, ComplianceLevel::NotApplicable),
        ]);

        $withoutNa = $this->scorer()->score([
            $this->answer('Q1', 1, ComplianceLevel::Compliant),
        ]);

        $this->assertSame(1.0, round($withNa->assuranceCoverage, 4));
        $this->assertSame($withoutNa->assuranceCoverage, $withNa->assuranceCoverage);
        $this->assertSame($withoutNa->evidenceConfidence, $withNa->evidenceConfidence);
    }

    #[Test]
    public function an_unanswered_question_is_excluded_and_warned_about(): void
    {
        $score = $this->scorer()->score([
            $this->answer('Q1', 1, ComplianceLevel::Compliant),
            $this->answer('Q2', 1, ComplianceLevel::Unanswered),
        ]);

        $this->assertSame(1.0, round($score->assuranceCoverage, 4));
        $this->assertNotEmpty($score->warnings);
        $this->assertStringContainsString('not finished', $score->warnings[0]);
    }

    #[Test]
    public function an_empty_assessment_scores_zero_without_dividing_by_zero(): void
    {
        $score = $this->scorer()->score([]);

        $this->assertSame(0.0, $score->assuranceCoverage);
        $this->assertNull($score->evidenceConfidence);
    }

    /* ------------------------------------------------------------------ */
    /*  EC — the four coefficients */
    /* ------------------------------------------------------------------ */

    /**
     * Each coefficient on its own. These four numbers ARE the product's
     * commercial argument, so each is asserted individually.
     */
    #[Test]
    #[DataProvider('coefficientCases')]
    public function each_assurance_level_scores_its_coefficient(AssuranceLevel $level, float $expected): void
    {
        $score = $this->scorer()->score([
            $this->answer('Q1', 1, ComplianceLevel::Compliant, $level),
        ]);

        $this->assertSame($expected, round((float) $score->evidenceConfidence, 4));
    }

    /** @return array<string, array{AssuranceLevel, float}> */
    public static function coefficientCases(): array
    {
        return [
            'self attested' => [AssuranceLevel::SelfAttested, 0.35],
            'documented' => [AssuranceLevel::Documented, 0.60],
            'independently assured' => [AssuranceLevel::IndependentlyAssured, 0.85],
            'validated' => [AssuranceLevel::Validated, 1.00],
        ];
    }

    #[Test]
    public function a_missing_assurance_level_counts_as_self_attested(): void
    {
        // The vendor said so and attached nothing. Treating an absent level as
        // anything better would let an unrecorded answer outscore an honestly
        // self-attested one.
        // Constructed directly rather than through the helper, which defaults
        // a compliant answer to self-attested and would mask the very case
        // this asserts.
        $score = $this->scorer()->score([
            new ScoreableAnswer(
                questionCode: 'Q1',
                weight: 1,
                compliance: ComplianceLevel::Compliant,
                assuranceLevel: null,
            ),
        ]);

        $this->assertSame(0.35, round((float) $score->evidenceConfidence, 4));
        $this->assertSame('no_assurance_level', $score->answers[0]->modifiers[0]['name']);
    }

    #[Test]
    public function confidence_is_weighted_by_compliance_so_a_failed_answer_cannot_drag_it_down(): void
    {
        // EC's denominator is Σ(w × compliance), so a non-compliant answer
        // contributes to neither side. EC measures the quality of the evidence
        // behind what the vendor DOES do.
        $score = $this->scorer()->score([
            $this->answer('Q1', 1, ComplianceLevel::Compliant, AssuranceLevel::Validated),
            $this->answer('Q2', 1, ComplianceLevel::NonCompliant, AssuranceLevel::SelfAttested),
        ]);

        $this->assertSame(0.5, round($score->assuranceCoverage, 4));
        $this->assertSame(1.0, round((float) $score->evidenceConfidence, 4));
    }

    #[Test]
    public function a_partial_answer_contributes_half_its_weight_to_the_confidence_mean(): void
    {
        $score = $this->scorer()->score([
            $this->answer('Q1', 1, ComplianceLevel::Compliant, AssuranceLevel::Validated),
            $this->answer('Q2', 1, ComplianceLevel::Partial, AssuranceLevel::SelfAttested),
        ]);

        // EC = (1×1.0×1.00 + 0.5×1×0.35) / (1×1.0 + 1×0.5) = 1.175 / 1.5
        $this->assertSame(round(1.175 / 1.5, 4), round((float) $score->evidenceConfidence, 4));
    }

    /* ------------------------------------------------------------------ */
    /*  The modifiers */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function expired_evidence_halves_the_confidence(): void
    {
        $score = $this->scorer()->score([
            $this->answer('Q1', 1, ComplianceLevel::Compliant, AssuranceLevel::IndependentlyAssured,
                overrides: ['evidenceExpired' => true]),
        ]);

        $this->assertSame(0.425, round((float) $score->evidenceConfidence, 4));
    }

    #[Test]
    public function a_scope_mismatch_applies_seven_tenths(): void
    {
        $score = $this->scorer()->score([
            $this->answer('Q1', 1, ComplianceLevel::Compliant, AssuranceLevel::IndependentlyAssured,
                overrides: ['scopeMismatch' => true]),
        ]);

        $this->assertSame(0.595, round((float) $score->evidenceConfidence, 4));
    }

    #[Test]
    public function a_quality_flag_halves_the_confidence(): void
    {
        $score = $this->scorer()->score([
            $this->answer('Q1', 1, ComplianceLevel::Compliant, AssuranceLevel::Documented,
                overrides: ['qualityFlagged' => true]),
        ]);

        $this->assertSame(0.30, round((float) $score->evidenceConfidence, 4));
    }

    #[Test]
    public function modifiers_compound_multiplicatively(): void
    {
        // 0.85 × 0.5 × 0.7 = 0.2975
        $score = $this->scorer()->score([
            $this->answer('Q1', 1, ComplianceLevel::Compliant, AssuranceLevel::IndependentlyAssured,
                overrides: ['evidenceExpired' => true, 'scopeMismatch' => true]),
        ]);

        $this->assertSame(0.2975, round((float) $score->evidenceConfidence, 4));
        $this->assertCount(2, $score->answers[0]->modifiers);
    }

    #[Test]
    public function the_modifier_floor_stops_confidence_reaching_zero(): void
    {
        // 0.35 × 0.5 × 0.7 × 0.5 = 0.061, floored at 0.2. An answer with
        // evidence behind it never scores as though it had none.
        $score = $this->scorer()->score([
            $this->answer('Q1', 1, ComplianceLevel::Compliant, AssuranceLevel::SelfAttested,
                overrides: ['evidenceExpired' => true, 'scopeMismatch' => true, 'qualityFlagged' => true]),
        ]);

        $this->assertSame(0.2, round((float) $score->evidenceConfidence, 4));

        $modifiers = $score->answers[0]->modifiers;
        $this->assertSame('modifier_floor', end($modifiers)['name']);
    }

    /* --- AC-05, the bridge letter ------------------------------------- */

    #[Test]
    public function ac05_a_bridge_letter_caps_the_level_rather_than_multiplying_it(): void
    {
        // The criterion: a control evidenced only by a bridge letter is capped
        // at `documented` and scores 0.60 — NOT 0.85 × some factor, which
        // would give 0.595 and look close enough to be wrong unnoticed.
        $score = $this->scorer()->score([
            $this->answer('Q1', 1, ComplianceLevel::Compliant, AssuranceLevel::IndependentlyAssured,
                overrides: ['bridgeLetterOnly' => true]),
        ]);

        $this->assertSame(0.60, round((float) $score->evidenceConfidence, 4));
        $this->assertSame(AssuranceLevel::Documented, $score->answers[0]->effectiveLevel);
        $this->assertSame('bridge_letter_cap', $score->answers[0]->modifiers[0]['name']);
    }

    #[Test]
    public function a_bridge_letter_does_not_raise_a_lower_level(): void
    {
        $score = $this->scorer()->score([
            $this->answer('Q1', 1, ComplianceLevel::Compliant, AssuranceLevel::SelfAttested,
                overrides: ['bridgeLetterOnly' => true]),
        ]);

        $this->assertSame(0.35, round((float) $score->evidenceConfidence, 4));
        $this->assertSame([], $score->answers[0]->modifiers);
    }

    #[Test]
    public function a_bridge_letter_caps_before_the_other_modifiers_apply(): void
    {
        // Capped to documented (0.60), then halved for expiry: 0.30.
        $score = $this->scorer()->score([
            $this->answer('Q1', 1, ComplianceLevel::Compliant, AssuranceLevel::Validated,
                overrides: ['bridgeLetterOnly' => true, 'evidenceExpired' => true]),
        ]);

        $this->assertSame(0.30, round((float) $score->evidenceConfidence, 4));
    }

    /* --- Carry-forward decay ------------------------------------------ */

    #[Test]
    #[DataProvider('carryForwardCases')]
    public function carry_forward_decays_per_cycle_with_its_own_floor(int $cycles, float $expected): void
    {
        $score = $this->scorer()->score([
            $this->answer('Q1', 1, ComplianceLevel::Compliant, AssuranceLevel::Validated,
                overrides: ['carryForwardCycles' => $cycles]),
        ]);

        $this->assertSame($expected, round((float) $score->evidenceConfidence, 4));
    }

    /** @return array<string, array{int, float}> */
    public static function carryForwardCases(): array
    {
        return [
            'not carried' => [0, 1.0],
            'one cycle' => [1, 0.9],
            'two cycles' => [2, 0.81],
            'three cycles' => [3, 0.729],
            'four cycles' => [4, 0.6561],
            // 0.9^7 = 0.478, below the 0.5 carry-forward floor — which is a
            // different floor from the global modifier floor of 0.2.
            'seven cycles hits the carry-forward floor' => [7, 0.5],
            'twenty cycles stays at the floor' => [20, 0.5],
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Critical questions */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_failed_critical_question_caps_coverage_at_a_half(): void
    {
        // Nine of ten weight compliant would be AC 0.9. One failed critical
        // question caps it at 0.5 — deliberately blunt, because a vendor that
        // fails a critical control has failed regardless of the rest.
        $answers = [$this->answer('CRIT', 1, ComplianceLevel::NonCompliant, isCritical: true)];

        foreach (range(1, 9) as $i) {
            $answers[] = $this->answer("Q{$i}", 1, ComplianceLevel::Compliant, AssuranceLevel::Validated);
        }

        $score = $this->scorer()->score($answers);

        $this->assertSame(0.5, round($score->assuranceCoverage, 4));
        $this->assertTrue($score->coverageCapped);
        $this->assertSame(['CRIT'], $score->criticalFailures);
    }

    #[Test]
    public function the_cap_never_raises_a_coverage_already_below_it(): void
    {
        $score = $this->scorer()->score([
            $this->answer('CRIT', 1, ComplianceLevel::NonCompliant, isCritical: true),
            $this->answer('Q1', 1, ComplianceLevel::NonCompliant),
            $this->answer('Q2', 1, ComplianceLevel::NonCompliant),
            $this->answer('Q3', 1, ComplianceLevel::Compliant),
        ]);

        // 0.25 stands; the cap is a ceiling, not an assignment.
        $this->assertSame(0.25, round($score->assuranceCoverage, 4));
        $this->assertFalse($score->coverageCapped);
    }

    #[Test]
    public function a_critical_question_answered_partially_does_not_cap(): void
    {
        // The rule is "answered non-compliant". A partial answer is a gap, not
        // a failure, and treating it as one would make the cap fire on most
        // real assessments.
        $score = $this->scorer()->score([
            $this->answer('CRIT', 1, ComplianceLevel::Partial, isCritical: true),
            $this->answer('Q1', 1, ComplianceLevel::Compliant),
        ]);

        $this->assertSame(0.75, round($score->assuranceCoverage, 4));
        $this->assertSame([], $score->criticalFailures);
    }

    #[Test]
    public function several_failed_critical_questions_are_all_listed(): void
    {
        $score = $this->scorer()->score([
            $this->answer('CRIT-A', 1, ComplianceLevel::NonCompliant, isCritical: true),
            $this->answer('CRIT-B', 1, ComplianceLevel::NonCompliant, isCritical: true),
            $this->answer('Q1', 8, ComplianceLevel::Compliant),
        ]);

        $this->assertSame(['CRIT-A', 'CRIT-B'], $score->criticalFailures);
        $this->assertSame(0.5, round($score->assuranceCoverage, 4));
    }

    /* ------------------------------------------------------------------ */
    /*  Section and domain scores — FR-ASM-10 */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function scores_are_reported_per_section_and_per_domain(): void
    {
        $score = $this->scorer()->score([
            $this->answer('A1', 1, ComplianceLevel::Compliant, AssuranceLevel::Validated,
                section: 'access', domain: 'security'),
            $this->answer('A2', 1, ComplianceLevel::NonCompliant, section: 'access', domain: 'security'),
            $this->answer('B1', 1, ComplianceLevel::Compliant, AssuranceLevel::SelfAttested,
                section: 'resilience', domain: 'continuity'),
        ]);

        $this->assertSame(0.5, $score->sections['access']['ac']);
        $this->assertSame(1.0, $score->sections['access']['ec']);
        $this->assertSame(1.0, $score->sections['resilience']['ac']);
        $this->assertSame(0.35, $score->sections['resilience']['ec']);

        $this->assertSame(0.5, $score->domains['security']['ac']);
        $this->assertSame(1.0, $score->domains['continuity']['ac']);
    }

    /* ------------------------------------------------------------------ */
    /*  AC-03 — the criterion the module is sold on */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function ac03_two_identical_answer_sets_differ_by_238_residual_points_on_evidence_alone(): void
    {
        // TRD §17 AC-03, verbatim: two engagements with IR = 88 and identical
        // answer sets at AC = 0.90, one all self-attested (EC = 0.35,
        // M = 0.189) and one all independently assured with in-scope current
        // evidence (EC = 0.85, M = 0.459), produce residual scores of 71.4 and
        // 47.6 — a difference of 23.8 attributable entirely to EC.
        $scorer = AssessmentScorer::fromConfig();
        $kmax = (float) config('tprm.scoring.kmax');

        // Nine compliant, one non-compliant, all equally weighted: AC = 0.90.
        $build = function (AssuranceLevel $level) {
            $answers = [];

            foreach (range(1, 9) as $i) {
                $answers[] = $this->answer("Q{$i}", 1, ComplianceLevel::Compliant, $level);
            }
            $answers[] = $this->answer('Q10', 1, ComplianceLevel::NonCompliant, $level);

            return $answers;
        };

        $attested = $scorer->score($build(AssuranceLevel::SelfAttested));
        $assured = $scorer->score($build(AssuranceLevel::IndependentlyAssured));

        $this->assertSame(0.90, round($attested->assuranceCoverage, 4));
        $this->assertSame(0.90, round($assured->assuranceCoverage, 4));

        $this->assertSame(0.35, round((float) $attested->evidenceConfidence, 4));
        $this->assertSame(0.85, round((float) $assured->evidenceConfidence, 4));

        $this->assertSame(0.189, round((float) $attested->mitigation($kmax), 4));
        $this->assertSame(0.459, round((float) $assured->mitigation($kmax), 4));

        // RR = IR × (1 − M), with no findings and no signals.
        $ir = 88.0;
        $residualAttested = $ir * (1 - (float) $attested->mitigation($kmax));
        $residualAssured = $ir * (1 - (float) $assured->mitigation($kmax));

        $this->assertSame(71.4, round($residualAttested, 1));
        $this->assertSame(47.6, round($residualAssured, 1));
        $this->assertSame(23.8, round($residualAttested - $residualAssured, 1));

        // And the two land in different bands, which is what a board sees.
        $this->assertSame(RiskBand::High, RiskBand::fromScore($residualAttested));
        $this->assertSame(RiskBand::Moderate, RiskBand::fromScore($residualAssured));
    }

    #[Test]
    public function the_shipped_coefficients_are_the_specified_ones(): void
    {
        // Guards the config the criterion above depends on.
        $coefficients = config('tprm.scoring.confidence');

        $this->assertSame(0.35, $coefficients['self_attested']);
        $this->assertSame(0.60, $coefficients['documented']);
        $this->assertSame(0.85, $coefficients['independently_assured']);
        $this->assertSame(1.00, $coefficients['validated']);
        $this->assertSame(0.60, config('tprm.scoring.kmax'));
    }

    /* ------------------------------------------------------------------ */
    /*  Determinism */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_same_answers_always_produce_the_same_derivation(): void
    {
        $answers = [
            $this->answer('Q1', 3, ComplianceLevel::Compliant, AssuranceLevel::IndependentlyAssured,
                overrides: ['scopeMismatch' => true]),
            $this->answer('Q2', 1, ComplianceLevel::Partial, AssuranceLevel::Documented,
                overrides: ['carryForwardCycles' => 2]),
            $this->answer('Q3', 2, ComplianceLevel::NotApplicable),
        ];

        $this->assertSame(
            $this->scorer()->score($answers)->toArray(),
            $this->scorer()->score($answers)->toArray()
        );
    }

    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function answer(
        string $code,
        float $weight,
        ComplianceLevel $compliance,
        ?AssuranceLevel $level = null,
        bool $isCritical = false,
        ?string $section = null,
        ?string $domain = null,
        array $overrides = [],
    ): ScoreableAnswer {
        return new ScoreableAnswer(
            questionCode: $code,
            weight: $weight,
            compliance: $compliance,
            assuranceLevel: $level ?? ($compliance === ComplianceLevel::Compliant || $compliance === ComplianceLevel::Partial
                ? AssuranceLevel::SelfAttested
                : null),
            sectionCode: $section,
            domainTag: $domain,
            isCritical: $isCritical,
            evidenceExpired: $overrides['evidenceExpired'] ?? false,
            bridgeLetterOnly: $overrides['bridgeLetterOnly'] ?? false,
            scopeMismatch: $overrides['scopeMismatch'] ?? false,
            qualityFlagged: $overrides['qualityFlagged'] ?? false,
            carryForwardCycles: $overrides['carryForwardCycles'] ?? 0,
        );
    }
}
