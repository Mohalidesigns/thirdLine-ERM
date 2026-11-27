<?php

namespace Tests\Unit\Sm;

use App\Services\Sm\ScenarioComputationService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Characterisation tests for ScenarioComputationService (ADR 0027 D1, Amendment 2 §A2.6).
 * Pins all twelve published figures from `docs/ScenarioModelling/Poor succesion planning.xls`
 * (PHASE0-SPIKE.md).
 */
class ScenarioComputationServiceTest extends TestCase
{
    private ScenarioComputationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ScenarioComputationService;
    }

    #[Test]
    public function it_reproduces_the_published_scorecard_totals_exactly(): void
    {
        // Reference instance from Poor succesion planning.xls Scorecard sheet:
        // rows (level, weight) = (5,5), (5,2), (1,5), (5,5), (5,5), nature = mixed
        $components = [
            ['component_code' => 'control_environment', 'efficiency_level' => 5, 'weighting_factor' => 5],
            ['component_code' => 'risk_assessment', 'efficiency_level' => 5, 'weighting_factor' => 2],
            ['component_code' => 'control_activities', 'efficiency_level' => 1, 'weighting_factor' => 5],
            ['component_code' => 'information_communication', 'efficiency_level' => 5, 'weighting_factor' => 5],
            ['component_code' => 'monitoring', 'efficiency_level' => 5, 'weighting_factor' => 5],
        ];

        $result = $this->service->computeScorecard('mixed', $components);

        // K12 = 22, L12 = 15.2, global = 0.6909090909090909
        $this->assertSame(22, $result['total_weight']);
        $this->assertEqualsWithDelta(15.2, $result['total_weighted_score'], 0.00000001);
        $this->assertEqualsWithDelta(0.6909090909090909, $result['global_score'], 0.000000000000001);

        // Check individual component row math
        $row1 = $result['components'][0];
        $this->assertSame(0.90, $row1['level_score']);
        $this->assertEqualsWithDelta(0.85, $row1['adjusted_score'], 0.0001); // 0.90 - 0.05
        $this->assertEqualsWithDelta(4.25, $row1['weighted_score'], 0.0001); // 0.85 * 5

        $row3 = $result['components'][2];
        $this->assertSame(0.20, $row3['level_score']);
        $this->assertEqualsWithDelta(0.15, $row3['adjusted_score'], 0.0001); // 0.20 - 0.05
        $this->assertEqualsWithDelta(0.75, $row3['weighted_score'], 0.0001); // 0.15 * 5
    }

    #[Test]
    public function it_reproduces_the_published_frequency_and_interval_exactly(): void
    {
        // Reference instance from LS sheet rows 72-77:
        // E72 = 55, E73 = 0.6909090909090909, E74 = 0.95
        $drivers = [
            ['driver_type' => 'exposed_staff_count', 'computation_role' => 'count', 'value' => 55.0],
            ['driver_type' => 'retention_plan_efficiency', 'computation_role' => 'control_score_complement', 'value' => 0.0, 'sm_scorecard_id' => 1],
            ['driver_type' => 'competition_pressure_prob', 'computation_role' => 'probability', 'value' => 0.95],
        ];

        $scorecardScore = 0.6909090909090909;
        $result = $this->service->computeFrequency($drivers, $scorecardScore);

        // E75 = 16.15 events/year
        $this->assertEqualsWithDelta(16.15, $result['total_frequency'], 0.00000000001);

        // E77 = 1 / 16.15 = 0.061919504643962855 years
        $this->assertEqualsWithDelta(0.061919504643962855, $result['interval_years'], 0.000000000000001);
        $this->assertStringContainsString('1 event in 0.062 year(s)', $result['interval_display']);
    }

    #[Test]
    public function it_reproduces_the_published_gross_impact_totals_exactly(): void
    {
        $frequencyInterval = 0.061919504643962855;

        $types = [
            [
                'name' => 'Cost of hiring and training new staff',
                'aggregation_template' => 'staff_turnover_interval',
                'eligible_to_transfer' => false,
                'criteria' => [
                    ['computation_role' => 'count', 'typical_value' => 55, 'serious_value' => 55, 'extreme_value' => 55],
                    ['computation_role' => 'unit_amount', 'typical_value' => 1084669, 'serious_value' => 1084669, 'extreme_value' => 1084669],
                    ['computation_role' => 'duration', 'typical_value' => 4, 'serious_value' => 6, 'extreme_value' => 12],
                ],
            ],
            [
                'name' => 'Cost of litigation',
                'aggregation_template' => 'litigation_probability',
                'eligible_to_transfer' => false,
                'criteria' => [
                    ['computation_role' => 'count', 'typical_value' => 55, 'serious_value' => 55, 'extreme_value' => 55],
                    ['computation_role' => 'unit_amount', 'typical_value' => 1084669, 'serious_value' => 1084669, 'extreme_value' => 1084669],
                    ['computation_role' => 'flat_fee', 'typical_value' => 10000000, 'serious_value' => 10000000, 'extreme_value' => 10000000],
                    ['computation_role' => 'probability', 'typical_value' => 0.005, 'serious_value' => 0.01, 'extreme_value' => 0.015],
                ],
            ],
        ];

        $result = $this->service->computeImpact($types, $frequencyInterval);

        // Type 1 totals:
        // E85 = 14 775 676.78018576
        // F85 = 22 163 515.17027864
        // G85 = 44 327 030.34055728
        $hiring = $result['types'][0];
        $this->assertEqualsWithDelta(14775676.78018576, $hiring['typical_total'], 0.000001);
        $this->assertEqualsWithDelta(22163515.17027864, $hiring['serious_total'], 0.000001);
        $this->assertEqualsWithDelta(44327030.34055728, $hiring['extreme_total'], 0.000001);

        // Type 2 totals:
        // E90 = 348 283.975
        // F90 = 696 567.95
        // G90 = 1 044 851.925
        $litigation = $result['types'][1];
        $this->assertEqualsWithDelta(348283.975, $litigation['typical_total'], 0.000001);
        $this->assertEqualsWithDelta(696567.95, $litigation['serious_total'], 0.000001);
        $this->assertEqualsWithDelta(1044851.925, $litigation['extreme_total'], 0.000001);

        // Gross totals (E91):
        // Typical: 15 123 960.75518576
        // Serious: 22 860 083.120278638
        // Extreme: 45 371 882.265557274
        $gross = $result['gross_impact'];
        $this->assertEqualsWithDelta(15123960.75518576, $gross['typical'], 0.000001);
        $this->assertEqualsWithDelta(22860083.120278638, $gross['serious'], 0.000001);
        $this->assertEqualsWithDelta(45371882.265557274, $gross['extreme'], 0.000001);
    }

    #[Test]
    public function it_reproduces_uninsured_net_impact_matching_gross_impact_exactly(): void
    {
        // Uninsured scenario: transferable is 0 for all severities
        $grossImpact = [
            'typical' => 15123960.75518576,
            'serious' => 22860083.120278638,
            'extreme' => 45371882.265557274,
        ];
        $transferable = [
            'typical' => 0.0,
            'serious' => 0.0,
            'extreme' => 0.0,
        ];

        $coverages = [
            'typical' => ['deductible_local' => 0, 'limit_local' => 0],
            'serious' => ['deductible_local' => 0, 'limit_local' => 0],
            'extreme' => ['deductible_local' => 0, 'limit_local' => 0],
        ];

        $netting = $this->service->computeInsurance($coverages, $grossImpact, $transferable);

        // In the workbook rows 95-106: when transferable is 0, Net Impact equals Gross Impact bit-for-bit
        $this->assertEqualsWithDelta(15123960.75518576, $netting['typical']['net_impact'], 0.000001);
        $this->assertEqualsWithDelta(22860083.120278638, $netting['serious']['net_impact'], 0.000001);
        $this->assertEqualsWithDelta(45371882.265557274, $netting['extreme']['net_impact'], 0.000001);
    }

    #[Test]
    public function it_computes_tiered_insurance_netting_with_haircuts(): void
    {
        $grossImpact = ['typical' => 100000000.0, 'serious' => 200000000.0, 'extreme' => 500000000.0];
        $transferable = ['typical' => 50000000.0, 'serious' => 100000000.0, 'extreme' => 200000000.0];

        // Policy:
        // Local: ded = 5,000,000, limit = 40,000,000
        // Group: ded = 40,000,000, limit = 150,000,000
        // Recovery 90%, Prob cover 95%, Default haircut 2%
        $coverages = [
            'typical' => [
                'deductible_local' => 5000000.0,
                'limit_local' => 40000000.0,
                'deductible_group' => 40000000.0,
                'limit_group' => 150000000.0,
                'recovery_rate' => 0.90,
                'probability_of_cover' => 0.95,
                'insurer_default_haircut' => 0.02,
            ],
            'serious' => [
                'deductible_local' => 5000000.0,
                'limit_local' => 40000000.0,
                'deductible_group' => 40000000.0,
                'limit_group' => 150000000.0,
                'recovery_rate' => 0.90,
                'probability_of_cover' => 0.95,
                'insurer_default_haircut' => 0.02,
            ],
            'extreme' => [
                'deductible_local' => 5000000.0,
                'limit_local' => 40000000.0,
                'deductible_group' => 40000000.0,
                'limit_group' => 150000000.0,
                'recovery_rate' => 0.90,
                'probability_of_cover' => 0.95,
                'insurer_default_haircut' => 0.02,
            ],
        ];

        $netting = $this->service->computeInsurance($coverages, $grossImpact, $transferable);

        // Typical: t = 50M.
        // dedG (40M) <= t < limG (150M) -> residual = dedG - limL + dedL = 40M - 40M + 5M = 5,000,000
        $this->assertEqualsWithDelta(5000000.0, $netting['typical']['residual_amount'], 0.01);

        // Total haircut = 1 - (0.90 * 0.95) + 0.02 = 1 - 0.855 + 0.02 = 0.165
        $this->assertEqualsWithDelta(0.165, $netting['typical']['total_haircut'], 0.0001);

        // Haircut impact = (transferable - residual) * total_haircut = (50M - 5M) * 0.165 = 45M * 0.165 = 7,425,000
        $this->assertEqualsWithDelta(7425000.0, $netting['typical']['haircut_impact'], 0.01);

        // Net impact = residual + haircut_impact + gross - transferable
        // = 5,000,000 + 7,425,000 + 100,000,000 - 50,000,000 = 62,425,000
        $this->assertEqualsWithDelta(62425000.0, $netting['typical']['net_impact'], 0.01);
    }

    #[Test]
    public function it_tests_all_five_residual_tiers_and_boundaries_per_adr_0027(): void
    {
        $dedL = 100.0;
        $limL = 500.0;
        $dedG = 800.0;
        $limG = 1200.0;

        $policy = [
            'deductible_local' => $dedL,
            'limit_local' => $limL,
            'deductible_group' => $dedG,
            'limit_group' => $limG,
            'recovery_rate' => 1.0,
            'probability_of_cover' => 1.0,
            'insurer_default_haircut' => 0.0,
        ];

        // Tier 1: t < dedL -> residual = t
        $resTier1 = $this->service->computeInsurance(['typical' => $policy], ['typical' => 1000.0], ['typical' => 50.0]);
        $this->assertSame(50.0, $resTier1['typical']['residual_amount']);

        // Boundary between 1 and 2: t = dedL (100) -> falls into tier 2: residual = dedL (100)
        $resBound1_2 = $this->service->computeInsurance(['typical' => $policy], ['typical' => 1000.0], ['typical' => 100.0]);
        $this->assertSame(100.0, $resBound1_2['typical']['residual_amount']);

        // Tier 2: dedL <= t < limL -> residual = dedL
        $resTier2 = $this->service->computeInsurance(['typical' => $policy], ['typical' => 1000.0], ['typical' => 300.0]);
        $this->assertSame(100.0, $resTier2['typical']['residual_amount']);

        // Boundary between 2 and 3: t = limL (500) -> falls into tier 3: residual = t - limL + dedL = 500 - 500 + 100 = 100
        $resBound2_3 = $this->service->computeInsurance(['typical' => $policy], ['typical' => 1000.0], ['typical' => 500.0]);
        $this->assertSame(100.0, $resBound2_3['typical']['residual_amount']);

        // Tier 3: limL <= t < dedG -> residual = t - limL + dedL
        $resTier3 = $this->service->computeInsurance(['typical' => $policy], ['typical' => 1000.0], ['typical' => 700.0]);
        $this->assertSame(700.0 - 500.0 + 100.0, $resTier3['typical']['residual_amount']); // 300.0

        // Boundary between 3 and 4: t = dedG (800) -> falls into tier 4: residual = dedG - limL + dedL = 800 - 500 + 100 = 400
        $resBound3_4 = $this->service->computeInsurance(['typical' => $policy], ['typical' => 1000.0], ['typical' => 800.0]);
        $this->assertSame(400.0, $resBound3_4['typical']['residual_amount']);

        // Tier 4: dedG <= t < limG -> residual = dedG - limL + dedL
        $resTier4 = $this->service->computeInsurance(['typical' => $policy], ['typical' => 1000.0], ['typical' => 1000.0]);
        $this->assertSame(400.0, $resTier4['typical']['residual_amount']);

        // Boundary between 4 and 5: t = limG (1200) -> falls into tier 5: residual = t - limG + dedG - limL + dedL = 1200 - 1200 + 800 - 500 + 100 = 400
        $resBound4_5 = $this->service->computeInsurance(['typical' => $policy], ['typical' => 2000.0], ['typical' => 1200.0]);
        $this->assertSame(400.0, $resBound4_5['typical']['residual_amount']);

        // Tier 5: t >= limG -> residual = t - limG + dedG - limL + dedL
        $resTier5 = $this->service->computeInsurance(['typical' => $policy], ['typical' => 2000.0], ['typical' => 1500.0]);
        $this->assertSame(1500.0 - 1200.0 + 800.0 - 500.0 + 100.0, $resTier5['typical']['residual_amount']); // 700.0
    }

    #[Test]
    public function it_preserves_explicit_zero_for_control_score_complement(): void
    {
        $drivers = [
            ['driver_type' => 'count_driver', 'computation_role' => 'count', 'value' => 10.0],
            ['driver_type' => 'explicit_zero_control', 'computation_role' => 'control_score_complement', 'value' => 0.0],
            ['driver_type' => 'prob_driver', 'computation_role' => 'probability', 'value' => 1.0],
        ];

        // Even if scorecardScore is 0.75, an explicit 0.0 value must NOT fall back to 0.75
        $result = $this->service->computeFrequency($drivers, 0.75);

        // lambda = 10 * (1 - 0.0) * 1 = 10.0
        $this->assertEqualsWithDelta(10.0, $result['total_frequency'], 0.000001);
    }

    /**
     * Production defect SM-2026-001 (Bug 1): count, unit_amount and flat_fee were read from
     * the TYPICAL column for all three severities. Every role must read the column being
     * computed. The golden fixtures above carry identical count/amount values in every
     * column, which is why they never caught it.
     */
    #[Test]
    public function litigation_template_reads_every_role_from_the_severity_column_being_computed(): void
    {
        $types = [[
            'name' => 'Litigation',
            'aggregation_template' => 'litigation_probability',
            'eligible_to_transfer' => false,
            'criteria' => [
                ['computation_role' => 'count', 'typical_value' => 2, 'serious_value' => 5, 'extreme_value' => 12],
                ['computation_role' => 'unit_amount', 'typical_value' => 15000000, 'serious_value' => 30000000, 'extreme_value' => 45000000],
                ['computation_role' => 'probability', 'typical_value' => 1, 'serious_value' => 1, 'extreme_value' => 1],
            ],
        ]];

        $type = $this->service->computeImpact($types, 3.571)['types'][0];

        $this->assertEqualsWithDelta(30000000.0, $type['typical_total'], 0.000001);
        $this->assertEqualsWithDelta(150000000.0, $type['serious_total'], 0.000001);
        $this->assertEqualsWithDelta(540000000.0, $type['extreme_total'], 0.000001);
    }

    #[Test]
    public function litigation_flat_fee_and_probability_vary_by_column_independently(): void
    {
        $types = [[
            'name' => 'Litigation',
            'aggregation_template' => 'litigation_probability',
            'eligible_to_transfer' => false,
            'criteria' => [
                ['computation_role' => 'count', 'typical_value' => 2, 'serious_value' => 5, 'extreme_value' => 12],
                ['computation_role' => 'unit_amount', 'typical_value' => 15000000, 'serious_value' => 30000000, 'extreme_value' => 45000000],
                ['computation_role' => 'flat_fee', 'typical_value' => 1000000, 'serious_value' => 2000000, 'extreme_value' => 3000000],
                ['computation_role' => 'probability', 'typical_value' => 1, 'serious_value' => 0.5, 'extreme_value' => 1],
            ],
        ]];

        $type = $this->service->computeImpact($types, 1.0)['types'][0];

        $this->assertEqualsWithDelta(31000000.0, $type['typical_total'], 0.000001);   // (2*15m + 1m) * 1
        $this->assertEqualsWithDelta(76000000.0, $type['serious_total'], 0.000001);   // (5*30m + 2m) * 0.5
        $this->assertEqualsWithDelta(543000000.0, $type['extreme_total'], 0.000001);  // (12*45m + 3m) * 1
    }

    #[Test]
    public function staff_turnover_template_reads_count_and_salary_from_the_severity_column_being_computed(): void
    {
        $types = [[
            'name' => 'Staff turnover',
            'aggregation_template' => 'staff_turnover_interval',
            'eligible_to_transfer' => false,
            'criteria' => [
                ['computation_role' => 'count', 'typical_value' => 2, 'serious_value' => 5, 'extreme_value' => 12],
                ['computation_role' => 'unit_amount', 'typical_value' => 15000000, 'serious_value' => 20000000, 'extreme_value' => 25000000],
                ['computation_role' => 'duration', 'typical_value' => 1, 'serious_value' => 2, 'extreme_value' => 3],
            ],
        ]];

        // Interval pinned at 1.0 so this test isolates the column read from the interval factor.
        $type = $this->service->computeImpact($types, 1.0)['types'][0];

        $this->assertEqualsWithDelta(30000000.0, $type['typical_total'], 0.000001);   // 2 * 15m * 1
        $this->assertEqualsWithDelta(200000000.0, $type['serious_total'], 0.000001);  // 5 * 20m * 2
        $this->assertEqualsWithDelta(900000000.0, $type['extreme_total'], 0.000001);  // 12 * 25m * 3
    }
}
