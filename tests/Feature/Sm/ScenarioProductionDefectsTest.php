<?php

namespace Tests\Feature\Sm;

use App\Models\Sm\Scenario;
use PHPUnit\Framework\Attributes\Test;

/**
 * Defects found building SM-2026-001 (Ebola outbreak) on production, 2026-11-27.
 *
 * Bug 3 — the Insurance tab saved nothing and said nothing.
 * Bug 4 — impact category subtotals rendered ₦0 while the tab total was right.
 * Bug 5 — the manual control score and its "use manual score" toggle did not survive a save.
 */
class ScenarioProductionDefectsTest extends SmTestCase
{
    // ── Bug 3 ────────────────────────────────────────────────────────────────

    #[Test]
    public function insurance_terms_for_all_three_severities_persist_and_recompute_net_retained(): void
    {
        $scenario = $this->makeScenario(['status' => 'in_design']);
        $this->saveEligibleImpact($scenario);

        $terms = [
            'deductible_local' => 2000000,
            'limit_local' => 50000000,
            'deductible_group' => 0,
            'limit_group' => 0,
            'recovery_rate' => 0.8,
            'probability_of_cover' => 0.6,
            'insurer_default_haircut' => 0.05,
        ];

        // The exact shape InsuranceTab.jsx sends: a list, one row per severity.
        $this->actingAs($this->actor)->put(route('scenario.insurance.update', $scenario), [
            'coverages' => [
                ['severity' => 'typical'] + $terms,
                ['severity' => 'serious'] + $terms,
                ['severity' => 'extreme'] + $terms,
            ],
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $rows = $scenario->insuranceCoverages()->get()->keyBy('severity');
        $this->assertCount(3, $rows);

        // Transferable: 30m / 150m / 540m (count × unit × 1, eligible to transfer).
        $expected = [
            // t < limL → residual = dedL; haircut = 1 − 0.8×0.6 + 0.05 = 0.57
            'typical' => ['t' => 30000000, 'residual' => 2000000, 'net' => 2000000 + 28000000 * 0.57],
            // limL ≤ t, dedG = limG = 0 → t ≥ limG → residual = t − 0 + 0 − limL + dedL
            'serious' => ['t' => 150000000, 'residual' => 102000000, 'net' => 102000000 + 48000000 * 0.57],
            'extreme' => ['t' => 540000000, 'residual' => 492000000, 'net' => 492000000 + 48000000 * 0.57],
        ];

        foreach ($expected as $sev => $e) {
            $row = $rows[$sev];
            $this->assertEqualsWithDelta(2000000, (float) $row->deductible_local, 0.001, $sev);
            $this->assertEqualsWithDelta(50000000, (float) $row->limit_local, 0.001, $sev);
            $this->assertEqualsWithDelta(0.8, (float) $row->recovery_rate, 0.00001, $sev);
            $this->assertEqualsWithDelta(0.6, (float) $row->probability_of_cover, 0.00001, $sev);
            $this->assertEqualsWithDelta(0.05, (float) $row->insurer_default_haircut, 0.00001, $sev);
            $this->assertEqualsWithDelta($e['t'], (float) $row->transferable_amount, 0.001, $sev);
            $this->assertEqualsWithDelta($e['residual'], (float) $row->residual_amount, 0.001, $sev);
            $this->assertEqualsWithDelta($e['net'], (float) $row->net_impact, 0.001, $sev);
        }

        // …and the tab reads back what was saved, not the defaults.
        $this->actingAs($this->actor)
            ->get(route('scenario.show', ['scenario' => $scenario, 'tab' => 'insurance']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('insurance.coverages.serious.limit_local', '50000000.00000000')
                ->where('insurance.coverages.serious.recovery_rate', '0.8000'));
    }

    #[Test]
    public function an_insurance_payload_keyed_by_severity_is_refused_with_errors_not_a_silent_redirect(): void
    {
        $scenario = $this->makeScenario(['status' => 'in_design']);

        // The shape the Insurance tab used to send. It must never look like a save.
        $this->actingAs($this->actor)->put(route('scenario.insurance.update', $scenario), [
            'coverages' => [
                'typical' => ['deductible_local' => 1, 'limit_local' => 1, 'deductible_group' => 0, 'limit_group' => 0,
                    'recovery_rate' => 1, 'probability_of_cover' => 1, 'insurer_default_haircut' => 0],
            ],
        ])->assertSessionHasErrors()->assertSessionMissing('success');

        $this->assertSame(0, $scenario->insuranceCoverages()->count());
    }

    #[Test]
    public function out_of_range_insurance_input_returns_validation_errors(): void
    {
        $scenario = $this->makeScenario(['status' => 'in_design']);

        $this->actingAs($this->actor)->put(route('scenario.insurance.update', $scenario), [
            'coverages' => [[
                'severity' => 'typical', 'deductible_local' => -5, 'limit_local' => 1, 'deductible_group' => 0,
                'limit_group' => 0, 'recovery_rate' => 1.5, 'probability_of_cover' => 1, 'insurer_default_haircut' => 0,
            ]],
        ])->assertSessionHasErrors(['coverages.0.deductible_local', 'coverages.0.recovery_rate'])
            ->assertSessionMissing('success');
    }

    // ── Bug 4 ────────────────────────────────────────────────────────────────

    #[Test]
    public function impact_category_subtotals_are_in_the_payload_and_add_up_to_the_total(): void
    {
        $scenario = $this->makeScenario(['status' => 'in_design']);
        $this->saveEligibleImpact($scenario, extraCategory: true);

        $this->actingAs($this->actor)
            ->get(route('scenario.show', ['scenario' => $scenario, 'tab' => 'impact']))
            ->assertOk()
            ->assertInertia(function ($page) {
                $impact = $page->toArray()['props']['impact'];
                $this->assertCount(2, $impact['types']);

                foreach (['typical', 'serious', 'extreme'] as $sev) {
                    $sum = 0.0;
                    foreach ($impact['types'] as $type) {
                        // The key the category card renders.
                        $this->assertArrayHasKey("{$sev}_total", $type);
                        $sum += (float) $type["{$sev}_total"];
                    }
                    $this->assertGreaterThan(0.0, $sum, $sev);
                    // Exactly, to the cent: the total is the sum of the subtotals shown.
                    $this->assertSame($impact['gross_impact'][$sev], sprintf('%.2f', $sum), $sev);
                }

                // Litigation category: 30m / 150m / 540m exactly.
                $this->assertSame('30000000.00', $impact['types'][0]['typical_total']);
                $this->assertSame('150000000.00', $impact['types'][0]['serious_total']);
                $this->assertSame('540000000.00', $impact['types'][0]['extreme_total']);
            });
    }

    // ── Bug 5 (the manual control score) ─────────────────────────────────────

    #[Test]
    public function a_manual_control_score_and_its_toggle_persist_even_when_a_scorecard_exists(): void
    {
        $scenario = $this->makeScenario(['status' => 'in_design']);
        $this->saveScorecard($scenario); // global score 0.69090909

        $this->putDrivers($scenario, useScorecard: false, controlValue: 0.3553)->assertSessionHasNoErrors();

        $this->actingAs($this->actor)
            ->get(route('scenario.show', ['scenario' => $scenario, 'tab' => 'frequency']))
            ->assertOk()
            ->assertInertia(function ($page) {
                $freq = $page->toArray()['props']['frequency'];
                $control = collect($freq['drivers'])->firstWhere('computation_role', 'control_score_complement');
                $this->assertFalse($control['is_linked_to_scorecard']);
                $this->assertEqualsWithDelta(0.3553, (float) $control['value'], 0.000001);
                // λ = 400 × (1 − 0.3553) × 0.001 — the manual score, not the scorecard's 0.6909.
                $this->assertSame('0.2579', $freq['total_frequency']);
            });
    }

    #[Test]
    public function a_linked_control_driver_keeps_using_the_scorecard_after_save(): void
    {
        $scenario = $this->makeScenario(['status' => 'in_design']);
        $this->saveScorecard($scenario); // global score 0.69090909

        $this->putDrivers($scenario, useScorecard: true, controlValue: 0.1)->assertSessionHasNoErrors();

        $this->actingAs($this->actor)
            ->get(route('scenario.show', ['scenario' => $scenario, 'tab' => 'frequency']))
            ->assertInertia(function ($page) {
                $freq = $page->toArray()['props']['frequency'];
                $control = collect($freq['drivers'])->firstWhere('computation_role', 'control_score_complement');
                $this->assertTrue($control['is_linked_to_scorecard']);
                // λ = 400 × (1 − 0.690909) × 0.001 = 0.1236
                $this->assertSame('0.1236', $freq['total_frequency']);
            });
    }

    #[Test]
    public function the_link_toggle_survives_linked_to_manual_to_linked_round_trips(): void
    {
        $scenario = $this->makeScenario(['status' => 'in_design']);
        $this->saveScorecard($scenario);

        foreach ([[true, '0.1236'], [false, '0.2579'], [true, '0.1236']] as [$linked, $lambda]) {
            $this->putDrivers($scenario, useScorecard: $linked, controlValue: 0.3553)->assertSessionHasNoErrors();

            $row = $scenario->frequencyDrivers()->where('computation_role', 'control_score_complement')->first();
            $this->assertSame($linked, $row->sm_scorecard_id !== null);

            $this->actingAs($this->actor)
                ->get(route('scenario.show', ['scenario' => $scenario, 'tab' => 'frequency']))
                ->assertInertia(fn ($page) => $page
                    ->where('frequency.total_frequency', $lambda)
                    ->where('frequency.drivers.1.is_linked_to_scorecard', $linked));
        }
    }

    #[Test]
    public function linking_before_a_scorecard_exists_stays_linked_and_uses_the_manual_value_until_one_does(): void
    {
        $scenario = $this->makeScenario(['status' => 'in_design']);

        $this->putDrivers($scenario, useScorecard: true, controlValue: 0.3553)->assertSessionHasNoErrors();

        $row = $scenario->frequencyDrivers()->where('computation_role', 'control_score_complement')->first();
        $this->assertNull($row->sm_scorecard_id);
        $this->assertTrue($row->isLinkedToScorecard());

        $this->actingAs($this->actor)
            ->get(route('scenario.show', ['scenario' => $scenario, 'tab' => 'frequency']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('frequency.drivers.1.is_linked_to_scorecard', true)
                ->where('frequency.total_frequency', '0.2579'));
    }

    #[Test]
    public function a_read_only_scenario_refuses_the_link_toggle_and_changes_nothing(): void
    {
        $scenario = $this->makeScenario(['status' => 'in_design']);
        $this->saveScorecard($scenario);
        $this->putDrivers($scenario, useScorecard: true, controlValue: 0.3)->assertSessionHasNoErrors();
        $scenario->update(['status' => 'validated']);

        $this->putDrivers($scenario->fresh(), useScorecard: false, controlValue: 0.9);

        $row = $scenario->frequencyDrivers()->where('computation_role', 'control_score_complement')->first();
        $this->assertNotNull($row->sm_scorecard_id);
        $this->assertEqualsWithDelta(0.3, (float) $row->value, 0.000001);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function saveScorecard(Scenario $scenario): void
    {
        // The workbook's reference scorecard → global score 0.69090909.
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
    }

    private function putDrivers(Scenario $scenario, bool $useScorecard, float $controlValue)
    {
        return $this->actingAs($this->actor)->put(route('scenario.frequency.update', $scenario), [
            'drivers' => [
                ['driver_type' => 'exposed_staff_count', 'criterion' => 'Exposed staff', 'computation_role' => 'count',
                    'value' => 400, 'data_source' => 'HR information', 'use_scorecard' => false],
                ['driver_type' => 'retention_plan_efficiency', 'criterion' => 'Control efficiency',
                    'computation_role' => 'control_score_complement', 'value' => $controlValue,
                    'data_source' => $useScorecard ? 'Scorecard' : 'Manual assessment', 'use_scorecard' => $useScorecard],
                ['driver_type' => 'competition_pressure_prob', 'criterion' => 'Probability', 'computation_role' => 'probability',
                    'value' => 0.001, 'data_source' => 'Expert judgement', 'use_scorecard' => false],
            ],
        ]);
    }

    private function saveEligibleImpact(Scenario $scenario, bool $extraCategory = false): void
    {
        $types = [[
            'name' => 'Litigation',
            'aggregation_template' => 'litigation_probability',
            'eligible_to_transfer' => true,
            'criteria' => [
                ['label' => 'Claimants', 'unit' => 'count', 'computation_role' => 'count',
                    'typical_value' => 2, 'serious_value' => 5, 'extreme_value' => 12],
                ['label' => 'Award', 'unit' => 'NGN', 'computation_role' => 'unit_amount',
                    'typical_value' => 15000000, 'serious_value' => 30000000, 'extreme_value' => 45000000],
                ['label' => 'Probability', 'unit' => '%', 'computation_role' => 'probability',
                    'typical_value' => 1, 'serious_value' => 1, 'extreme_value' => 1],
            ],
        ]];

        if ($extraCategory) {
            $types[] = [
                'name' => 'Regulatory fines',
                'aggregation_template' => 'litigation_probability',
                'eligible_to_transfer' => false,
                'criteria' => [
                    ['label' => 'Fines', 'unit' => 'count', 'computation_role' => 'count',
                        'typical_value' => 1, 'serious_value' => 1, 'extreme_value' => 2],
                    ['label' => 'Fine', 'unit' => 'NGN', 'computation_role' => 'unit_amount',
                        'typical_value' => 100.33, 'serious_value' => 200.17, 'extreme_value' => 300.01],
                    ['label' => 'Probability', 'unit' => '%', 'computation_role' => 'probability',
                        'typical_value' => 0.3333, 'serious_value' => 0.5, 'extreme_value' => 1],
                ],
            ];
        }

        $this->actingAs($this->actor)
            ->put(route('scenario.impact.update', $scenario), ['types' => $types])
            ->assertSessionHasNoErrors();
    }
}
