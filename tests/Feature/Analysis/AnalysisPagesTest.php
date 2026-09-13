<?php

namespace Tests\Feature\Analysis;

use App\Models\TreatmentPlan;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * The four analysis screens on Inertia (migration Phase 5.1).
 *
 * The heat map grid, its axis labels and its band colours all come from the
 * organisation's SCORING PROFILE, so the page renders whatever matrix the
 * tenant configured rather than a hardcoded 5×5.
 */
class AnalysisPagesTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-15 09:00:00'));
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-08-15 09:00:00'));

        $this->bootDomainFixtures();

        Permission::findOrCreate('analysis.view');
        $this->actor->givePermissionTo('analysis.view');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Carbon\Carbon::setTestNow();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_heat_map_renders_the_tenants_own_matrix(): void
    {
        $this->makeRisk(['inherent_likelihood' => 4, 'inherent_impact' => 5, 'inherent_score' => 20, 'inherent_rating' => 'Critical']);

        $this->actingAs($this->actor)
            ->get(route('risk.analysis.heatmap'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Analysis/Heatmap')
                ->where('grid.rows', 5)
                ->where('grid.cols', 5)
                ->where('total', 1)
                ->where('viewType', 'inherent')
                // One cell per position, so a row never collapses.
                ->has('cells', 25)
                ->has('grid.bands', 4)
                ->has('movement.labels', 4)
                ->etc());
    }

    #[Test]
    public function a_heat_map_cell_carries_the_risks_inside_it(): void
    {
        $risk = $this->makeRisk([
            'title' => 'Settlement failure',
            'inherent_likelihood' => 4,
            'inherent_impact' => 5,
            'inherent_score' => 20,
            'inherent_rating' => 'Critical',
        ]);

        $props = $this->actingAs($this->actor)
            ->get(route('risk.analysis.heatmap'))
            ->assertOk()
            ->viewData('page')['props'];

        $cell = collect($props['cells'])->firstWhere(fn ($c) => $c['likelihood'] === 4 && $c['impact'] === 5);

        $this->assertSame(1, $cell['count']);
        $this->assertSame($risk->risk_code, $cell['risks'][0]['code']);
        $this->assertStringContainsString('/risk/register/', $cell['risks'][0]['url']);
    }

    #[Test]
    public function the_heat_map_filters_narrow_the_population(): void
    {
        $this->makeRisk(['inherent_likelihood' => 4, 'inherent_impact' => 5, 'inherent_score' => 20]);

        $this->actingAs($this->actor)
            ->get(route('risk.analysis.heatmap', ['category_id' => $this->category->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('total', 1)
                ->where('filters.category_id', $this->category->id)
                ->etc());

        $this->actingAs($this->actor)
            ->get(route('risk.analysis.heatmap', ['category_id' => 999999]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('total', 0)->etc());
    }

    #[Test]
    public function the_bowtie_asks_for_a_risk_before_drawing_one(): void
    {
        $risk = $this->makeRisk(['title' => 'Settlement failure']);

        $this->actingAs($this->actor)
            ->get(route('risk.analysis.bowtie'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Analysis/Bowtie')
                ->where('selected', null)
                ->has('risks', 1)
                ->etc());

        $this->actingAs($this->actor)
            ->get(route('risk.analysis.bowtie', ['risk_id' => $risk->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected.code', $risk->risk_code)
                ->where('selected.title', 'Settlement failure')
                ->etc());
    }

    #[Test]
    public function the_trends_page_carries_its_four_series(): void
    {
        $this->makeRisk(['inherent_likelihood' => 4, 'inherent_impact' => 4, 'inherent_score' => 16, 'inherent_rating' => 'High']);

        $this->actingAs($this->actor)
            ->get(route('risk.analysis.trends'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Analysis/Trends')
                ->has('ratingTrend.labels')
                ->has('scoreTrend.labels')
                ->has('categoryTrend.labels')
                ->has('treatmentTrend.labels')
                ->where('stats.totalActiveRisks', 1)
                ->etc());
    }

    /**
     * The Treatment Progress chart reads treatment_plans.
     *
     * It used to be drawn from the RISKS table: `completed` counted risks whose
     * status was closed/retired and whose updated_at fell in the month, and
     * `overdue` counted active risks rated High or Critical created more than
     * six months ago — the code's own comment called that "simplified". Neither
     * series touched a treatment plan.
     */
    #[Test]
    public function the_treatment_chart_counts_treatment_plans(): void
    {
        $risk = $this->makeRisk();

        // Completed in June.
        TreatmentPlan::create([
            'organization_id' => $this->organization->id,
            'risk_id' => $risk->id,
            'action_title' => 'Automate the reconciliation',
            'strategy' => 'mitigate',
            'status' => 'completed',
            'target_date' => '2026-05-31',
            'completion_date' => '2026-06-10',
            'created_by' => $this->actor->id,
        ]);

        // Past its target in March and still running.
        TreatmentPlan::create([
            'organization_id' => $this->organization->id,
            'risk_id' => $risk->id,
            'action_title' => 'Rewrite the settlement checklist',
            'strategy' => 'mitigate',
            'status' => 'in_progress',
            'target_date' => '2026-03-31',
            'created_by' => $this->actor->id,
        ]);

        // Not started, past target — not late: a plan nobody has begun is
        // unstarted, which is the rule RUNNING_STATUSES states.
        TreatmentPlan::create([
            'organization_id' => $this->organization->id,
            'risk_id' => $risk->id,
            'action_title' => 'Commission the external review',
            'strategy' => 'mitigate',
            'status' => 'not_started',
            'target_date' => '2026-02-28',
            'created_by' => $this->actor->id,
        ]);

        $series = $this->actingAs($this->actor)
            ->get(route('risk.analysis.trends'))
            ->assertOk()
            ->viewData('page')['props']['treatmentTrend'];

        $june = array_search('Jun 2026', $series['labels'], true);
        $july = array_search('Jul 2026', $series['labels'], true);

        $this->assertNotFalse($june);
        $this->assertSame(1, $series['completed'][$june], 'One plan was completed in June.');
        $this->assertSame(0, $series['completed'][$july], 'And none in July.');

        // At July's end exactly one plan is late: the in-progress one. The
        // completed one finished, and the unstarted one is not running.
        $this->assertSame(1, $series['overdue'][$july]);
    }

    #[Test]
    public function the_shared_controls_page_reports_overlap_not_correlation(): void
    {
        $riskA = $this->makeRisk(['inherent_score' => 20]);
        $riskB = $this->makeRisk(['inherent_score' => 16]);
        $control = $this->makeControl();

        $this->attachControl($riskA, $control);
        $this->attachControl($riskB, $control);

        $props = $this->actingAs($this->actor)
            ->get(route('risk.analysis.correlation'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Analysis/SharedControls')->etc())
            ->viewData('page')['props'];

        $this->assertCount(1, $props['pairs']);
        $this->assertSame(1, $props['pairs'][0]['shared_controls']);
        $this->assertSame(100, $props['pairs'][0]['overlap_pct'], 'One shared control out of one each.');

        // No coefficient, no significance — the columns an earlier work package
        // removed because nothing on this page is a correlation.
        $this->assertArrayNotHasKey('coefficient', $props['pairs'][0]);
        $this->assertArrayNotHasKey('significance', $props['pairs'][0]);

        // The diagonal stays blank rather than 100.
        $this->assertNull($props['matrix']['data'][0][0]);
    }

    #[Test]
    public function every_analysis_page_needs_the_permission(): void
    {
        $stranger = \App\Models\User::create([
            'organization_id' => $this->organization->id,
            'name' => 'No Analysis',
            'email' => 'no-analysis@example.test',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'is_active' => true,
        ]);

        foreach (['heatmap', 'bowtie', 'trends', 'correlation'] as $screen) {
            $this->actingAs($stranger)
                ->get(route("risk.analysis.{$screen}"))
                ->assertForbidden();
        }
    }
}
