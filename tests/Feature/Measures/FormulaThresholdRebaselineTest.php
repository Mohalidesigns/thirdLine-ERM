<?php

namespace Tests\Feature\Measures;

use App\Models\ApprovalRequest;
use App\Models\Measure;
use App\Models\MeasureThreshold;
use App\Models\Period;
use App\Services\FormulaEvaluationException;
use App\Services\FormulaEvaluator;
use App\Services\ThresholdRebaselineService;
use App\Support\Measures\MeasureCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\Support\CreatesMeasureFixtures;
use Tests\TestCase;

/**
 * WP-04 TASK 5 acceptance: a threshold defined as "0.5% of qualifying capital"
 * re-evaluates at period close and raises an approval task; approving writes a
 * new effective-dated band and retains the old one.
 *
 * Plus the Nigerian case the feature exists for: a 16% CPI move must move an
 * inflation-indexed naira limit, because a limit written in 2022 naira
 * classifies ordinary 2026 activity as a severe breach.
 */
class FormulaThresholdRebaselineTest extends TestCase
{
    use CreatesDomainFixtures, CreatesMeasureFixtures, RefreshDatabase;

    private Measure $exposure;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-05 09:00:00'));
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-01-05 09:00:00'));

        $this->bootDomainFixtures();
        $this->bootMeasureEngine();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->actor->assignRole('super-admin');

        $this->exposure = $this->measures()->requireMeasure(MeasureCatalog::RISK_FINANCIAL_EXPOSURE);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Carbon\Carbon::setTestNow();

        parent::tearDown();
    }

    /** Record an organisation-level input measure for a period. */
    private function recordInput(string $code, Period $period, float $value): void
    {
        $risk = $this->organisationAnchor();

        $this->measures()->record($code, $risk, $period, $value, [
            'detect_breach' => false,
            'currency_code' => $code === MeasureCatalog::MACRO_CPI_INDEX ? null : 'NGN',
        ]);
    }

    /**
     * Something in the graph to hang organisation-level figures off.
     * Capital is the bank's, not a risk's; any node serves as the anchor and
     * the formula resolves by measure, not by object.
     */
    private function organisationAnchor()
    {
        return $this->anchor ??= $this->makeRisk(['risk_code' => 'RK-ANCHOR', 'title' => 'Reporting entity anchor']);
    }

    private $anchor = null;

    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_evaluator_resolves_measure_references_without_eval(): void
    {
        $q1 = $this->quarter('2026-02-01');
        // Qualifying capital of NGN 200bn, held in kobo.
        $this->recordInput(MeasureCatalog::CAPITAL_TOTAL_QUALIFYING, $q1, 20_000_000_000_000);

        $result = app(FormulaEvaluator::class)->evaluate(
            "0.005 * @measure('capital.total_qualifying')",
            ['organization_id' => $this->organization->id, 'period_id' => $q1->id]
        );

        $this->assertSame(100_000_000_000.0, $result, '0.5% of NGN 200bn is NGN 1bn, in kobo.');
    }

    #[Test]
    public function the_capital_helper_is_sugar_for_the_same_lookup(): void
    {
        $q1 = $this->quarter('2026-02-01');
        $this->recordInput(MeasureCatalog::CAPITAL_TOTAL_QUALIFYING, $q1, 20_000_000_000_000);

        $context = ['organization_id' => $this->organization->id, 'period_id' => $q1->id];
        $evaluator = app(FormulaEvaluator::class);

        $this->assertSame(
            $evaluator->evaluate("0.005 * @measure('capital.total_qualifying')", $context),
            $evaluator->evaluate('0.005 * @capital()', $context)
        );
    }

    #[Test]
    public function a_missing_input_throws_rather_than_evaluating_to_zero(): void
    {
        $q1 = $this->quarter('2026-02-01');

        $this->expectException(FormulaEvaluationException::class);
        $this->expectExceptionMessageMatches('/No actual value recorded/');

        app(FormulaEvaluator::class)->evaluate(
            "0.005 * @measure('capital.total_qualifying')",
            ['organization_id' => $this->organization->id, 'period_id' => $q1->id]
        );
    }

    #[Test]
    public function an_expression_cannot_reach_php(): void
    {
        $this->expectException(FormulaEvaluationException::class);

        app(FormulaEvaluator::class)->evaluate(
            'system("id")',
            ['organization_id' => $this->organization->id, 'period_id' => $this->quarter('2026-02-01')->id]
        );
    }

    #[Test]
    public function closing_a_period_raises_an_approval_when_a_capital_linked_limit_has_drifted(): void
    {
        $q1 = $this->quarter('2026-02-01');

        // Capital has grown from the NGN 200bn the band was set against to
        // NGN 260bn, so 0.5% of it is 30% higher than the band in force.
        $this->recordInput(MeasureCatalog::CAPITAL_TOTAL_QUALIFYING, $q1, 26_000_000_000_000);

        $this->attachThreshold($this->exposure, [
            ['code' => 'green', 'label' => 'Within limit', 'color' => '#16A34A',
                'min' => null, 'max' => 100_000_000_000, 'max_formula' => "0.005 * @measure('capital.total_qualifying')"],
            ['code' => 'red', 'label' => 'Over limit', 'color' => '#DC2626',
                'min' => 100_000_000_000, 'max' => null, 'min_formula' => "0.005 * @measure('capital.total_qualifying')"],
        ]);

        $raised = app(ThresholdRebaselineService::class)->review($q1);

        $this->assertSame(1, $raised['examined']);
        $this->assertSame(1, $raised['drifted']);
        $this->assertSame(1, $raised['raised']);

        $approval = ApprovalRequest::where('action', ThresholdRebaselineService::ACTION)->firstOrFail();

        $this->assertSame('pending', $approval->status);
        $this->assertSame('measure_threshold', $approval->entity_type);
        $this->assertEqualsWithDelta(
            130_000_000_000.0,
            collect($approval->payload['proposed_bands'])->firstWhere('code', 'red')['min'],
            0.01
        );

        // Nothing has moved yet: raising a task is not changing a limit.
        $this->assertEquals(
            100_000_000_000,
            MeasureThreshold::whereNull('effective_to')->firstOrFail()->bands[1]['min']
        );
    }

    #[Test]
    public function a_move_inside_tolerance_raises_nothing(): void
    {
        config(['measures.rebaseline_tolerance' => 0.05]);

        $q1 = $this->quarter('2026-02-01');
        // 0.5% of this is 102bn — 2% away from the 100bn band in force.
        $this->recordInput(MeasureCatalog::CAPITAL_TOTAL_QUALIFYING, $q1, 20_400_000_000_000);

        $this->attachThreshold($this->exposure, [
            ['code' => 'red', 'label' => 'Over limit', 'color' => '#DC2626',
                'min' => 100_000_000_000, 'max' => null, 'min_formula' => '0.005 * @capital()'],
        ]);

        $result = app(ThresholdRebaselineService::class)->review($q1);

        $this->assertSame(0, $result['drifted']);
        $this->assertSame(0, ApprovalRequest::where('action', ThresholdRebaselineService::ACTION)->count());
    }

    #[Test]
    public function an_unevaluable_limit_is_skipped_not_moved(): void
    {
        $q1 = $this->quarter('2026-02-01');
        // Capital for the period has not been entered.

        $this->attachThreshold($this->exposure, [
            ['code' => 'red', 'label' => 'Over limit', 'color' => '#DC2626',
                'min' => 100_000_000_000, 'max' => null, 'min_formula' => '0.005 * @capital()'],
        ]);

        $result = app(ThresholdRebaselineService::class)->review($q1);

        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $result['raised']);
    }

    #[Test]
    public function a_sixteen_percent_cpi_move_re_baselines_a_naira_limit_end_to_end(): void
    {
        // The scenario: a NGN 50m reporting limit written against January 2026
        // prices. A year of ~16% headline inflation later, 50m of 2026 naira is
        // 58m of 2027 naira, and leaving the limit alone means ordinary
        // activity starts reading as a breach.
        $baseMonth = $this->month('2026-01-15');
        $laterMonth = $this->month('2027-01-15');

        $this->recordInput(MeasureCatalog::MACRO_CPI_INDEX, $baseMonth, 100.0);
        $this->recordInput(MeasureCatalog::MACRO_CPI_INDEX, $laterMonth, 116.0);

        $formula = "5000000000 * @cpi_index('2027-01', '2026-01')";

        $threshold = $this->attachThreshold($this->exposure, [
            ['code' => 'green', 'label' => 'Within limit', 'color' => '#16A34A',
                'min' => null, 'max' => 5_000_000_000, 'max_formula' => $formula],
            ['code' => 'red', 'label' => 'Over limit', 'color' => '#DC2626',
                'min' => 5_000_000_000, 'max' => null, 'min_formula' => $formula],
        ]);

        $service = app(ThresholdRebaselineService::class);
        $result = $service->review($laterMonth);

        $this->assertSame(1, $result['raised']);

        $approval = ApprovalRequest::where('action', ThresholdRebaselineService::ACTION)->firstOrFail();
        $change = collect($approval->payload['changes'])->firstWhere('bound', 'min');

        $this->assertEqualsWithDelta(5_800_000_000.0, $change['computed'], 0.01, 'NGN 50m indexed by 16%.');
        $this->assertEqualsWithDelta(0.16, $change['relative_change'], 0.0001);

        /* ---- approval ---- */

        $replacement = $service->apply($approval, $this->actor, 'Annual CPI re-baselining');

        $this->assertNotNull($replacement->supersedes_id);
        $this->assertSame($threshold->id, $replacement->supersedes_id);
        $this->assertEqualsWithDelta(
            5_800_000_000.0,
            collect($replacement->bands)->firstWhere('code', 'red')['min'],
            0.01
        );
        $this->assertSame($this->actor->id, $replacement->approved_by);

        // Effective the day after the period it was computed for.
        $this->assertSame(
            $laterMonth->end_date->addDay()->toDateString(),
            $replacement->effective_from->toDateString()
        );

        /* ---- the old band is retained, not overwritten ---- */

        $threshold->refresh();
        $this->assertEqualsWithDelta(
            5_000_000_000.0,
            collect($threshold->bands)->firstWhere('code', 'red')['min'],
            0.01,
            'The superseded band must keep the number that was actually in force.'
        );
        $this->assertSame($laterMonth->end_date->toDateString(), $threshold->effective_to->toDateString());

        /* ---- lookups resolve to the right band on either side of the cut ---- */

        $before = $this->measures()->activeThreshold($this->exposure, null, $laterMonth->end_date->toDateString());
        $after = $this->measures()->activeThreshold($this->exposure, null, $laterMonth->end_date->addDays(5)->toDateString());

        $this->assertSame($threshold->id, $before->id);
        $this->assertSame($replacement->id, $after->id);

        $this->assertSame('approved', $approval->fresh()->status);
    }

    #[Test]
    public function rejecting_leaves_the_limit_where_it_was(): void
    {
        $q1 = $this->quarter('2026-02-01');
        $this->recordInput(MeasureCatalog::CAPITAL_TOTAL_QUALIFYING, $q1, 26_000_000_000_000);

        $threshold = $this->attachThreshold($this->exposure, [
            ['code' => 'red', 'label' => 'Over limit', 'color' => '#DC2626',
                'min' => 100_000_000_000, 'max' => null, 'min_formula' => '0.005 * @capital()'],
        ]);

        $service = app(ThresholdRebaselineService::class);
        $service->review($q1);

        $approval = ApprovalRequest::where('action', ThresholdRebaselineService::ACTION)->firstOrFail();
        $service->reject($approval, $this->actor, 'Board has not approved a higher limit.');

        $this->assertSame('rejected', $approval->fresh()->status);
        $this->assertSame(1, MeasureThreshold::count());
        $this->assertNull($threshold->fresh()->effective_to);
        $this->assertEquals(100_000_000_000, $threshold->fresh()->bands[0]['min']);
    }

    #[Test]
    public function a_second_review_does_not_stack_a_duplicate_approval(): void
    {
        $q1 = $this->quarter('2026-02-01');
        $this->recordInput(MeasureCatalog::CAPITAL_TOTAL_QUALIFYING, $q1, 26_000_000_000_000);

        $this->attachThreshold($this->exposure, [
            ['code' => 'red', 'label' => 'Over limit', 'color' => '#DC2626',
                'min' => 100_000_000_000, 'max' => null, 'min_formula' => '0.005 * @capital()'],
        ]);

        $service = app(ThresholdRebaselineService::class);
        $service->review($q1);
        $second = $service->review($q1);

        $this->assertSame(0, $second['raised']);
        $this->assertSame(1, ApprovalRequest::where('action', ThresholdRebaselineService::ACTION)->count());
    }

    #[Test]
    public function approving_never_rewrites_the_band_in_place_through_the_approval_payload(): void
    {
        // ApprovalService::approve() applies its payload to the entity with
        // update(). The re-baselining payload is named `proposed_bands`
        // precisely so mass assignment cannot touch `bands` — this is the
        // regression guard for that.
        $q1 = $this->quarter('2026-02-01');
        $this->recordInput(MeasureCatalog::CAPITAL_TOTAL_QUALIFYING, $q1, 26_000_000_000_000);

        $threshold = $this->attachThreshold($this->exposure, [
            ['code' => 'red', 'label' => 'Over limit', 'color' => '#DC2626',
                'min' => 100_000_000_000, 'max' => null, 'min_formula' => '0.005 * @capital()'],
        ]);

        $service = app(ThresholdRebaselineService::class);
        $service->review($q1);

        $approval = ApprovalRequest::where('action', ThresholdRebaselineService::ACTION)->firstOrFail();

        $this->assertArrayNotHasKey('bands', $approval->payload);
        $this->assertArrayHasKey('proposed_bands', $approval->payload);

        $service->apply($approval, $this->actor);

        $this->assertEquals(100_000_000_000, $threshold->fresh()->bands[0]['min']);
    }
}
