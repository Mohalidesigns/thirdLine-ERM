<?php

namespace Tests\Unit\Tprm\Scoring;

use App\Enums\Tprm\RiskTier;
use App\Services\Tprm\Scoring\InherentRiskCalculator;
use App\Services\Tprm\Scoring\Ruleset;
use App\Support\Tprm\DefaultRuleset;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The inherent risk model of TRD §7.2, pinned to exact values.
 *
 * Extends Laravel's TestCase rather than PHPUnit's only because
 * `DefaultRuleset` reads factor weights and band edges from config — the
 * ruleset is built from the container once, and the calculator itself is then
 * exercised with no framework involvement at all.
 *
 * These are golden values. A change to the engine that moves one of them is an
 * explicit, reviewed decision about what a vendor's tier means, not a
 * refactor — which is why each case states the arithmetic it expects rather
 * than asserting against a recomputation.
 */
class InherentRiskCalculatorTest extends TestCase
{
    private InherentRiskCalculator $calculator;

    private Ruleset $ruleset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new InherentRiskCalculator;
        $this->ruleset = Ruleset::shipped();
    }

    /* ------------------------------------------------------------------ */
    /*  The shipped ruleset itself */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_shipped_weights_are_the_trd_weights_and_total_one_hundred(): void
    {
        // TRD §7.2's table. If these move, every tier in every tenant moves.
        $this->assertSame(25.0, $this->ruleset->factorWeight('DATA'));
        $this->assertSame(20.0, $this->ruleset->factorWeight('ACCESS'));
        $this->assertSame(18.0, $this->ruleset->factorWeight('CRIT'));
        $this->assertSame(12.0, $this->ruleset->factorWeight('REG'));
        $this->assertSame(10.0, $this->ruleset->factorWeight('SUB'));
        $this->assertSame(7.0, $this->ruleset->factorWeight('GEO'));
        $this->assertSame(8.0, $this->ruleset->factorWeight('FIN'));

        $this->assertTrue($this->ruleset->weightsTotalOneHundred());
        $this->assertSame(100.0, $this->ruleset->totalWeight());
    }

    #[Test]
    public function the_two_derived_factors_declare_themselves_as_derived(): void
    {
        // REG severities and FIN bands are this product's defaults, not
        // statements from the TRD. The `source` marker is what stops a later
        // reader treating them as requirements, and what the ruleset editor
        // shows beside them.
        $factors = DefaultRuleset::factors();

        $this->assertSame('derived', $factors['REG']['source']);
        $this->assertSame('derived', $factors['FIN']['source']);

        foreach (['DATA', 'ACCESS', 'CRIT', 'SUB', 'GEO'] as $code) {
            $this->assertSame('trd', $factors[$code]['source'], "{$code} should be stated by the TRD.");
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Whole-model golden values */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_maximum_risk_engagement_scores_one_hundred(): void
    {
        $result = $this->calculator->calculate($this->maxAnswers(), $this->ruleset, [
            'max_function_criticality' => 'critical',
            'supervisory_access_impeded' => true,
        ]);

        $this->assertSame(100.0, round($result->score, 4));
        $this->assertSame(RiskTier::Critical, $result->tier);
        $this->assertSame([], $result->warnings);
    }

    #[Test]
    public function a_zero_risk_engagement_scores_zero(): void
    {
        $result = $this->calculator->calculate([
            'A1' => 'none', 'A2' => 'none', 'A3' => 'domestic', 'A5' => 'none',
            'A7' => 'standard', 'A8' => 'over_72h', 'A10' => [], 'A12' => 'many',
            'A13' => 'under_1m', 'A14' => 'under_10m',
        ], $this->ruleset);

        // Not zero: SUB (0.1), GEO (0.1) and FIN (0.1) have no zero option,
        // and CRIT's lowest is 0.2 × 0.6. The floor of the model is a real
        // number and the test states it rather than assuming zero.
        $expected = ((0.0 * 25) + (0.0 * 20) + (0.2 * 0.6 * 18) + (0.0 * 12) + (0.1 * 10) + (0.1 * 7) + (0.1 * 8)) / 100 * 100;

        $this->assertSame(round($expected, 4), round($result->score, 4));
        $this->assertSame(RiskTier::Low, $result->tier);
    }

    #[Test]
    public function the_worked_core_banking_vendor_scores_as_the_trd_describes(): void
    {
        // TRD §7.5's worked example describes this vendor as IR = 88:
        // restricted data, privileged access, critical function with a 2h RTO,
        // sole source. The TRD does not state the other four answers, so this
        // asserts the arithmetic of the four it does state and that the result
        // lands in the high-80s band the example relies on.
        $result = $this->calculator->calculate([
            'A1' => 'restricted', 'A2' => 'over_1m',
            'A3' => 'domestic', 'A5' => 'privileged',
            'A7' => 'critical', 'A8' => 'under_4h',
            'A10' => ['cbn_cyber', 'ndpa', 'aml_cft'],
            'A12' => 'sole', 'A13' => 'over_6m', 'A14' => 'over_1b',
        ], $this->ruleset, ['max_function_criticality' => 'critical']);

        $scores = $result->factorScores();

        $this->assertSame(1.0, round($scores['DATA'], 4));
        $this->assertSame(1.0, round($scores['ACCESS'], 4));
        $this->assertSame(1.0, round($scores['CRIT'], 4));
        $this->assertSame(1.0, round($scores['SUB'], 4));
        $this->assertSame(1.0, round($scores['FIN'], 4));
        $this->assertSame(0.1, round($scores['GEO'], 4));

        // REG: (cbn_cyber 1.0 + ndpa 0.9 + aml_cft 1.0) ÷ 5.6, the total
        // severity of all seven shipped regimes. The denominator is asserted
        // on its own below, so editing a severity fails there with a clear
        // reason rather than here with an unexplained decimal.
        $this->assertSame(round(2.9 / 5.6, 4), round($scores['REG'], 4));

        $this->assertGreaterThanOrEqual(85.0, $result->score);
        $this->assertSame(RiskTier::Critical, $result->tier);
    }

    /* ------------------------------------------------------------------ */
    /*  The two multiplied factors */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $answers
     */
    #[Test]
    #[DataProvider('dataFactorCases')]
    public function the_data_factor_multiplies_classification_by_volume(array $answers, float $expected): void
    {
        $result = $this->calculator->calculate($answers + $this->baseAnswers(), $this->ruleset);

        $this->assertSame($expected, round($result->factorScores()['DATA'], 4));
    }

    /** @return array<string, array{array<string, mixed>, float}> */
    public static function dataFactorCases(): array
    {
        return [
            'restricted, over 1m' => [['A1' => 'restricted', 'A2' => 'over_1m'], 1.0],
            'restricted, under 1k' => [['A1' => 'restricted', 'A2' => 'under_1k'], 0.7],
            'confidential, 1k-100k' => [['A1' => 'confidential', 'A2' => '1k_100k'], round(0.7 * 0.85, 4)],
            'internal, 100k-1m' => [['A1' => 'internal', 'A2' => '100k_1m'], round(0.3 * 0.95, 4)],
            // A million rows of PUBLIC data is still barely a risk. This is
            // what multiplying rather than adding buys, and why the case is
            // pinned.
            'public, over 1m' => [['A1' => 'public', 'A2' => 'over_1m'], 0.1],
            // No data at all is zero whatever the volume answer says.
            'none, over 1m' => [['A1' => 'none', 'A2' => 'over_1m'], 0.0],
        ];
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    #[Test]
    #[DataProvider('critFactorCases')]
    public function the_criticality_factor_multiplies_criticality_by_rto(array $answers, float $expected): void
    {
        $result = $this->calculator->calculate($answers + $this->baseAnswers(), $this->ruleset);

        $this->assertSame($expected, round($result->factorScores()['CRIT'], 4));
    }

    /** @return array<string, array{array<string, mixed>, float}> */
    public static function critFactorCases(): array
    {
        return [
            'critical, 4h' => [['A7' => 'critical', 'A8' => 'under_4h'], 1.0],
            'critical, 24h' => [['A7' => 'critical', 'A8' => 'under_24h'], 0.9],
            'critical, 72h' => [['A7' => 'critical', 'A8' => 'under_72h'], 0.75],
            'critical, over 72h' => [['A7' => 'critical', 'A8' => 'over_72h'], 0.6],
            'important, 4h' => [['A7' => 'important', 'A8' => 'under_4h'], 0.6],
            // A Standard function that recovers in two hours is a
            // fast-recovering unimportant thing, not a critical one.
            'standard, 4h' => [['A7' => 'standard', 'A8' => 'under_4h'], 0.2],
        ];
    }

    #[Test]
    public function criticality_is_inherited_from_the_supported_functions_not_the_answer(): void
    {
        // FR-TIER-06: the engagement inherits the highest criticality of any
        // function it supports, and the derivation is shown. A requester who
        // answers "standard" while selecting a critical function does not get
        // to lower the tier.
        $result = $this->calculator->calculate(
            ['A7' => 'standard', 'A8' => 'under_4h'] + $this->baseAnswers(),
            $this->ruleset,
            ['max_function_criticality' => 'critical']
        );

        $this->assertSame(1.0, round($result->factorScores()['CRIT'], 4));

        $crit = collect($result->factors)->firstWhere('code', 'CRIT');
        $this->assertStringContainsString('Inherited', (string) $crit->note);
    }

    /* ------------------------------------------------------------------ */
    /*  The two overrides in §7.2 */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_transition_over_six_months_scores_as_sole_source(): void
    {
        $result = $this->calculator->calculate(
            ['A12' => 'many', 'A13' => 'over_6m'] + $this->baseAnswers(),
            $this->ruleset
        );

        // "Many alternatives" would be 0.1. Nine months to move to any of them
        // makes it a sole source during an incident.
        $this->assertSame(1.0, round($result->factorScores()['SUB'], 4));

        $sub = collect($result->factors)->firstWhere('code', 'SUB');
        $this->assertStringContainsString('more than six months', (string) $sub->note);
    }

    #[Test]
    public function the_transition_override_never_lowers_the_substitutability_score(): void
    {
        $result = $this->calculator->calculate(
            ['A12' => 'sole', 'A13' => 'under_1m'] + $this->baseAnswers(),
            $this->ruleset
        );

        $this->assertSame(1.0, round($result->factorScores()['SUB'], 4));
    }

    #[Test]
    public function an_impeded_jurisdiction_adds_two_tenths_capped_at_one(): void
    {
        $withBasis = $this->calculator->calculate(
            ['A3' => 'adequate_basis'] + $this->baseAnswers(),
            $this->ruleset,
            ['supervisory_access_impeded' => true]
        );

        $this->assertSame(0.8, round($withBasis->factorScores()['GEO'], 4));

        // Already at 1.0, so the cap holds rather than producing 1.2.
        $noBasis = $this->calculator->calculate(
            ['A3' => 'no_basis'] + $this->baseAnswers(),
            $this->ruleset,
            ['supervisory_access_impeded' => true]
        );

        $this->assertSame(1.0, round($noBasis->factorScores()['GEO'], 4));
    }

    /* ------------------------------------------------------------------ */
    /*  Regulatory exposure */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_regulatory_severities_total_the_expected_denominator(): void
    {
        // Every REG score is a proportion of this number, so it is the single
        // value that shifts all of them at once. Pinned on its own so that
        // editing a regime's severity fails here, saying what changed, rather
        // than in four other tests as an unexplained decimal.
        $total = array_sum(array_map(
            fn (array $option) => (float) $option['score'],
            DefaultRuleset::factors()['REG']['options']
        ));

        $this->assertSame(5.6, round($total, 4));
    }

    #[Test]
    public function regulatory_exposure_weighs_severity_rather_than_counting_regimes(): void
    {
        $twoHeavy = $this->calculator->calculate(
            ['A10' => ['cbn_cyber', 'aml_cft']] + $this->baseAnswers(),
            $this->ruleset
        );

        $threeLight = $this->calculator->calculate(
            ['A10' => ['consumer_protection', 'open_banking', 'sector_specific']] + $this->baseAnswers(),
            $this->ruleset
        );

        // 2.0/5.7 against 1.8/5.7 — two heavy regimes outrank three light
        // ones, which a count could not express.
        $this->assertGreaterThan(
            $threeLight->factorScores()['REG'],
            $twoHeavy->factorScores()['REG']
        );
    }

    #[Test]
    public function no_applicable_regimes_scores_zero_and_an_unknown_regime_is_ignored(): void
    {
        $none = $this->calculator->calculate(['A10' => []] + $this->baseAnswers(), $this->ruleset);
        $this->assertSame(0.0, round($none->factorScores()['REG'], 4));

        // A tenant-added regime we do not ship is skipped silently: this list
        // is extensible and a warning on every recomputation would be noise.
        $unknown = $this->calculator->calculate(
            ['A10' => ['cbn_cyber', 'some_tenant_regime']] + $this->baseAnswers(),
            $this->ruleset
        );
        $this->assertSame(round(1.0 / 5.6, 4), round($unknown->factorScores()['REG'], 4));
        $this->assertSame([], $unknown->warnings);
    }

    /* ------------------------------------------------------------------ */
    /*  Bad and missing input */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_unrecognised_option_scores_zero_and_says_so(): void
    {
        // The failure this guards against: `access: "administrator"` instead
        // of `"privileged"` producing a Low-tiered core banking vendor with
        // nothing on screen to explain it.
        $result = $this->calculator->calculate(
            ['A5' => 'administrator'] + $this->baseAnswers(),
            $this->ruleset
        );

        $this->assertSame(0.0, round($result->factorScores()['ACCESS'], 4));
        $this->assertNotEmpty($result->warnings);
        $this->assertStringContainsString('administrator', $result->warnings[0]);
        $this->assertStringContainsString('ACCESS', $result->warnings[0]);
    }

    #[Test]
    public function a_missing_volume_answer_leaves_the_classification_intact(): void
    {
        // The dangerous alternative is treating a missing volume as zero,
        // which would score restricted customer data as no risk at all.
        $result = $this->calculator->calculate(
            ['A1' => 'restricted', 'A2' => null] + $this->baseAnswers(),
            $this->ruleset
        );

        $this->assertSame(1.0, round($result->factorScores()['DATA'], 4));
    }

    #[Test]
    public function a_missing_rto_answer_leaves_the_criticality_intact(): void
    {
        $result = $this->calculator->calculate(
            ['A7' => 'critical', 'A8' => null] + $this->baseAnswers(),
            $this->ruleset
        );

        $this->assertSame(1.0, round($result->factorScores()['CRIT'], 4));
    }

    #[Test]
    public function an_entirely_empty_answer_set_does_not_throw(): void
    {
        $result = $this->calculator->calculate([], $this->ruleset);

        $this->assertSame(0.0, round($result->score, 4));
        $this->assertSame(RiskTier::Low, $result->tier);
        // Silence would be the bug. Every unanswered scoring question is named.
        $this->assertNotEmpty($result->warnings);
    }

    #[Test]
    public function captured_but_unscored_answers_are_reported_rather_than_dropped(): void
    {
        // Appendix A asks eighteen questions; §7.2's arithmetic uses eleven.
        // The other seven must be visibly captured, or a user answers a
        // question believing it matters and nothing ever reads it.
        $result = $this->calculator->calculate(
            ['A9' => 'directly', 'A16' => 'unescorted', 'A18' => true] + $this->baseAnswers(),
            $this->ruleset
        );

        $this->assertArrayHasKey('A9', $result->unscoredAnswers);
        $this->assertArrayHasKey('A16', $result->unscoredAnswers);
        $this->assertArrayHasKey('A18', $result->unscoredAnswers);
        $this->assertStringContainsString('criticality × RTO', $result->unscoredAnswers['A9']['reason']);
    }

    #[Test]
    public function a_ruleset_with_no_weight_produces_zero_rather_than_dividing_by_zero(): void
    {
        $broken = new Ruleset(
            version: 'broken',
            factors: ['DATA' => ['label' => 'Data', 'weight' => 0, 'options' => [
                ['value' => 'restricted', 'label' => 'Restricted', 'score' => 1.0],
            ]]],
            knockouts: [],
            bandEdges: DefaultRuleset::bandEdges(),
            volumeBands: DefaultRuleset::dataVolumeBands(),
            rtoBands: DefaultRuleset::rtoBands(),
        );

        $result = $this->calculator->calculate(['A1' => 'restricted'], $broken);

        $this->assertSame(0.0, $result->score);
        $this->assertStringContainsString('no factor weight', implode(' ', $result->warnings));
    }

    /* ------------------------------------------------------------------ */
    /*  Determinism */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_same_inputs_always_produce_the_same_derivation(): void
    {
        // AC-15: two users viewing the same score at the same time see
        // identical figures. The calculator being a pure function of its
        // inputs is what makes that true rather than hoped for.
        $answers = $this->maxAnswers();
        $context = ['max_function_criticality' => 'critical'];

        $first = $this->calculator->calculate($answers, $this->ruleset, $context);
        $second = $this->calculator->calculate($answers, $this->ruleset, $context);

        $this->assertSame($first->toArray(), $second->toArray());
    }

    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private function baseAnswers(): array
    {
        return [
            'A1' => 'internal', 'A2' => 'under_1k', 'A3' => 'domestic', 'A5' => 'read_only',
            'A7' => 'standard', 'A8' => 'over_72h', 'A10' => [], 'A12' => 'many',
            'A13' => 'under_1m', 'A14' => 'under_10m',
        ];
    }

    /** @return array<string, mixed> */
    private function maxAnswers(): array
    {
        return [
            'A1' => 'restricted', 'A2' => 'over_1m', 'A3' => 'no_basis', 'A5' => 'privileged',
            'A7' => 'critical', 'A8' => 'under_4h',
            'A10' => ['cbn_cyber', 'aml_cft', 'ndpa', 'pci_dss', 'open_banking', 'sector_specific', 'consumer_protection'],
            'A12' => 'sole', 'A13' => 'over_6m', 'A14' => 'over_1b',
        ];
    }
}
