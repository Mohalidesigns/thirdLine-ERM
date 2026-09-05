<?php

namespace Tests\Feature\Characterisation;

use App\Models\IcaapAssessment;
use App\Models\Issue;
use App\Models\KeyRiskIndicator;
use App\Models\Organization;
use App\Models\QuantificationScenario;
use App\Models\QuantificationSetting;
use App\Models\SimulationResult;
use App\Models\SimulationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * The four quantification reports (migration Phase 5.2).
 *
 * Written against the RUNNING BLADE SCREENS before `QuantificationReportService`
 * existed, and kept permanently. These four are what a bank prints for its
 * board and files with the CBN, so the extraction has to leave every figure
 * exactly where it was.
 *
 * The reports share the ICAAP screen's arithmetic — resolveMinimumCar,
 * capitalRatioPercent, naira, stressImpactRows — and [[IcaapCharacterisationTest]]
 * already pins that. What is pinned HERE is what only the reports do:
 *
 *   - Capital Adequacy: Pillar 1 computed from the resolved minimum, the
 *     partial-total rule on Pillar 2A and headroom, CAR surplus and variance.
 *   - Stress Testing: rows come from the DELIBERATELY BOUND run and nothing
 *     else, and the tenant's own stress scenarios are flagged in or out of it.
 *   - Risk Contribution: the basis flags, which are what stop an ordinal
 *     residual-score total being printed with a naira sign.
 *   - Regulatory Pack: the resolved minimum reaching the checklist line, and
 *     the CAR basis being stated rather than silently chosen.
 */
class QuantificationReportsCharacterisationTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        Permission::findOrCreate('quantification.view');
        $this->actor->givePermissionTo('quantification.view');
    }

    /* ================================================================== */
    /*  Capital Adequacy Summary */
    /* ================================================================== */

    /**
     * Pillar 1 is the resolved minimum applied to RWA — not, as it was before
     * WP-08, the Pillar 2A columns printed under a Pillar 1 heading.
     */
    #[Test]
    public function capital_adequacy_computes_pillar_one_from_the_resolved_minimum(): void
    {
        $this->assessment([
            'total_qualifying_capital_kobo' => 26_000_000_000_000,   // 260bn
            'total_rwa_kobo' => 200_000_000_000_000,                 // 2trn
            'cbn_minimum_car' => 15.0,
        ]);

        $d = $this->capitalAdequacy()['d'];

        $this->assertSame(15.0, $d->car_required);
        $this->assertSame(300_000_000_000.0, $d->pillar1_requirement, '15% of NGN 2trn.');
        $this->assertSame(13.0, $d->car_computed);
        $this->assertSame(-2.0, $d->car_surplus, 'Computed CAR less the minimum, and it may be negative.');
    }

    /** The minimum is resolved, not the hardcoded 10 that `car_required ?? 10` produced. */
    #[Test]
    public function capital_adequacy_falls_back_to_the_organisation_minimum(): void
    {
        QuantificationSetting::create([
            'organization_id' => $this->organization->id,
            'cbn_minimum_car' => 15.0,
        ]);

        // No assessment at all: the org-wide figure is what is left to resolve to.
        $d = $this->capitalAdequacy()['d'];

        $this->assertSame(15.0, $d->car_required);
        $this->assertFalse($this->capitalAdequacy()['hasData']);
    }

    /** An unrecorded balance sheet is not a balance sheet of zeroes. */
    #[Test]
    public function capital_adequacy_leaves_absent_inputs_absent(): void
    {
        $this->assessment([
            'total_qualifying_capital_kobo' => null,
            'total_rwa_kobo' => null,
        ]);

        $d = $this->capitalAdequacy()['d'];

        foreach (['total_capital', 'total_rwa', 'car_computed', 'cet1_ratio', 'tier1_ratio', 'pillar1_requirement', 'car_surplus', 'headroom'] as $key) {
            $this->assertNull($d->{$key}, "{$key} must be null, not zero, when nothing was recorded.");
        }
    }

    /** Pillar 2A totals only the components on file; none at all is null, not zero. */
    #[Test]
    public function capital_adequacy_totals_only_the_recorded_pillar_two_a_components(): void
    {
        $this->assessment([
            'pillar2a_credit_kobo' => 4_000_000_000_000,
            'pillar2a_market_kobo' => 1_000_000_000_000,
            'pillar2a_operational_kobo' => null,
            'pillar2a_other_kobo' => null,
        ]);

        $this->assertSame(50_000_000_000.0, $this->capitalAdequacy()['d']->total_pillar2a);

        $this->flushAssessments();
        $this->assessment([]);

        $this->assertNull($this->capitalAdequacy()['d']->total_pillar2a, 'No component on file is unknown, not zero.');
    }

    /**
     * Headroom is capital less EVERY deduction, or nothing at all. A partial
     * total would read as more headroom than the bank has.
     */
    #[Test]
    public function capital_adequacy_headroom_needs_every_deduction(): void
    {
        $this->assessment([
            'total_qualifying_capital_kobo' => 26_000_000_000_000,   // 260bn
            'total_rwa_kobo' => 200_000_000_000_000,                 // 2trn
            'cbn_minimum_car' => 10.0,                               // P1 = 200bn
            'pillar2a_credit_kobo' => 1_000_000_000_000,             // 10bn
            'pillar2b_stress_buffer_kobo' => null,                   // missing
        ]);

        $this->assertNull($this->capitalAdequacy()['d']->headroom);

        $this->flushAssessments();
        $this->assessment([
            'total_qualifying_capital_kobo' => 26_000_000_000_000,
            'total_rwa_kobo' => 200_000_000_000_000,
            'cbn_minimum_car' => 10.0,
            'pillar2a_credit_kobo' => 1_000_000_000_000,
            'pillar2b_stress_buffer_kobo' => 500_000_000_000,        // 5bn
        ]);

        // 260 − 200 − 10 − 5 = 45bn.
        $this->assertSame(45_000_000_000.0, $this->capitalAdequacy()['d']->headroom);
    }

    /** The preparer's CAR is reconciled against the computed one, not merged with it. */
    #[Test]
    public function capital_adequacy_reconciles_the_reported_car(): void
    {
        $this->assessment([
            'total_qualifying_capital_kobo' => 26_000_000_000_000,
            'total_rwa_kobo' => 200_000_000_000_000,
            'car_actual' => 12.40,
        ]);

        $d = $this->capitalAdequacy()['d'];

        $this->assertSame(13.0, $d->car_computed);
        $this->assertSame(12.4, $d->car_reported);
        $this->assertSame(0.6, $d->car_variance);
        $this->assertTrue($d->car_variance_material, 'Above the 0.05 reconciliation tolerance.');
    }

    /* ================================================================== */
    /*  Stress Testing */
    /* ================================================================== */

    /**
     * THE DEFECT WP-08 REMOVED, PINNED SO IT CANNOT COME BACK: when no run is
     * bound, the report used to pick up whatever simulation finished most
     * recently — which is how a single-scenario operational calibration was
     * presented to a board as a macroeconomic stress test.
     */
    #[Test]
    public function stress_testing_never_falls_back_to_the_latest_completed_run(): void
    {
        $unrelated = $this->completedRun(['var_99_kobo' => 5_000_000_000_000]);

        $this->assessment([
            'total_qualifying_capital_kobo' => 26_000_000_000_000,
            'total_rwa_kobo' => 200_000_000_000_000,
            'stress_simulation_id' => null,
        ]);

        $data = $this->stressTesting();

        $this->assertFalse($data['hasBoundRun']);
        $this->assertFalse($data['hasRows']);
        $this->assertNull($data['stressSim']);
        $this->assertCount(0, $data['rows']);
        $this->assertTrue($unrelated->exists, 'A completed run exists and is deliberately ignored.');
    }

    /** A run bound to another tenant's assessment is not this tenant's run. */
    #[Test]
    public function stress_testing_reads_only_this_organisations_bound_run(): void
    {
        $otherBank = Organization::create([
            'name' => 'Other Bank PLC',
            'short_name' => 'OTHB',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        $foreignRun = SimulationRun::create([
            'organization_id' => $otherBank->id,
            'simulation_reference' => 'SIM-FOREIGN',
            'status' => 'completed',
            'iterations' => 10_000,
            'initiated_by' => $this->actor->id,
            'completed_at' => now(),
        ]);

        $this->assessment(['stress_simulation_id' => $foreignRun->id]);

        $this->assertFalse($this->stressTesting()['hasBoundRun']);
    }

    /**
     * One row per confidence level the bound run genuinely stored, each with
     * capital impact, capital after, CAR after and shortfall derived from the
     * balance sheet — never the five hardcoded CAR drops of the old report.
     */
    #[Test]
    public function stress_testing_derives_every_row_from_the_bound_run_and_the_balance_sheet(): void
    {
        $run = $this->completedRun([
            'var_95_kobo' => 2_000_000_000_000,      // 20bn
            'var_99_kobo' => 6_000_000_000_000,      // 60bn
        ]);

        $this->assessment([
            'total_qualifying_capital_kobo' => 26_000_000_000_000,   // 260bn
            'total_rwa_kobo' => 200_000_000_000_000,                 // 2trn
            'cbn_minimum_car' => 10.0,
            'stress_simulation_id' => $run->id,
        ]);

        $data = $this->stressTesting();

        $this->assertTrue($data['hasBoundRun']);
        $this->assertTrue($data['hasRows']);

        // keyBy() would truncate 99.9 to the array key 99 — PHP float keys —
        // so the levels are asserted as a list and the rows read positionally.
        $this->assertSame([95.0, 99.0], $data['rows']->pluck('confidence')->all(),
            'One row per level the run stored, and no row for a level it did not.');

        $rows = $data['rows']->keyBy('confidence');

        // 260 − 20 = 240bn against 2trn RWA = 12.00%, above a 10% minimum.
        $this->assertSame(20_000_000_000.0, $rows[95.0]->capital_impact);
        $this->assertSame(240_000_000_000.0, $rows[95.0]->capital_after);
        $this->assertSame(12.0, $rows[95.0]->car_after);
        $this->assertSame(0.0, $rows[95.0]->shortfall);
        $this->assertTrue($rows[95.0]->meets_minimum);

        // 260 − 60 = 200bn = 10.00% exactly; required capital is 200bn, so no
        // shortfall and the verdict is a pass at the boundary.
        $this->assertSame(200_000_000_000.0, $rows[99.0]->capital_after);
        $this->assertSame(10.0, $rows[99.0]->car_after);
        $this->assertSame(0.0, $rows[99.0]->shortfall);
        $this->assertTrue($rows[99.0]->meets_minimum);
    }

    /**
     * The tenant's own stress scenarios are listed, flagged by whether the
     * bound run covered them. Both ways this schema records "stress" count.
     */
    #[Test]
    public function stress_testing_flags_which_stress_scenarios_the_bound_run_covered(): void
    {
        $inRun = $this->scenario(['scenario_reference' => 'QS-001', 'scenario_type' => 'stress', 'expected_annual_loss_kobo' => 1_500_000_000_000]);
        $outOfRun = $this->scenario(['scenario_reference' => 'QS-002', 'cbn_stress_scenario' => 'severe_recession']);
        $this->scenario(['scenario_reference' => 'QS-003', 'scenario_type' => 'single_event']);

        $run = $this->completedRun(['var_95_kobo' => 1_000_000_000_000], ['scenario_ids' => [$inRun->id]]);

        $this->assessment([
            'total_qualifying_capital_kobo' => 26_000_000_000_000,
            'total_rwa_kobo' => 200_000_000_000_000,
            'stress_simulation_id' => $run->id,
        ]);

        $scenarios = $this->stressTesting()['stressScenarios'];

        $this->assertCount(2, $scenarios, 'Only the two carrying a stress designation; the single_event one is out.');

        $byReference = $scenarios->keyBy('reference');
        $this->assertTrue($byReference['QS-001']->in_bound_run);
        $this->assertFalse($byReference['QS-002']->in_bound_run);
        $this->assertSame(15_000_000_000.0, $byReference['QS-001']->expected_annual_loss, 'Kobo in, naira out.');
        $this->assertSame($outOfRun->id, QuantificationScenario::where('scenario_reference', 'QS-002')->value('id'));
    }

    /* ================================================================== */
    /*  Risk Contribution */
    /* ================================================================== */

    /**
     * WITH a completed run the rows are Naira shares of expected annual loss,
     * and the basis flag says so — it is what stops the view printing a naira
     * sign in front of an ordinal total.
     */
    #[Test]
    public function risk_contribution_by_type_uses_the_expected_loss_basis_when_a_run_exists(): void
    {
        $scenarioA = $this->scenario(['scenario_reference' => 'QS-010', 'name' => 'Internal Fraud']);
        $scenarioB = $this->scenario(['scenario_reference' => 'QS-011', 'name' => 'System Outage']);

        $run = $this->completedRun(['expected_annual_loss_kobo' => 1_000_000_000_000]);   // 10bn total
        $this->scenarioResult($run, $scenarioA, 750_000_000_000);                          // 7.5bn
        $this->scenarioResult($run, $scenarioB, 250_000_000_000);                          // 2.5bn

        $data = $this->riskContribution();

        $this->assertSame('expected_loss', $data['byTypeBasis']);

        $rows = $data['byType'];
        $this->assertSame('Internal Fraud', $rows[0]->label, 'Sorted by value, descending.');
        $this->assertSame(7_500_000_000.0, $rows[0]->value);
        $this->assertSame(75.0, $rows[0]->share_pct);
        $this->assertSame(25.0, $rows[1]->share_pct);
        $this->assertNull($rows[0]->risks, 'An expected-loss row counts no risks.');
    }

    /**
     * WITHOUT a run the fallback is a total of RESIDUAL SCORES — ordinal
     * points on a 1-25 matrix, not Naira and not capital. The basis flag is
     * the only thing telling the view that.
     */
    #[Test]
    public function risk_contribution_falls_back_to_the_residual_score_basis(): void
    {
        $this->makeRisk(['residual_score' => 16]);
        $this->makeRisk(['residual_score' => 4]);

        $data = $this->riskContribution();

        $this->assertSame('residual_score', $data['byTypeBasis']);
        $this->assertSame('residual_score', $data['byUnitBasis'], 'By unit is ALWAYS residual score; the engine has never produced a business-unit loss distribution.');

        $row = $data['byType'][0];
        $this->assertSame('Operational Risk', $row->label);
        $this->assertSame(20.0, $row->value);
        $this->assertSame(2, $row->risks);
        $this->assertSame(100.0, $row->share_pct);

        $this->assertSame('Unassigned', $data['byUnit'][0]->label);
        $this->assertSame(20.0, $data['byUnit'][0]->value);
    }

    /** Nothing on file is an empty report that says so, not a report of zeroes. */
    #[Test]
    public function risk_contribution_with_nothing_on_file_has_no_data(): void
    {
        $data = $this->riskContribution();

        $this->assertFalse($data['hasData']);
        $this->assertNull($data['latestSim']);
    }

    /* ================================================================== */
    /*  Regulatory Compliance Pack */
    /* ================================================================== */

    /**
     * The checklist line is a compliance assertion, so the minimum in it is
     * the RESOLVED one. It used to read `car_required ?? 10` against a column
     * that does not exist, so every pack filed to the CBN claimed 10%.
     */
    #[Test]
    public function regulatory_pack_states_the_resolved_minimum_and_the_car_basis(): void
    {
        $this->assessment([
            'total_qualifying_capital_kobo' => 26_000_000_000_000,
            'total_rwa_kobo' => 200_000_000_000_000,
            'car_actual' => 12.40,
            'cbn_minimum_car' => 15.0,
        ]);

        $data = $this->regulatoryPack();
        $summary = $data['summary'];

        $this->assertSame(15.0, $summary->car_required);
        $this->assertSame(13.0, $summary->car_computed);
        $this->assertSame(12.4, $summary->car_reported);
        $this->assertSame(13.0, $summary->car_actual, 'The computed figure wins where it can be computed.');
        $this->assertSame('computed from capital / RWA', $summary->car_basis);

        $car = $data['checklist']->firstWhere('item', 'CAR above CBN minimum (15%)');
        $this->assertNotNull($car, 'The minimum is printed as resolved, not as a hardcoded 10%.');
        $this->assertSame('fail', $car['status'], '13.00% against a 15% minimum.');
    }

    /** With no RWA on file the preparer's typed CAR is used, and the pack says so. */
    #[Test]
    public function regulatory_pack_falls_back_to_the_reported_car_and_names_the_basis(): void
    {
        $this->assessment([
            'total_qualifying_capital_kobo' => 26_000_000_000_000,
            'total_rwa_kobo' => null,
            'car_actual' => 12.40,
            'cbn_minimum_car' => 10.0,
        ]);

        $summary = $this->regulatoryPack()['summary'];

        $this->assertNull($summary->car_computed);
        $this->assertSame(12.4, $summary->car_actual);
        $this->assertSame('as reported on the assessment', $summary->car_basis);
    }

    /** No capital position at all is a warning, not a fail and not a 0% CAR. */
    #[Test]
    public function regulatory_pack_treats_an_unassessable_car_as_a_warning(): void
    {
        $data = $this->regulatoryPack();

        $this->assertNull($data['summary']->car_actual);

        $statuses = collect($data['checklist'])->keyBy('item')->map(fn ($row) => $row['status']);

        $this->assertSame('warning', $statuses->first(), 'CAR cannot be assessed.');
        $this->assertSame('fail', $data['checklist']->firstWhere('item', 'ICAAP submitted this cycle')['status']);
    }

    /** The operational counts the pack files: risks, KRIs, losses and issues. */
    #[Test]
    public function regulatory_pack_counts_the_operational_position(): void
    {
        $this->assessment([]);

        $this->makeRisk(['residual_rating' => 'Critical']);
        $this->makeRisk(['residual_rating' => 'High']);
        $this->makeRisk(['residual_rating' => 'High', 'status' => 'archived']);

        $this->kri(['current_status' => 'red']);
        $this->kri(['current_status' => 'amber']);

        $this->makeLossEvent(['date_of_loss' => now()->startOfYear()->addDay()]);
        $this->makeLossEvent(['date_of_loss' => now()->subYears(2)]);

        $this->issue(['issue_status' => 'OPEN', 'regulatory_reportable' => true]);
        $this->issue(['issue_status' => 'OPEN', 'remediation_due_date' => now()->subWeek()]);
        $this->issue(['issue_status' => 'CLOSED', 'regulatory_reportable' => true]);

        $summary = $this->regulatoryPack()['summary'];

        $this->assertSame(2, $summary->active_risks, 'The archived one is out.');
        $this->assertSame(1, $summary->critical_risks);
        $this->assertSame(2, $summary->high_risks, 'residual_rating is counted regardless of status, as it always has been.');
        $this->assertSame(1, $summary->red_kris);
        $this->assertSame(1, $summary->amber_kris);
        $this->assertSame(1, $summary->loss_events_ytd, 'Year to date only.');
        $this->assertSame(2, $summary->open_issues);
        $this->assertSame(1, $summary->overdue_issues);
        $this->assertSame(1, $summary->regulatory_issues, 'The closed one is out.');
    }

    /* ================================================================== */
    /*  Fixtures and screen readers */
    /* ================================================================== */

    /** @return array<string, mixed> */
    private function screen(string $route): array
    {
        return $this->actingAs($this->actor)->get(route($route))->assertOk()->original->getData();
    }

    /** @return array<string, mixed> */
    private function capitalAdequacy(): array
    {
        return $this->screen('risk.quantification.reports.capital-adequacy');
    }

    /** @return array<string, mixed> */
    private function stressTesting(): array
    {
        return $this->screen('risk.quantification.reports.stress-testing');
    }

    /** @return array<string, mixed> */
    private function riskContribution(): array
    {
        return $this->screen('risk.quantification.reports.risk-contribution');
    }

    /** @return array<string, mixed> */
    private function regulatoryPack(): array
    {
        return $this->screen('risk.quantification.reports.regulatory-pack');
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

    /** Every report reads the LATEST assessment, so a second case needs a clean slate. */
    private function flushAssessments(): void
    {
        IcaapAssessment::query()->delete();
    }

    /**
     * A completed run with an aggregate result. $aggregate carries the VaR /
     * expected-loss columns the reports read.
     */
    private function completedRun(array $aggregate = [], array $attributes = []): SimulationRun
    {
        $run = SimulationRun::create(array_merge([
            'organization_id' => $this->organization->id,
            'simulation_reference' => 'SIM-'.str_pad((string) (SimulationRun::count() + 1), 4, '0', STR_PAD_LEFT),
            'status' => 'completed',
            'iterations' => 10_000,
            'initiated_by' => $this->actor->id,
            'completed_at' => now(),
        ], $attributes));

        SimulationResult::create(array_merge([
            'simulation_run_id' => $run->id,
            'result_type' => 'aggregate',
        ], $aggregate));

        return $run->fresh();
    }

    private function scenarioResult(SimulationRun $run, QuantificationScenario $scenario, int $expectedLossKobo): SimulationResult
    {
        return SimulationResult::create([
            'simulation_run_id' => $run->id,
            'scenario_id' => $scenario->id,
            'result_type' => 'scenario',
            'expected_annual_loss_kobo' => $expectedLossKobo,
        ]);
    }

    private function scenario(array $attributes = []): QuantificationScenario
    {
        return QuantificationScenario::create(array_merge([
            'organization_id' => $this->organization->id,
            'scenario_reference' => 'QS-'.str_pad((string) (QuantificationScenario::count() + 1), 3, '0', STR_PAD_LEFT),
            'scenario_type' => QuantificationScenario::DEFAULT_TYPE,
            'name' => 'Scenario',
            'status' => 'active',
            'created_by' => $this->actor->id,
        ], $attributes));
    }

    private function kri(array $attributes = []): KeyRiskIndicator
    {
        $n = KeyRiskIndicator::count() + 1;

        return KeyRiskIndicator::create(array_merge([
            'organization_id' => $this->organization->id,
            'kri_code' => 'KRI-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'name' => 'Indicator '.$n,
            'data_source' => 'Core banking',
            'measurement_frequency' => 'monthly',
            'unit_of_measure' => 'count',
            'created_by' => $this->actor->id,
        ], $attributes));
    }

    private function issue(array $attributes = []): Issue
    {
        $n = Issue::count() + 1;

        return Issue::create(array_merge([
            'organization_id' => $this->organization->id,
            'issue_reference' => sprintf('ISS-%04d', $n),
            'title' => 'Issue '.$n,
            'description' => 'Fixture issue '.$n,
            'issue_source' => 'internal_audit',
            'issue_category' => 'Control Weakness',
            'issue_status' => 'OPEN',
            'priority' => 'medium',
            'created_by' => $this->actor->id,
        ], $attributes));
    }
}
