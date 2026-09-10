<?php

namespace Tests\Feature\Characterisation;

use App\Models\IcaapAssessment;
use App\Models\QuantificationScenario;
use App\Models\SimulationResult;
use App\Models\SimulationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * The quantification dashboard's figures (migration Phase 5.2).
 *
 * Written when `dashboard()` — 117 lines of capital arithmetic in a controller,
 * the shape `icaap()` had before `IcaapService` — moved into
 * `QuantificationDashboardService`. There was no test of this screen at all
 * before it, on a page whose headline tiles are capital figures.
 *
 * ONE FIGURE IS DELIBERATELY CHANGED, and it is the reason to read this file.
 * The controller computed the ICAAP capital add-on as
 * `($pillar2a ?? 0) + ($pillar2b ?? 0)`, and the tile printed the result under
 * "ICAAP Capital Add-on · Pillar 2A + Pillar 2B, AS ASSESSED". A bank with no
 * assessment on file therefore read **"₦0, as assessed"** — a specific claim
 * that its capital add-on is nil, made from no data whatsoever. That is the
 * same defect WP-08 removed from the expected-shortfall tile on this very
 * screen, and from CAR on the ICAAP screen; it had simply been missed here.
 */
class QuantificationDashboardTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        Permission::findOrCreate('quantification.view');
        $this->actor->givePermissionTo('quantification.view');
    }

    /** An organisation with nothing on file says so, in every capital tile. */
    #[Test]
    public function an_organisation_with_no_assessment_reports_no_capital(): void
    {
        $figures = $this->dashboard();

        $this->assertFalse($figures['hasAssessment']);

        foreach ([
            'capitalAdequacyRatio', 'carReported', 'totalCapital', 'tier1', 'tier2',
            'pillar2aCapital', 'pillar2bCapital', 'capitalBuffer', 'totalEconomicCapital',
        ] as $key) {
            $this->assertNull($figures[$key], "{$key} must be null, not zero, with no assessment on file.");
        }

        // The minimum is still resolved and reported: it is a property of the
        // institution, not of an assessment that does not exist.
        $this->assertEqualsWithDelta(10.0, $figures['minimumCar'], 0.001);
    }

    /**
     * THE CHANGED FIGURE. The add-on covers the pillars that are on record,
     * and is absent when neither is.
     */
    #[Test]
    public function the_capital_add_on_counts_only_the_pillars_on_record(): void
    {
        $this->assessment([
            'pillar2a_credit_kobo' => 4_000_000_000_000,     // 40bn
            'pillar2a_market_kobo' => 1_000_000_000_000,     // 10bn
            'pillar2b_stress_buffer_kobo' => 500_000_000_000, // 5bn
        ]);

        $figures = $this->dashboard();

        $this->assertEqualsWithDelta(50_000_000_000, $figures['pillar2aCapital'], 0.001, 'The two components on file.');
        $this->assertEqualsWithDelta(5_000_000_000, $figures['pillar2bCapital'], 0.001);
        $this->assertEqualsWithDelta(55_000_000_000, $figures['totalEconomicCapital'], 0.001);
    }

    /** An assessment carrying neither pillar reports no add-on, not ₦0. */
    #[Test]
    public function an_assessment_with_neither_pillar_reports_no_add_on(): void
    {
        $this->assessment(['total_qualifying_capital_kobo' => 26_000_000_000_000]);

        $figures = $this->dashboard();

        $this->assertTrue($figures['hasAssessment']);
        $this->assertNull($figures['pillar2aCapital']);
        $this->assertNull($figures['pillar2bCapital']);
        $this->assertNull($figures['totalEconomicCapital'], 'Nothing recorded is not an add-on of zero.');
        $this->assertNull($figures['capitalBuffer'], 'A buffer needs every deduction.');
    }

    /**
     * CAR is computed from capital and RWA; the preparer's typed figure is a
     * stated fallback, not a silent winner.
     */
    #[Test]
    public function car_is_computed_and_its_basis_is_stated(): void
    {
        $this->assessment([
            'total_qualifying_capital_kobo' => 26_000_000_000_000,
            'total_rwa_kobo' => 200_000_000_000_000,
            'car_actual' => 12.40,
        ]);

        $figures = $this->dashboard();

        $this->assertEqualsWithDelta(13.0, $figures['capitalAdequacyRatio'], 0.001);
        $this->assertEqualsWithDelta(12.4, $figures['carReported'], 0.001);
        $this->assertSame('computed from capital / RWA', $figures['carBasis']);
    }

    /** Without RWA the typed figure is used, and the basis says so. */
    #[Test]
    public function car_falls_back_to_the_reported_figure_and_names_the_basis(): void
    {
        $this->assessment(['car_actual' => 12.40, 'total_rwa_kobo' => null]);

        $figures = $this->dashboard();

        $this->assertEqualsWithDelta(12.4, $figures['capitalAdequacyRatio'], 0.001);
        $this->assertSame('as reported', $figures['carBasis']);
    }

    /**
     * The add-on chart is labelled for the columns it reads.
     *
     * WP-08: these five were headed "Credit / Market / Operational / Liquidity
     * / Other" as though they decomposed economic capital by risk type. Four
     * are Pillar 2A columns and the fifth is the Pillar 2B stress buffer, which
     * has nothing to do with liquidity risk.
     */
    #[Test]
    public function the_add_on_chart_is_labelled_for_the_columns_it_reads(): void
    {
        $this->assessment(['pillar2a_credit_kobo' => 4_000_000_000_000]);

        $chart = $this->dashboard()['capitalByTypeData'];

        $this->assertSame(
            ['Pillar 2A — Credit', 'Pillar 2A — Market', 'Pillar 2A — Operational', 'Pillar 2A — Other', 'Pillar 2B — Stress Buffer'],
            $chart['labels'],
        );

        $this->assertEqualsWithDelta(40_000_000_000, $chart['values'][0], 0.001);
        $this->assertNull($chart['values'][1], 'An unrecorded component leaves a gap rather than drawing a zero bar.');
    }

    /**
     * Expected shortfall is null — not ₦0 — when no tail mean was stored.
     *
     * The accessor used to return VaR(99) under a comment claiming it was the
     * mean of losses beyond VaR(95). Those are different statistics.
     */
    #[Test]
    public function expected_shortfall_is_absent_rather_than_zero(): void
    {
        $run = $this->completedRun(['var_95_kobo' => 2_000_000_000_000, 'es_95_kobo' => null]);

        $figures = $this->dashboard();

        $this->assertEqualsWithDelta(20_000_000_000, $figures['var95'], 0.001);
        $this->assertNull($figures['expectedShortfall']);
        $this->assertSame(1, $figures['simulationsRun']);
        $this->assertTrue($run->exists);
    }

    /** The loss chart plots only the percentiles the run stored. */
    #[Test]
    public function the_loss_chart_plots_only_stored_percentiles(): void
    {
        $this->completedRun([
            'var_95_kobo' => 1_000_000_000_000,
            'percentile_distribution' => ['p50' => 500_000_000_000, 'p95' => 2_000_000_000_000],
        ]);

        $chart = $this->dashboard()['lossDistData'];

        $this->assertSame(['50%', '95%'], $chart['labels']);
        $this->assertEqualsWithDelta([5_000_000_000, 20_000_000_000], $chart['values'], 0.001);
    }

    /** Counts follow the register and the runs. */
    #[Test]
    public function the_counters_follow_the_register(): void
    {
        $this->scenario(['status' => 'active']);
        $this->scenario(['status' => 'archived']);

        $this->assertSame(1, $this->dashboard()['activeScenarios']);
    }

    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private function dashboard(): array
    {
        return $this->actingAs($this->actor)
            ->get(route('risk.quantification.dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Quantification/Dashboard'))
            ->inertiaProps();
    }

    private function assessment(array $attributes = []): IcaapAssessment
    {
        return IcaapAssessment::create(array_merge([
            'organization_id' => $this->organization->id,
            'period' => '2026-Q2',
            'status' => 'draft',
            'prepared_by' => $this->actor->id,
        ], $attributes));
    }

    private function completedRun(array $aggregate = []): SimulationRun
    {
        $run = SimulationRun::create([
            'organization_id' => $this->organization->id,
            'simulation_reference' => 'SIM-'.now()->year.'-001',
            'status' => 'completed',
            'iterations' => 10_000,
            'initiated_by' => $this->actor->id,
            'completed_at' => now(),
        ]);

        SimulationResult::create(array_merge([
            'simulation_run_id' => $run->id,
            'result_type' => 'aggregate',
        ], $aggregate));

        return $run->fresh();
    }

    private function scenario(array $attributes = []): QuantificationScenario
    {
        return QuantificationScenario::create(array_merge([
            'organization_id' => $this->organization->id,
            'scenario_reference' => 'SCN-'.now()->year.'-'.str_pad((string) (QuantificationScenario::count() + 1), 3, '0', STR_PAD_LEFT),
            'scenario_type' => QuantificationScenario::DEFAULT_TYPE,
            'name' => 'Scenario',
            'status' => 'active',
            'created_by' => $this->actor->id,
        ], $attributes));
    }
}
