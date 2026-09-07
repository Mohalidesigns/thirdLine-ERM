<?php

namespace Tests\Feature\Rcsa;

use App\Models\BusinessUnit;
use App\Models\Rcsa\RcsaActionPlan;
use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaRegisterRisk;
use App\Models\User;
use App\Services\Rcsa\RcsaAssessmentService;
use App\Services\Rcsa\RcsaCycleService;
use App\Services\Rcsa\RcsaDashboardService;
use App\Services\Rcsa\RcsaExportService;
use App\Support\Rcsa\RcsaScope;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

/**
 * §11's scoping rule, from both sides:
 *
 * > A risk champion in Retail Operations must not be able to read Treasury's
 * > assessment, or export it.
 *
 * P7's acceptance criterion is the plan's own: *"a test suite that attempts
 * cross-business-unit access on every route as each role and asserts a 403."*
 * `every_route_that_takes_an_assessment_refuses_another_unit` is that test —
 * it walks the actual route table rather than a hand-written list, so a route
 * added in P8 that forgets to authorise is a failure here rather than a
 * discovery in production.
 *
 * THE OTHER HALF IS THE LIST, and it matters just as much. A row somebody can
 * see but not open is still a disclosure: the risk statement, the unit and the
 * residual level are all on the index screen.
 */
class BusinessUnitScopeTest extends CycleTestCase
{
    private User $champion;

    private User $headOfOrm;

    private RcsaAssessment $retailAssessment;

    private RcsaAssessment $treasuryAssessment;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        // One published risk in each unit, so the cycle provisions both.
        $this->publishedRisk(['risk_no' => 'RETAIL-R1', 'business_unit_id' => $this->retail->id]);
        $this->publishedRisk(['risk_no' => 'TREAS-R1', 'business_unit_id' => $this->treasury->id]);

        $cycle = $this->makeCycle();
        app(RcsaCycleService::class)->open($cycle, $this->actor);

        $this->retailAssessment = RcsaAssessment::query()->where('business_unit_id', $this->retail->id)->sole();
        $this->treasuryAssessment = RcsaAssessment::query()->where('business_unit_id', $this->treasury->id)->sole();

        // The Risk Champion of §11's role list: every RCSA permission there is,
        // and authority over Retail alone. Giving them the permissions is the
        // point — this suite proves scoping, not that a permission was withheld.
        $this->champion = $this->userWith([
            'rcsa_universe.view', 'rcsa_universe.create', 'rcsa_universe.update', 'rcsa_universe.delete',
            'rcsa_universe.publish', 'rcsa_universe.import',
            'rcsa_cycle.view', 'rcsa_cycle.manage', 'rcsa_cycle.open', 'rcsa_cycle.close',
            'rcsa_assessment.view', 'rcsa_assessment.complete', 'rcsa_assessment.submit',
            'rcsa_assessment.approve', 'rcsa_assessment.review', 'rcsa_assessment.validate', 'rcsa_assessment.return',
            'rcsa_actionplan.view', 'rcsa_actionplan.update', 'rcsa_actionplan.close', 'rcsa_actionplan.verify',
            'rcsa_export.bulk', 'rcsa_audit.view',
        ], units: [$this->retail]);

        // The Head of ORM: the same permissions plus the estate.
        $this->headOfOrm = $this->userWith([
            'rcsa_universe.view',
            'rcsa_cycle.view',
            'rcsa_assessment.view', 'rcsa_assessment.review', 'rcsa_assessment.validate',
            'rcsa_actionplan.view',
            'rcsa_export.bulk',
            RcsaScope::ALL_UNITS,
        ], units: []);
    }

    /* ------------------------------------------------------------------ */
    /*  The acceptance criterion */
    /* ------------------------------------------------------------------ */

    /**
     * Every route that takes an assessment, walked off the route table.
     */
    #[Test]
    public function every_route_that_takes_an_assessment_refuses_another_unit(): void
    {
        $checked = 0;

        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            $name = (string) $route->getName();

            if (! str_starts_with($name, 'rcsa.') || ! str_contains($route->uri(), '{assessment}')) {
                continue;
            }

            // Routes with a second bound model would need that model to exist
            // in Treasury too; the assessment-only routes are what prove the
            // rule, and the nested ones are covered by their parent's policy.
            if (preg_match('/\{(line|batch|row|plan)\}/', $route->uri())) {
                continue;
            }

            $method = in_array('GET', $route->methods(), true) ? 'get' : 'post';
            $url = route($name, ['assessment' => $this->treasuryAssessment->id]);

            $response = $this->actingAs($this->champion)->{$method}($url);

            $this->assertContains(
                $response->getStatusCode(),
                [403, 404],
                "Route [{$name}] let a Retail champion reach a Treasury assessment "
                ."(got {$response->getStatusCode()}).",
            );

            $checked++;
        }

        // If the filter ever stops matching, this test would pass by checking
        // nothing at all.
        $this->assertGreaterThanOrEqual(6, $checked, 'The route walk matched almost nothing — check the filter.');
    }

    #[Test]
    public function the_same_routes_are_allowed_on_the_champions_own_unit(): void
    {
        $this->actingAs($this->champion)
            ->get(route('rcsa.assessments.show', $this->retailAssessment))
            ->assertOk();

        $this->actingAs($this->champion)
            ->get(route('rcsa.assessments.outstanding', $this->retailAssessment))
            ->assertOk();

        // Which is what makes the refusals above about SCOPE rather than about
        // a permission the champion was never given.
        $this->actingAs($this->champion)
            ->get(route('rcsa.assessments.show', $this->treasuryAssessment))
            ->assertForbidden();
    }

    #[Test]
    public function the_head_of_orm_reaches_every_unit(): void
    {
        foreach ([$this->retailAssessment, $this->treasuryAssessment] as $assessment) {
            $this->actingAs($this->headOfOrm)
                ->get(route('rcsa.assessments.show', $assessment))
                ->assertOk();
        }
    }

    /* ------------------------------------------------------------------ */
    /*  The lists must not show what the policies refuse */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_assessment_list_shows_only_the_champions_units(): void
    {
        $this->actingAs($this->champion)
            ->get(route('rcsa.assessments.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('assessments.data', 1))
            ->assertDontSee('Treasury');

        $this->actingAs($this->headOfOrm)
            ->get(route('rcsa.assessments.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('assessments.data', 2));
    }

    #[Test]
    public function the_universe_list_shows_only_the_champions_units(): void
    {
        $this->actingAs($this->champion)
            ->get(route('rcsa.universe.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('risks.data', 1));

        $this->assertSame(2, RcsaRegisterRisk::count());
    }

    #[Test]
    public function the_cycle_screen_counts_only_the_champions_units(): void
    {
        $this->actingAs($this->champion)
            ->get(route('rcsa.cycles.show', $this->retailAssessment->cycle_id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('assessments', 1));

        $this->actingAs($this->headOfOrm)
            ->get(route('rcsa.cycles.show', $this->retailAssessment->cycle_id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('assessments', 2));
    }

    /* ------------------------------------------------------------------ */
    /*  "…or export it" */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_champion_cannot_export_another_units_risks(): void
    {
        $this->scoreBoth();

        $exports = app(RcsaExportService::class);

        $this->assertSame(1, $exports->count([], $this->champion));
        $this->assertSame(2, $exports->count([], $this->headOfOrm));

        // Naming the other unit explicitly does not widen it — the scope is
        // applied as well as the filter, not instead of it.
        $this->assertSame(
            0,
            $exports->count(['business_units' => [$this->treasury->id]], $this->champion),
        );

        $lines = $exports->lines([], $this->champion);
        $this->assertSame(['Retail Banking'], $lines->pluck('business_unit_name')->unique()->all());
    }

    #[Test]
    public function the_unit_filter_offers_only_the_units_the_user_may_export(): void
    {
        $options = app(RcsaExportService::class)->unitOptions($this->champion);

        $this->assertSame(['Retail Banking'], array_column($options, 'name'));
        $this->assertGreaterThan(1, count(app(RcsaExportService::class)->unitOptions($this->headOfOrm)));
    }

    /* ------------------------------------------------------------------ */
    /*  The dashboard summarises only what the user may see */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_dashboard_totals_are_scoped(): void
    {
        $this->scoreBoth();

        $cycleId = (int) $this->retailAssessment->cycle_id;

        $championView = app(RcsaDashboardService::class)->for($this->champion)->headline($cycleId);
        $ormView = app(RcsaDashboardService::class)->for($this->headOfOrm)->headline($cycleId);

        $this->assertSame(1, $championView['risks']);
        $this->assertSame(1, $championView['units']);

        $this->assertSame(2, $ormView['risks']);
        $this->assertSame(2, $ormView['units']);

        // The heat map is the most-quoted artefact in a Board pack, so it is
        // worth asserting it is scoped and not merely the tiles above it.
        $championMap = app(RcsaDashboardService::class)->for($this->champion)->heatMap($cycleId, 'inherent');
        $this->assertSame(1, $championMap['total']);
    }

    /* ------------------------------------------------------------------ */
    /*  The register, which outlives the cycle */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_action_plan_register_is_scoped_through_the_line(): void
    {
        $this->scoreBoth(aboveAppetite: true);

        foreach ([$this->retailAssessment, $this->treasuryAssessment] as $assessment) {
            $assessment->lines()->first()->actionPlans()->create([
                'organization_id' => $this->organization->id,
                'control_to_implement' => 'Something that will be done about it.',
                'owner_id' => $this->actor->id,
                'target_date' => now()->addMonth()->toDateString(),
                'status' => RcsaActionPlan::OPEN,
            ]);
        }

        $this->assertSame(2, RcsaActionPlan::count());

        $this->actingAs($this->champion)
            ->get(route('rcsa.action-plans.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('plans.data', 1));

        $this->actingAs($this->headOfOrm)
            ->get(route('rcsa.action-plans.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('plans.data', 2));

        // And the plan itself is refused, not merely hidden.
        $treasuryPlan = RcsaActionPlan::query()
            ->whereHas('line', fn ($q) => $q->where('business_unit_id', $this->treasury->id))
            ->sole();

        $this->actingAs($this->champion)
            ->patch(route('rcsa.action-plans.progress', $treasuryPlan), ['progress_pct' => 50])
            ->assertForbidden();
    }

    /* ------------------------------------------------------------------ */
    /*  The boundary itself */
    /* ------------------------------------------------------------------ */

    /**
     * The failure mode this whole design is arranged to avoid: an account
     * nobody has configured seeing everything because "no assignments" was
     * read as "no restriction".
     */
    #[Test]
    public function a_user_with_no_assignments_sees_nothing_rather_than_everything(): void
    {
        $unconfigured = $this->userWith(['rcsa_assessment.view', 'rcsa_universe.view'], units: []);

        $scope = app(RcsaScope::class);

        $this->assertSame([], $scope->unitIdsFor($unconfigured));
        $this->assertNotNull($scope->describe($unconfigured));

        $this->actingAs($unconfigured)
            ->get(route('rcsa.assessments.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('assessments.data', 0)->where('scopeNotice', $scope->describe($unconfigured)));

        $this->actingAs($unconfigured)
            ->get(route('rcsa.assessments.show', $this->retailAssessment))
            ->assertForbidden();
    }

    /**
     * An assignment to a parent reaches its children, and the expansion is
     * computed on READ — a branch added after the assignment is inside it.
     */
    #[Test]
    public function an_assignment_reaches_the_units_below_it(): void
    {
        $branch = BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'parent_id' => $this->retail->id,
            'code' => 'RETAIL-IK',
            'name' => 'Retail — Ikeja',
            'is_active' => true,
        ]);

        $scope = app(RcsaScope::class);

        $this->assertContains($branch->id, $scope->unitIdsFor($this->champion));
        $this->assertTrue($scope->reaches($this->champion, $branch->id));
        $this->assertFalse($scope->reaches($this->champion, $this->treasury->id));

        // A branch created AFTER the assignment is still inside it, because the
        // tree is walked on read rather than stored at assignment time.
        $deeper = BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'parent_id' => $branch->id,
            'code' => 'RETAIL-IK-2',
            'name' => 'Retail — Ikeja Annex',
            'is_active' => true,
        ]);

        $this->assertTrue(app(RcsaScope::class)->reaches($this->champion, $deeper->id));
    }

    #[Test]
    public function an_assignment_can_be_narrowed_to_one_unit(): void
    {
        $branch = BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'parent_id' => $this->retail->id,
            'code' => 'RETAIL-VI',
            'name' => 'Retail — Victoria Island',
            'is_active' => true,
        ]);

        $narrow = $this->userWith(['rcsa_assessment.view'], units: []);
        $this->assign($narrow, [$this->retail], includesDescendants: false);

        $scope = app(RcsaScope::class);

        $this->assertTrue($scope->reaches($narrow, $this->retail->id));
        $this->assertFalse($scope->reaches($narrow, $branch->id));
    }

    /**
     * A tree with a cycle in it — which a bad import can produce — must not
     * spin the expansion for ever.
     *
     * THE CYCLE IS WRITTEN THROUGH THE QUERY BUILDER, ON PURPOSE, but no longer
     * for the reason P7 recorded. It used to be that saving it through the
     * model exhausted memory: `HasObjectIdentity` projects the unit into the
     * object graph along its `parent` edge and the walk had no visited set, so
     * the process died before this test's own subject was reached. That is
     * fixed — BusinessUnit now carries RejectsParentCycles, and
     * ObjectSyncService::materialisePath() bounds the walk (see
     * tests/Feature/Graph/ParentCycleTest).
     *
     * The direct write stays because the guard REFUSES a cyclic parent: a
     * normal save can no longer produce the malformed tree this test needs.
     * Legacy rows and hand-run UPDATEs still can, which is exactly what the
     * query builder is standing in for.
     */
    #[Test]
    public function a_cycle_in_the_unit_tree_does_not_hang_the_expansion(): void
    {
        $child = BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'parent_id' => $this->retail->id,
            'code' => 'LOOP',
            'name' => 'Looping unit',
            'is_active' => true,
        ]);

        // Retail's parent is its own child.
        \Illuminate\Support\Facades\DB::table('business_units')
            ->where('id', $this->retail->id)
            ->update(['parent_id' => $child->id]);

        $units = app(RcsaScope::class)->unitIdsFor($this->champion);

        $this->assertContains($this->retail->id, $units);
        $this->assertContains($child->id, $units);
        $this->assertNotContains($this->treasury->id, $units);
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    private function scoreBoth(bool $aboveAppetite = false): void
    {
        $service = app(RcsaAssessmentService::class);

        foreach ([$this->retailAssessment, $this->treasuryAssessment] as $assessment) {
            foreach ($assessment->lines()->get() as $line) {
                $service->apply($line, $aboveAppetite
                    ? ['inherent_likelihood' => 5, 'inherent_impact' => 5, 'control_effectiveness' => 'Not Achieved']
                    : ['inherent_likelihood' => 2, 'inherent_impact' => 2, 'control_effectiveness' => 'Mostly Achieved'],
                    $this->actor);
            }
        }
    }
}
