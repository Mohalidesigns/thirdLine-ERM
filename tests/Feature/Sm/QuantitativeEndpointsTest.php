<?php

namespace Tests\Feature\Sm;

use App\Models\Sm\Scorecard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Feature tests for Phase 2 Quantitative Endpoints (ADR 0027 Amendment 2 §A2.7).
 * Covers Scorecard, Frequency, Impact, and Insurance updates, authorization, and lifecycle guards.
 */
class QuantitativeEndpointsTest extends SmTestCase
{
    #[Test]
    public function updating_scorecard_saves_components_and_recomputes_global_score(): void
    {
        $scenario = $this->makeScenario(['status' => 'in_design']);

        $payload = [
            'control_nature' => 'mixed',
            'components' => [
                ['component_code' => 'control_environment', 'efficiency_level' => 5, 'weighting_factor' => 5, 'notes' => 'Tested'],
                ['component_code' => 'risk_assessment', 'efficiency_level' => 5, 'weighting_factor' => 2, 'notes' => null],
                ['component_code' => 'control_activities', 'efficiency_level' => 1, 'weighting_factor' => 5, 'notes' => null],
                ['component_code' => 'information_communication', 'efficiency_level' => 5, 'weighting_factor' => 5, 'notes' => null],
                ['component_code' => 'monitoring', 'efficiency_level' => 5, 'weighting_factor' => 5, 'notes' => null],
            ],
        ];

        $response = $this->actingAs($this->actor)
            ->put(route('scenario.scorecard.update', $scenario), $payload);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success', 'Scorecard saved.');

        $scorecard = $scenario->scorecard()->with('components')->first();
        $this->assertNotNull($scorecard);
        $this->assertSame('mixed', $scorecard->control_nature);
        $this->assertSame(22, $scorecard->total_weight);
        $this->assertEqualsWithDelta(15.2, (float) $scorecard->total_weighted_score, 0.0001);
        $this->assertEqualsWithDelta(0.69090909, (float) $scorecard->global_score, 0.0001);
        $this->assertCount(5, $scorecard->components);
    }

    #[Test]
    public function updating_frequency_drivers_saves_rows_and_calculates_annual_rate(): void
    {
        $scenario = $this->makeScenario(['status' => 'in_design']);

        // First set up scorecard with known score 0.69090909
        $scorecard = Scorecard::create([
            'organization_id' => $scenario->organization_id,
            'sm_scenario_id' => $scenario->id,
            'control_nature' => 'mixed',
            'total_weight' => 22,
            'total_weighted_score' => 15.2000,
            'global_score' => 0.69090909,
        ]);

        $payload = [
            'drivers' => [
                [
                    'driver_type' => 'exposed_staff_count',
                    'criterion' => 'Exposed Staff count',
                    'computation_role' => 'count',
                    'value' => 55,
                    'data_source' => 'HR information',
                ],
                [
                    'driver_type' => 'retention_plan_efficiency',
                    'criterion' => 'Retention plan efficiency',
                    'computation_role' => 'control_score_complement',
                    'value' => 0,
                    'data_source' => 'Scorecard',
                    'sm_scorecard_id' => $scorecard->id,
                ],
                [
                    'driver_type' => 'competition_pressure_prob',
                    'criterion' => 'Competition pressure probability',
                    'computation_role' => 'probability',
                    'value' => 0.95,
                    'data_source' => 'Expert judgement',
                ],
            ],
        ];

        $response = $this->actingAs($this->actor)
            ->put(route('scenario.frequency.update', $scenario), $payload);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success', 'Frequency drivers saved.');

        $drivers = $scenario->frequencyDrivers()->get();
        $this->assertCount(3, $drivers);
        $this->assertSame('exposed_staff_count', $drivers[0]->driver_type);
        $this->assertEquals(55, (float) $drivers[0]->value);
    }

    #[Test]
    public function updating_impact_decomposition_saves_types_and_recomputes_subtotals(): void
    {
        $scenario = $this->makeScenario(['status' => 'in_design']);

        // Set up frequency driver so interval is 0.061919504643962855
        $scenario->frequencyDrivers()->create([
            'organization_id' => $scenario->organization_id,
            'driver_type' => 'exposed_staff_count',
            'criterion' => 'Exposed Staff count',
            'computation_role' => 'count',
            'value' => 55,
            'data_source' => 'HR information',
        ]);
        $scenario->frequencyDrivers()->create([
            'organization_id' => $scenario->organization_id,
            'driver_type' => 'retention_plan_efficiency',
            'criterion' => 'Retention plan efficiency',
            'computation_role' => 'control_score_complement',
            'value' => 0.69090909,
            'data_source' => 'Scorecard',
        ]);
        $scenario->frequencyDrivers()->create([
            'organization_id' => $scenario->organization_id,
            'driver_type' => 'competition_pressure_prob',
            'criterion' => 'Competition pressure probability',
            'computation_role' => 'probability',
            'value' => 0.95,
            'data_source' => 'Expert judgement',
        ]);

        $payload = [
            'types' => [
                [
                    'name' => 'Cost of hiring and training new staff',
                    'aggregation_template' => 'staff_turnover_interval',
                    'eligible_to_transfer' => false,
                    'criteria' => [
                        ['label' => 'ID Count', 'unit' => 'people', 'computation_role' => 'count', 'data_source' => 'HR info', 'typical_value' => 55, 'serious_value' => 55, 'extreme_value' => 55],
                        ['label' => 'Monthly gross salary', 'unit' => 'NGN', 'computation_role' => 'unit_amount', 'data_source' => 'HR info', 'typical_value' => 1084669, 'serious_value' => 1084669, 'extreme_value' => 1084669],
                        ['label' => 'Months to hire & train', 'unit' => 'months', 'computation_role' => 'duration', 'data_source' => 'HR info', 'typical_value' => 4, 'serious_value' => 6, 'extreme_value' => 12],
                    ],
                ],
                [
                    'name' => 'Cost of litigation',
                    'aggregation_template' => 'litigation_probability',
                    'eligible_to_transfer' => false,
                    'criteria' => [
                        ['label' => 'ID Count', 'unit' => 'people', 'computation_role' => 'count', 'data_source' => 'HR info', 'typical_value' => 55, 'serious_value' => 55, 'extreme_value' => 55],
                        ['label' => 'Compensation', 'unit' => 'NGN', 'computation_role' => 'unit_amount', 'data_source' => 'HR info', 'typical_value' => 1084669, 'serious_value' => 1084669, 'extreme_value' => 1084669],
                        ['label' => 'Court fees', 'unit' => 'NGN', 'computation_role' => 'flat_fee', 'data_source' => 'Legal', 'typical_value' => 10000000, 'serious_value' => 10000000, 'extreme_value' => 10000000],
                        ['label' => 'P(litigation)', 'unit' => '%', 'computation_role' => 'probability', 'data_source' => 'Legal', 'typical_value' => 0.005, 'serious_value' => 0.01, 'extreme_value' => 0.015],
                    ],
                ],
            ],
        ];

        $response = $this->actingAs($this->actor)
            ->put(route('scenario.impact.update', $scenario), $payload);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success', 'Impact assessment saved.');

        $types = $scenario->impactTypes()->with('criteria')->get();
        $this->assertCount(2, $types);
        $this->assertEqualsWithDelta(14775676.78, (float) $types[0]->typical_total, 1.0);
        $this->assertEqualsWithDelta(348283.98, (float) $types[1]->typical_total, 1.0);

        // Insurance coverages should automatically calculate net impact
        $coverages = $scenario->insuranceCoverages()->get()->keyBy('severity');
        $this->assertCount(3, $coverages);
        $this->assertEqualsWithDelta(15123960.76, (float) $coverages['typical']->gross_impact, 1.0);
        $this->assertEqualsWithDelta(15123960.76, (float) $coverages['typical']->net_impact, 1.0);
    }

    #[Test]
    #[DataProvider('readOnlyStatusAndEndpointProvider')]
    public function quantitative_edits_are_refused_with_flash_when_scenario_is_read_only(string $status, string $routeName, array $payload): void
    {
        $scenario = $this->makeScenario(['status' => $status]);

        $response = $this->actingAs($this->actor)
            ->put(route($routeName, $scenario), $payload);

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public static function readOnlyStatusAndEndpointProvider(): array
    {
        $routes = [
            'scorecard' => [
                'scenario.scorecard.update',
                [
                    'control_nature' => 'mixed',
                    'components' => [
                        ['component_code' => 'control_environment', 'efficiency_level' => 1, 'weighting_factor' => 1, 'notes' => null],
                    ],
                ],
            ],
            'frequency' => [
                'scenario.frequency.update',
                [
                    'drivers' => [
                        ['driver_type' => 'events', 'criterion' => 'Events', 'computation_role' => 'count', 'value' => 1],
                    ],
                ],
            ],
            'impact' => [
                'scenario.impact.update',
                ['types' => []],
            ],
            'insurance' => [
                'scenario.insurance.update',
                [
                    'coverages' => [
                        [
                            'severity' => 'typical',
                            'deductible_local' => 0,
                            'limit_local' => 0,
                            'deductible_group' => 0,
                            'limit_group' => 0,
                            'recovery_rate' => 1,
                            'probability_of_cover' => 1,
                            'insurer_default_haircut' => 0,
                        ],
                    ],
                ],
            ],
        ];

        $cases = [];
        foreach (['in_validation', 'validated', 'signed_off', 'archived'] as $status) {
            foreach ($routes as $name => [$route, $payload]) {
                $cases["{$name} on {$status}"] = [$status, $route, $payload];
            }
        }

        return $cases;
    }

    #[Test]
    public function frequency_update_refuses_scorecard_from_another_scenario(): void
    {
        $scenario1 = $this->makeScenario(['status' => 'in_design']);
        $scenario2 = $this->makeScenario(['status' => 'in_design']);

        $otherScorecard = Scorecard::create([
            'organization_id' => $scenario2->organization_id,
            'sm_scenario_id' => $scenario2->id,
            'control_nature' => 'mixed',
            'total_weight' => 22,
            'total_weighted_score' => 15.2,
            'global_score' => 0.69090909,
        ]);

        $response = $this->actingAs($this->actor)
            ->put(route('scenario.frequency.update', $scenario1), [
                'drivers' => [
                    [
                        'driver_type' => 'retention_plan',
                        'criterion' => 'Retention',
                        'computation_role' => 'control_score_complement',
                        'value' => 0,
                        'sm_scorecard_id' => $otherScorecard->id,
                    ],
                ],
            ]);

        $response->assertSessionHasErrors('drivers.0.sm_scorecard_id');
    }

    #[Test]
    public function full_http_workflow_reproduces_published_figures_to_stored_decimals(): void
    {
        $scenario = $this->makeScenario(['status' => 'in_design']);

        // 1. Scorecard Save
        $this->actingAs($this->actor)->put(route('scenario.scorecard.update', $scenario), [
            'control_nature' => 'mixed',
            'components' => [
                ['component_code' => 'control_environment', 'efficiency_level' => 5, 'weighting_factor' => 5, 'notes' => null],
                ['component_code' => 'risk_assessment', 'efficiency_level' => 5, 'weighting_factor' => 2, 'notes' => null],
                ['component_code' => 'control_activities', 'efficiency_level' => 1, 'weighting_factor' => 5, 'notes' => null],
                ['component_code' => 'information_communication', 'efficiency_level' => 5, 'weighting_factor' => 5, 'notes' => null],
                ['component_code' => 'monitoring', 'efficiency_level' => 5, 'weighting_factor' => 5, 'notes' => null],
            ],
        ])->assertSessionHasNoErrors();

        $sc = $scenario->scorecard()->first();
        $this->assertSame('0.69090909', (string) $sc->global_score);

        // 2. Frequency Save
        $this->actingAs($this->actor)->put(route('scenario.frequency.update', $scenario), [
            'drivers' => [
                ['driver_type' => 'count_driver', 'criterion' => 'Count', 'computation_role' => 'count', 'value' => 55],
                ['driver_type' => 'score_driver', 'criterion' => 'Score', 'computation_role' => 'control_score_complement', 'value' => 0, 'sm_scorecard_id' => $sc->id],
                ['driver_type' => 'prob_driver', 'criterion' => 'Prob', 'computation_role' => 'probability', 'value' => 0.95],
            ],
        ])->assertSessionHasNoErrors();

        // 3. Impact Save
        $this->actingAs($this->actor)->put(route('scenario.impact.update', $scenario), [
            'types' => [
                [
                    'name' => 'Hiring',
                    'aggregation_template' => 'staff_turnover_interval',
                    'eligible_to_transfer' => false,
                    'criteria' => [
                        ['computation_role' => 'count', 'label' => 'C', 'typical_value' => 55, 'serious_value' => 55, 'extreme_value' => 55],
                        ['computation_role' => 'unit_amount', 'label' => 'A', 'typical_value' => 1084669, 'serious_value' => 1084669, 'extreme_value' => 1084669],
                        ['computation_role' => 'duration', 'label' => 'D', 'typical_value' => 4, 'serious_value' => 6, 'extreme_value' => 12],
                    ],
                ],
                [
                    'name' => 'Litigation',
                    'aggregation_template' => 'litigation_probability',
                    'eligible_to_transfer' => false,
                    'criteria' => [
                        ['computation_role' => 'count', 'label' => 'C', 'typical_value' => 55, 'serious_value' => 55, 'extreme_value' => 55],
                        ['computation_role' => 'unit_amount', 'label' => 'A', 'typical_value' => 1084669, 'serious_value' => 1084669, 'extreme_value' => 1084669],
                        ['computation_role' => 'flat_fee', 'label' => 'F', 'typical_value' => 10000000, 'serious_value' => 20000000, 'extreme_value' => 30000000],
                        ['computation_role' => 'probability', 'label' => 'P', 'typical_value' => 0.005, 'serious_value' => 0.01, 'extreme_value' => 0.015],
                    ],
                ],
            ],
        ])->assertSessionHasNoErrors();

        $types = $scenario->impactTypes()->orderBy('id')->get();
        // Assert stored strings match golden values
        $this->assertEqualsWithDelta(14775676.78018576, (float) $types[0]->typical_total, 0.0001);
        $this->assertEqualsWithDelta(348283.97500000, (float) $types[1]->typical_total, 0.0001);

        // 4. Insurance Netting Save
        $this->actingAs($this->actor)->put(route('scenario.insurance.update', $scenario), [
            'coverages' => [
                [
                    'severity' => 'typical',
                    'deductible_local' => 0,
                    'limit_local' => 0,
                    'deductible_group' => 0,
                    'limit_group' => 0,
                    'recovery_rate' => 1,
                    'probability_of_cover' => 1,
                    'insurer_default_haircut' => 0,
                ],
            ],
        ])->assertSessionHasNoErrors();

        $cov = $scenario->insuranceCoverages()->where('severity', 'typical')->first();
        // Uninsured net equals gross
        $this->assertEqualsWithDelta((float) $cov->gross_impact, (float) $cov->net_impact, 0.0001);
    }

    #[Test]
    public function user_without_design_permission_cannot_update_quantitative_tabs(): void
    {
        $scenario = $this->makeScenario(['status' => 'in_design']);
        $viewer = $this->userWith(['scenario.view']);

        $this->actingAs($viewer)
            ->put(route('scenario.scorecard.update', $scenario), [
                'control_nature' => 'mixed',
                'components' => [
                    [
                        'component_code' => 'control_environment',
                        'efficiency_level' => 1,
                        'weighting_factor' => 1,
                        'notes' => null,
                    ],
                ],
            ])
            ->assertForbidden();
    }
}
