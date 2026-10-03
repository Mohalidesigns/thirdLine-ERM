<?php

namespace Tests\Feature\Bcms;

use App\Models\Bcms\ExerciseDefinition;
use App\Models\Bcms\ExerciseOccurrence;
use App\Models\Bcms\Plan;
use App\Models\BusinessUnit;
use App\Models\KeyRiskIndicator;
use App\Models\Organization;
use App\Models\User;
use App\Models\WidgetDefinition;
use App\Services\Bcms\ResilienceKriPublisher;
use App\Services\Widgets\WidgetContext;
use App\Services\Widgets\WidgetDataService;
use App\Services\Widgets\WidgetSourceRegistry;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Database\Seeders\Bcms\BcmsWidgetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Gate 1 re-gate #4, criterion 5, as amended by ADR 0021 Amendment 1: BCMS
 * widgets publish through the SAME generic Dashboards builder TPRM's do
 * (`App\Services\Widgets\WidgetSourceRegistry`/`WidgetQueryEngine`), and
 * branch scope is a property of DRILLS AND PLANS, not of the seventeen
 * resilience KRIs or the maturity score — both organisation-level by
 * definition, with no per-branch reading to lean on. See the ADR for why.
 *
 * `unitsUnderNodes()` (extracted from TPRM's own `engagementsUnderNodes()`)
 * is what the `business_unit_ref`/`bcms_definition_units` node-scope kinds
 * below reuse — `tests/Feature/Tprm/TprmWidgetTest.php` and
 * `TprmWidgetHqRenderTest.php` are the guard that extraction did not change
 * TPRM's own behaviour, and both are confirmed green, unedited, elsewhere in
 * this cycle's verification.
 *
 * B1 (gate 1 code review #1): EVERY TEST BELOW USES A UNIT-ASSIGNED USER, not
 * an unassigned one. `WidgetQueryEngine::baseQuery()` never called
 * `applyVisibility()` before this cycle, so an UNASSIGNED user (no
 * `business_unit_user` row at all) was shown every branch's rows through a
 * widget regardless of node — ADR 0017's own rule is that an unassigned user
 * sees ORGANISATION-LEVEL rows only, nothing belonging to any unit. The
 * previous version of this file used one unassigned user for every
 * assertion, including "the Kano node shows 2 rows" — which passed for the
 * wrong reason (node scope alone, with no visibility check running at all)
 * and would have kept passing had `applyVisibility()` been wired to the
 * wrong user or never wired at all. A user actually ASSIGNED to Kano (with
 * `includes_descendants`) is what proves the fix.
 */
class Phase11WidgetsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private BusinessUnit $kano;

    private BusinessUnit $kanoChild;

    private BusinessUnit $lagos;

    /** Assigned to Kano, `includes_descendants = true`. */
    private User $kanoUser;

    /** Assigned to Lagos only. */
    private User $lagosUser;

    /** Holds `rcsa_scope.all_units` — unrestricted, the "sees everything" reader. */
    private User $orgUser;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.bcms', true);

        $this->organization = Organization::create([
            'name' => 'Kano Heritage Bank', 'short_name' => 'KHB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($this->organization->id);
        $this->seed(BcmsReferenceSeeder::class);
        TenantContext::set($this->organization->id);

        $this->kano = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-KAN', 'name' => 'Kano Branch', 'is_active' => true,
        ]);
        $this->kanoChild = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-KAN-C', 'name' => 'Kano Sub-Branch',
            'parent_id' => $this->kano->id, 'is_active' => true,
        ]);
        $this->lagos = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-LAG', 'name' => 'Lagos Branch', 'is_active' => true,
        ]);

        $viewPermissions = ['bcms.exercise.view', 'bcms.plan.view', 'kri.view'];

        $this->kanoUser = $this->makeUser($viewPermissions, $this->kano, true);
        $this->lagosUser = $this->makeUser($viewPermissions, $this->lagos, false);
        $this->orgUser = $this->makeUser(array_merge($viewPermissions, ['rcsa_scope.all_units']));
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    /**
     * Amendment 1's proof (i): drills at Kano and Lagos plus one corporate
     * drill with no business unit on its definition. The Kano-ASSIGNED
     * user's own node shows Kano's drill AND its child unit's
     * (`includes_descendants`); the Lagos-assigned user's node shows only
     * its own; the unrestricted (`rcsa_scope.all_units`) reader at
     * organisation scope shows all three named branch drills PLUS the
     * unattributed corporate one, which never appears on a branch tile at
     * all.
     */
    #[Test]
    public function a_branch_node_shows_its_own_drills_and_its_child_units_but_not_another_branchs(): void
    {
        $kanoDefinition = ExerciseDefinition::factory()->create(['business_unit_id' => $this->kano->getKey()]);
        $kanoChildDefinition = ExerciseDefinition::factory()->create(['business_unit_id' => $this->kanoChild->getKey()]);
        $lagosDefinition = ExerciseDefinition::factory()->create(['business_unit_id' => $this->lagos->getKey()]);
        $corporateDefinition = ExerciseDefinition::factory()->create(); // no business unit at all

        ExerciseOccurrence::factory()->create(['definition_id' => $kanoDefinition->getKey()]);
        ExerciseOccurrence::factory()->create(['definition_id' => $kanoChildDefinition->getKey()]);
        ExerciseOccurrence::factory()->create(['definition_id' => $lagosDefinition->getKey()]);
        ExerciseOccurrence::factory()->create(['definition_id' => $corporateDefinition->getKey()]);

        $definition = $this->countDefinition('bcms_exercise_occurrences');

        $kano = app(WidgetDataService::class)->render($definition, new WidgetContext($this->kanoUser, $this->kano->graphObject()));
        $lagos = app(WidgetDataService::class)->render($definition, new WidgetContext($this->lagosUser, $this->lagos->graphObject()));
        $organisation = app(WidgetDataService::class)->render($definition, new WidgetContext($this->orgUser));

        $this->assertSame(2, (int) $kano['data']['value'], "The Kano-assigned user's own node must show Kano's drill and its child unit's.");
        $this->assertSame(1, (int) $lagos['data']['value'], "The Lagos-assigned user's own node must show only Lagos's drill.");
        $this->assertSame(4, (int) $organisation['data']['value'], 'The unrestricted reader at organisation scope must show every drill, including the unattributed corporate one.');
    }

    /**
     * Amendment 1's proof (ii): the same three-way shape for plan status,
     * over the same three unit-assigned users. A group-wide plan with no
     * `business_unit_id` appears at organisation scope only.
     */
    #[Test]
    public function a_branch_node_shows_its_own_plans_and_its_child_units_but_not_another_branchs(): void
    {
        Plan::factory()->create(['business_unit_id' => $this->kano->getKey()]);
        Plan::factory()->create(['business_unit_id' => $this->kanoChild->getKey()]);
        Plan::factory()->create(['business_unit_id' => $this->lagos->getKey()]);
        Plan::factory()->create(); // group-wide, no business unit

        $definition = $this->countDefinition('bcms_plans');

        $kano = app(WidgetDataService::class)->render($definition, new WidgetContext($this->kanoUser, $this->kano->graphObject()));
        $lagos = app(WidgetDataService::class)->render($definition, new WidgetContext($this->lagosUser, $this->lagos->graphObject()));
        $organisation = app(WidgetDataService::class)->render($definition, new WidgetContext($this->orgUser));

        $this->assertSame(2, (int) $kano['data']['value'], "The Kano-assigned user's own node must show Kano's plan and its child unit's.");
        $this->assertSame(1, (int) $lagos['data']['value'], "The Lagos-assigned user's own node must show only Lagos's plan.");
        $this->assertSame(4, (int) $organisation['data']['value'], 'The unrestricted reader at organisation scope must show every plan, including the group-wide one.');
    }

    /**
     * B1's own defect, proven directly: a user assigned to Kano ONLY (no
     * `rcsa_scope.all_units`) must not see Lagos's plan even when the widget
     * itself carries no node restriction at all (`WidgetContext` with no
     * node — `WidgetScope::isUnrestricted()`). Node scope and record
     * visibility are two different gates, and this proves the second one
     * holds even when the first is wide open.
     */
    #[Test]
    public function an_assigned_users_own_visibility_holds_even_with_no_node_on_the_widget(): void
    {
        Plan::factory()->create(['business_unit_id' => $this->kano->getKey()]);
        Plan::factory()->create(['business_unit_id' => $this->lagos->getKey()]);
        Plan::factory()->create(); // group-wide, visible to everyone

        $definition = $this->countDefinition('bcms_plans');

        // No node at all — WidgetScope::isUnrestricted() — yet the
        // Kano-assigned user must still see only Kano's plan plus the
        // group-wide one, never Lagos's.
        $result = app(WidgetDataService::class)->render($definition, new WidgetContext($this->kanoUser));

        $this->assertSame(2, (int) $result['data']['value'], "An assigned user's own visibility must hold even on a node-unrestricted widget.");
    }

    /**
     * R1 (gate 1 code review #2): the first version of `applyVisibility()`
     * re-implemented ADR 0017 as a second copy and dropped the named-user
     * arm — `ExerciseOccurrence::orgVisibilityNamedUsers()` names
     * `facilitator_id`/`participants.user_id` — so a Kano-assigned
     * facilitator of a LAGOS drill saw it on the calendar (which goes
     * through `BindsToVisibleRecord` directly) but not on the widget (which
     * went through the copy). Now both go through the SAME
     * `constrainToVisibleRecord()`, so the facilitator sees exactly that
     * one drill, not zero and not every Lagos drill.
     */
    #[Test]
    public function a_kano_assigned_facilitator_of_a_lagos_drill_sees_exactly_that_drill_on_the_widget(): void
    {
        $lagosDefinition = ExerciseDefinition::factory()->create(['business_unit_id' => $this->lagos->getKey()]);
        $otherLagosDefinition = ExerciseDefinition::factory()->create(['business_unit_id' => $this->lagos->getKey()]);

        $facilitatedOccurrence = ExerciseOccurrence::factory()->create([
            'definition_id' => $lagosDefinition->getKey(), 'facilitator_id' => $this->kanoUser->getKey(),
        ]);
        // A second Lagos drill the Kano user does NOT facilitate — must NOT
        // appear; the named-user arm names ONE row, not the whole branch.
        ExerciseOccurrence::factory()->create(['definition_id' => $otherLagosDefinition->getKey()]);

        $definition = $this->countDefinition('bcms_exercise_occurrences');

        // No node — isolates visibility from node/branch placement, the
        // same shape `an_assigned_users_own_visibility_holds_even_with_no_node_on_the_widget`
        // already establishes.
        $result = app(WidgetDataService::class)->render($definition, new WidgetContext($this->kanoUser));

        $this->assertSame(1, (int) $result['data']['value'], 'The facilitator must see exactly the one drill they facilitate, not the whole Lagos branch.');
    }

    /**
     * R1: the trait's own "whole estate" short-circuit
     * (`unitIdsFor($user) === null` returns with NO predicate applied at
     * all) is what stops a `whereHas('definition', ...)` existence
     * requirement from silently hiding an occurrence whose definition has
     * been soft-deleted (`OccurrenceGenerator::reschedule()` does this) from
     * an `rcsa_scope.all_units` holder — the trait's own docblock names this
     * exact defect. The first copy in `WidgetQueryEngine` added that
     * `whereHas()` unconditionally.
     */
    #[Test]
    public function an_all_units_reader_still_sees_an_occurrence_whose_definition_is_soft_deleted(): void
    {
        $definition = ExerciseDefinition::factory()->create(['business_unit_id' => $this->kano->getKey()]);
        $occurrence = ExerciseOccurrence::factory()->create(['definition_id' => $definition->getKey()]);
        $definition->delete();

        $this->assertTrue($definition->fresh()->trashed());

        $widgetDefinition = $this->countDefinition('bcms_exercise_occurrences');

        // orgUser holds rcsa_scope.all_units — unitIdsFor() returns null —
        // and no node is on the widget either, so nothing on the node axis
        // filters it out.
        $result = app(WidgetDataService::class)->render($widgetDefinition, new WidgetContext($this->orgUser));

        $this->assertSame(1, (int) $result['data']['value'], 'An all-units reader must still see an occurrence whose definition was soft-deleted, exactly as it appears on the calendar.');
        $this->assertTrue(ExerciseOccurrence::query()->whereKey($occurrence->getKey())->exists(), 'The occurrence itself must be untouched by the fixture.');
    }

    /**
     * Amendment 1's proof (iii), REPLACING the previous cycle's
     * `forceFill(['node_id' => ...])` test, which exercised a row shape no
     * product write path produces (`node_id` is not fillable and nothing
     * resolves it for a KRI — see `ResilienceKriPublisher::createKri()`'s
     * docblock). The seventeen resilience KRIs are organisation-level by
     * definition: a branch node shows NONE of them, and organisation scope
     * shows all seventeen. `KeyRiskIndicator` implements neither
     * `ScopedToOrgHierarchyContract` nor `orgAnchorPath()`, so B1's
     * visibility fix does not change this shape at all — the Kano-assigned
     * user is used here deliberately to prove that.
     */
    #[Test]
    public function resilience_kris_show_none_on_a_branch_node_and_all_seventeen_at_organisation_scope(): void
    {
        app(ResilienceKriPublisher::class)->adopt();

        $this->assertSame(17, KeyRiskIndicator::query()->where('kri_code', 'like', 'BCMS-%')->count());

        $definition = $this->countDefinition('key_risk_indicators', [
            'filters' => [['field' => 'kri_code', 'op' => 'like', 'value' => 'BCMS-']],
        ]);

        $branch = app(WidgetDataService::class)->render($definition, new WidgetContext($this->kanoUser, $this->kano->graphObject()));
        $organisation = app(WidgetDataService::class)->render($definition, new WidgetContext($this->orgUser));

        $this->assertSame(0, (int) $branch['data']['value'], 'A branch node must show none of the seventeen organisation-level KRIs.');
        $this->assertSame(17, (int) $organisation['data']['value'], 'Organisation scope must show all seventeen.');
    }

    /**
     * Amendment 1's proof (iv): the source permission gate is unaffected by
     * any of the above — a viewer without `bcms.exercise.view` gets
     * `forbidden`, whatever node the widget is placed on.
     */
    #[Test]
    public function a_viewer_without_bcms_exercise_view_gets_forbidden(): void
    {
        $viewer = $this->makeUser(['kri.view'], $this->kano, false); // deliberately no bcms.exercise.view

        $definition = $this->countDefinition('bcms_exercise_occurrences');

        $result = app(WidgetDataService::class)->render($definition, new WidgetContext($viewer, $this->kano->graphObject()));

        $this->assertSame('forbidden', $result['state']);
    }

    /**
     * B14 (gate 1 code review #1): every widget `BcmsWidgetSeeder` ships must
     * actually RENDER through the full engine (`WidgetDataService::render()`,
     * not just `WidgetQueryEngine` directly) — the seeder's own `sort`
     * key-name defect (`field`/`direction` instead of the `by`/`dir`
     * `RegisterResolver` actually reads) and its frozen `date('Y-m-d')` filter
     * were both the kind of bug that passes a "the source exists and the
     * columns are whitelisted" smoke test while quietly never sorting or
     * ageing correctly at render time — only rendering catches that.
     *
     * R6 (gate 1 code review #2): `state === 'ok'` ALONE proves only that the
     * widget did not throw — it proves nothing about the `$today` filter or
     * the `scheduled_date asc` sort actually named in the drill-calendar
     * widget's own `query` config; a frozen date filter or a
     * `field`/`direction` sort key (B14's own defect family) would ALSO
     * render 'ok', with the wrong rows or the wrong order. Seed a past, a
     * near-future and a far-future drill and assert on the rows themselves.
     */
    #[Test]
    public function every_seeded_bcms_widget_renders_through_the_engine(): void
    {
        $this->seed(BcmsWidgetSeeder::class);

        $definitions = WidgetDefinition::withoutGlobalScopes()->where('code', 'like', 'wg-bcms-%')->get();
        $this->assertCount(3, $definitions, 'Every seeded widget must actually be found to render.');

        $exerciseDefinition = ExerciseDefinition::factory()->create();

        // `bcms_exercise_occurrences` is unique on (definition_id,
        // sequence_no); the factory's own default is a RANDOM 1-5, so three
        // occurrences on the same definition with no explicit sequence_no
        // collide intermittently (~44% of the time) — an explicit, distinct
        // sequence_no per row is what a fixture, not luck, guarantees.
        $past = ExerciseOccurrence::factory()->create([
            'definition_id' => $exerciseDefinition->getKey(), 'sequence_no' => 1, 'scheduled_date' => now()->subDays(10)->toDateString(),
        ]);
        $farFuture = ExerciseOccurrence::factory()->create([
            'definition_id' => $exerciseDefinition->getKey(), 'sequence_no' => 2, 'scheduled_date' => now()->addDays(60)->toDateString(),
        ]);
        $nearFuture = ExerciseOccurrence::factory()->create([
            'definition_id' => $exerciseDefinition->getKey(), 'sequence_no' => 3, 'scheduled_date' => now()->addDays(5)->toDateString(),
        ]);

        foreach ($definitions as $definition) {
            $result = app(WidgetDataService::class)->render($definition, new WidgetContext($this->orgUser));

            $this->assertSame('ok', $result['state'], "{$definition->code} must render, not error.");

            if ($definition->code === 'wg-bcms-drill-calendar') {
                $ids = collect($result['data']['rows'])->pluck('id')->all();

                $this->assertNotContains($past->getKey(), $ids, 'A drill already in the past must not appear on the upcoming-drills widget.');
                $this->assertSame(
                    [$nearFuture->getKey(), $farFuture->getKey()],
                    $ids,
                    'The remaining two drills must come back sorted by scheduled_date ascending, the near one before the far one.'
                );
            }
        }
    }

    /**
     * `DatabaseSeeder` has no smoke test of its own; this is the seeder's,
     * mirroring `TprmWidgetTest::the_seeder_ships_nine_system_widgets` /
     * `seeding_twice_does_not_duplicate` — the two facts a widget seeder
     * must hold: it ships system rows (`organization_id` null) and it is
     * idempotent on `code`.
     */
    #[Test]
    public function the_bcms_widget_seeder_ships_three_system_widgets_idempotently(): void
    {
        $this->seed(BcmsWidgetSeeder::class);
        $this->seed(BcmsWidgetSeeder::class);

        $widgets = WidgetDefinition::withoutGlobalScopes()
            ->whereNull('organization_id')
            ->where('code', 'like', 'wg-bcms-%')
            ->get();

        $this->assertCount(3, $widgets);
        $this->assertSame([
            'wg-bcms-drill-calendar', 'wg-bcms-plan-status', 'wg-bcms-resilience-kris',
        ], $widgets->pluck('code')->sort()->values()->all());
    }

    #[Test]
    public function every_column_the_bcms_widget_seeder_names_is_on_its_sources_whitelist(): void
    {
        $this->seed(BcmsWidgetSeeder::class);

        $registry = app(WidgetSourceRegistry::class);

        foreach (WidgetDefinition::withoutGlobalScopes()->where('code', 'like', 'wg-bcms-%')->get() as $widget) {
            $source = (string) $widget->queryConfig('source');

            foreach ((array) $widget->queryConfig('columns', []) as $column) {
                $this->assertTrue(
                    $registry->allowsColumn($source, $column),
                    "{$widget->code} names column [{$column}], which [{$source}] does not allow."
                );
            }

            foreach ((array) $widget->queryConfig('filters', []) as $filter) {
                $this->assertTrue(
                    $registry->allowsColumn($source, $filter['field']),
                    "{$widget->code} filters on [{$filter['field']}], which [{$source}] does not allow."
                );
            }
        }
    }

    /** @param  list<string>  $permissions */
    private function makeUser(array $permissions, ?BusinessUnit $unit = null, bool $includesDescendants = false): User
    {
        $email = Str::random(12).'@khb.test';

        $user = User::create([
            'name' => 'Widget Viewer '.Str::random(6), 'email' => $email,
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id, 'is_active' => true,
        ]);

        if ($permissions !== []) {
            $role = Role::findOrCreate('widget-viewer-'.md5($email), 'web');

            foreach ($permissions as $permission) {
                $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
            }

            $user->assignRole($role);
        }

        if ($unit !== null) {
            DB::table('business_unit_user')->insert([
                'organization_id' => $this->organization->id, 'user_id' => $user->id,
                'business_unit_id' => $unit->id, 'includes_descendants' => $includesDescendants,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $user;
    }

    /** @param  array<string, mixed>  $queryExtra */
    private function countDefinition(string $source, array $queryExtra = []): WidgetDefinition
    {
        static $sequence = 0;

        return WidgetDefinition::create([
            'organization_id' => $this->organization->id,
            'code' => 'test-bcms-widget-'.(++$sequence),
            'name' => 'Test widget',
            'widget_type' => 'kpi_tile',
            'context_binding' => 'inherit_subtree',
            'period_binding' => 'selected',
            'query' => array_merge(['source' => $source], $queryExtra),
        ]);
    }
}
