<?php

namespace Tests\Feature\Bcms;

use App\Enums\Bcms\CallTreeType;
use App\Enums\Bcms\DistributionMode;
use App\Enums\Bcms\FindingClassification;
use App\Enums\Bcms\FindingSource;
use App\Enums\Bcms\PlanType;
use App\Models\Bcms\Aar;
use App\Models\Bcms\Alert;
use App\Models\Bcms\AlertRecipient;
use App\Models\Bcms\AlertTemplate;
use App\Models\Bcms\BiaCampaign;
use App\Models\Bcms\CallTree;
use App\Models\Bcms\CallTreeTest;
use App\Models\Bcms\Concerns\BindsToVisibleRecord;
use App\Models\Bcms\Concerns\ScopedToOrgHierarchy;
use App\Models\Bcms\Concerns\ScopedToOrgHierarchyContract;
use App\Models\Bcms\DrSystem;
use App\Models\Bcms\DrTest;
use App\Models\Bcms\Evidence;
use App\Models\Bcms\ExerciseDefinition;
use App\Models\Bcms\ExerciseInject;
use App\Models\Bcms\ExerciseOccurrence;
use App\Models\Bcms\ExerciseParticipant;
use App\Models\Bcms\ExerciseProgramme;
use App\Models\Bcms\ExerciseType;
use App\Models\Bcms\Finding;
use App\Models\Bcms\IdentityConnector;
use App\Models\Bcms\IdentitySyncChange;
use App\Models\Bcms\IdentitySyncRun;
use App\Models\Bcms\ManagementReview;
use App\Models\Bcms\Plan;
use App\Models\Bcms\PlanSection;
use App\Models\Bcms\Process;
use App\Models\Bcms\Programme;
use App\Models\Bcms\ProgrammeObligation;
use App\Models\Bcms\ReadinessTask;
use App\Models\Bcms\TrainingRecord;
use App\Models\BusinessUnit;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bcms\Findings\FindingService;
use App\Support\Rcsa\RcsaScope;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use ReflectionNamedType;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * ADR 0017 — BCMS record visibility is resolved at route binding, not in a
 * policy. This is the enforcement described in the ADR's §6, in the shape of
 * `RouteAuthorizationTest` and `BcmsRouteKeyTest`: it enumerates MODELS,
 * because a new record route for an already-classified model is safe by
 * construction, and a new model fails the guard the moment its first route
 * exists.
 */
class BcmsRecordVisibilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * §2 category 3. Pinned and asserted whole (§6.3): a reason per entry, and
     * the reason is the thing a reviewer reads before approving a diff that
     * adds to it.
     *
     * @var array<class-string<Model>, string>
     */
    private const ORGANISATION_LEVEL_MODELS = [
        Programme::class => 'one per tenant',
        ProgrammeObligation::class => 'child of the one-per-tenant programme; a compliance '
            .'obligation is organisation-wide by construction, like its parent',
        ManagementReview::class => 'child of the one-per-tenant programme; a review of the whole '
            .'BCMS programme is organisation-wide by construction',
        ExerciseProgramme::class => 'the annual exercise programme is organisation-wide; '
            .'individual exercises scope to a unit through their definition',
        BiaCampaign::class => 'spans units by construction, and the assessments inside it are each scoped',
        Alert::class => 'targeted by audience rule, bcms_alerts has no unit column by design',
        AlertRecipient::class => 'targets a contact through an organisation-level alert; '
            .'visibility follows the alert, which has none',
        AlertTemplate::class => 'a message template is shared across the whole tenant; '
            .'there is no per-unit variant',
        // ADR 0018 §8 (BCMS Phase 2C). All three identity models are
        // organisation-level; IdentityConnector is never route-bound today
        // (the settings controller resolves it manually, one per tenant), so
        // §6.1's route scan would not otherwise ask for it, but the ADR pins
        // all three in the same map and the reason is the ADR's own words.
        IdentityConnector::class => 'one per tenant, holds no business unit and should not (ADR 0018 §2.2, §8)',
        IdentitySyncRun::class => 'a record of an organisation-wide directory read (ADR 0018 §2.3, §8)',
        IdentitySyncChange::class => 'a staged fact from an organisation-wide directory read, '
            .'nested under its (organisation-level) run',
        // BCMS Phase 10 (ADR 0020 §3). An IT DR register is enterprise IT's,
        // not one business unit's: recovery tiers and RTO/RPO targets apply
        // across the whole estate, like BiaCampaign, and there is no unit
        // column to scope on.
        DrSystem::class => 'an IT DR register is organisation-wide; recovery tiers and targets apply '
            .'across the whole estate, not one business unit',
        DrTest::class => 'a test result against an organisation-level DR system, visible the same way',
        // BCMS Phase 11 (ADR 0021; ADR 0017 §2 classification). A training
        // record's only two relations are `curriculum` (system-owned
        // reference content, `organization_id` null, no business unit at
        // all) and `user` — a PLATFORM `App\Models\User`, not a BCMS anchor:
        // unlike `Contact`, User does not use `ScopedToOrgHierarchy`, so no
        // orgAnchorPath() segment can terminate on it under §6.2's rule.
        // `occurrence_id` is nullable and only set for the minority of
        // records criterion 6 auto-links from an exercise — a derived path
        // needs to be the SINGLE path (§2 rule 2), and one that 404s every
        // manually recorded outcome (the common case) the moment it lacks an
        // occurrence is not that. Training compliance is read and managed
        // BCMS-wide by `bcms.training.view`/`.manage`, the same scope as the
        // curricula being measured against, which are themselves system-wide.
        TrainingRecord::class => 'anchors on a curriculum (system-wide reference content) and a '
            .'platform User, neither of which carries BCMS unit scoping; visible wherever '
            .'bcms.training.view/.manage already govern it',
    ];

    private Organization $organization;

    private BusinessUnit $kano;

    private BusinessUnit $lagos;

    private BusinessUnit $group;

    private BusinessUnit $groupBranch;

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
            'organization_id' => $this->organization->id, 'code' => 'BU-KANO', 'name' => 'Kano', 'is_active' => true,
        ]);
        $this->lagos = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-LAGOS', 'name' => 'Lagos', 'is_active' => true,
        ]);
        $this->group = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-GROUP', 'name' => 'Group', 'is_active' => true,
        ]);
        $this->groupBranch = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-GROUP-B', 'name' => 'Group Branch',
            'is_active' => true, 'parent_id' => $this->group->id,
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  §6.1 — every routed model resolves through the filter, or is pinned */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function every_bcms_route_parameter_binding_a_bcms_model_is_covered(): void
    {
        $offenders = [];
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            /** @var RoutingRoute $route */
            $name = $route->getName();

            if ($name === null || ! str_starts_with($name, 'bcms.')) {
                continue;
            }

            foreach ($this->bcmsModelParameters($route) as $model) {
                $checked++;

                $usesBinding = in_array(BindsToVisibleRecord::class, class_uses_recursive($model), true);
                $isPinned = array_key_exists($model, self::ORGANISATION_LEVEL_MODELS);

                if (! $usesBinding && ! $isPinned) {
                    $offenders[] = "{$name} binds {$model}, which uses neither BindsToVisibleRecord "
                        .'nor appears in the pinned organisation-level map.';
                }
            }
        }

        $this->assertGreaterThan(0, $checked, 'No BCMS route bound a model — this guard would pass vacuously.');
        $this->assertSame([], $offenders, implode("\n", $offenders));
    }

    #[Test]
    public function the_five_nested_routes_scope_their_child_binding_to_the_parent(): void
    {
        // ADR 0017 §5. Each of these resolves a second BCMS model through the
        // first's own relation; without `->scopeBindings()` the child binds
        // globally by id, and a child belonging to a DIFFERENT parent can be
        // addressed through a parent the user can see.
        $nested = [
            'bcms.plans.sections.update',
            'bcms.plans.sections.destroy',
            'bcms.plans.deactivate',
            'bcms.call-trees.nodes.update',
            'bcms.call-trees.nodes.destroy',
            'bcms.call-trees.nodes.reparent',
            'bcms.call-trees.nodes.deputy',
            'bcms.bia.dependencies.destroy',
            'bcms.call-tree-tests.nodes.ack',
            'bcms.call-tree-tests.nodes.failure',
            'bcms.call-tree-tests.nodes.fix-contact',
            'bcms.call-tree-tests.nodes.finding',
        ];

        foreach ($nested as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "Route {$name} no longer exists.");
            $this->assertTrue(
                $route->enforcesScopedBindings(),
                "{$name} binds a second BCMS model but does not call ->scopeBindings() — "
                .'the child would resolve globally by id.'
            );
        }
    }

    /**
     * @return list<class-string<Model>>
     */
    private function bcmsModelParameters(RoutingRoute $route): array
    {
        $action = $route->getAction('uses');

        if (! is_string($action) || ! str_contains($action, '@')) {
            return [];
        }

        [$class, $method] = explode('@', $action, 2);

        if (! class_exists($class) || ! method_exists($class, $method)) {
            return [];
        }

        $models = [];

        foreach ((new ReflectionMethod($class, $method))->getParameters() as $argument) {
            $type = $argument->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $model = $type->getName();

            if (str_starts_with($model, 'App\\Models\\Bcms\\') && is_subclass_of($model, Model::class)) {
                $models[] = $model;
            }
        }

        return $models;
    }

    /* ------------------------------------------------------------------ */
    /*  §6.2 — every model using the concern is classifiable */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function every_model_using_binds_to_visible_record_is_classifiable(): void
    {
        $models = $this->bcmsModelClasses();
        $checked = 0;

        foreach ($models as $model) {
            if (! in_array(BindsToVisibleRecord::class, class_uses_recursive($model), true)) {
                continue;
            }

            $checked++;
            $instance = new $model;

            if (in_array(ScopedToOrgHierarchy::class, class_uses_recursive($model), true)) {
                // Anchor: the column it claims to scope on must actually exist —
                // this is the exact assertion that would have caught `Finding`
                // before its first call raised an unknown-column error.
                $this->assertTrue(
                    Schema::hasColumn($instance->getTable(), $instance->orgScopeColumn()),
                    "{$model} is an anchor via ScopedToOrgHierarchy, but its table "
                    ."{$instance->getTable()} has no column {$instance->orgScopeColumn()}."
                );

                // It must also `implements ScopedToOrgHierarchyContract` — the
                // trait gives PHPStan no way to know a class has it, and
                // `BindsToVisibleRecord` narrows on the interface, not
                // `method_exists()`, precisely so the compiler can verify the
                // call it makes on every anchor.
                $this->assertInstanceOf(
                    ScopedToOrgHierarchyContract::class,
                    $instance,
                    "{$model} uses ScopedToOrgHierarchy but does not `implements ScopedToOrgHierarchyContract` — "
                    .'BindsToVisibleRecord will silently treat it as unclassifiable.'
                );

                continue;
            }

            // Derived: orgAnchorPath() must exist and its relation chain must
            // terminate on a model that is itself an anchor.
            $this->assertTrue(
                method_exists($instance, 'orgAnchorPath'),
                "{$model} uses BindsToVisibleRecord but declares neither ScopedToOrgHierarchy "
                .'nor orgAnchorPath() — it is not classifiable under ADR 0017 §2.'
            );

            $segments = explode('.', $instance->orgAnchorPath());
            $cursor = $instance;

            foreach ($segments as $segment) {
                $this->assertTrue(
                    method_exists($cursor, $segment),
                    "{$model}'s orgAnchorPath segment '{$segment}' is not a relation on ".$cursor::class
                );

                $cursor = $cursor->{$segment}()->getRelated();
            }

            // Matches the runtime check exactly: `constrainAnchorPath()`'s
            // terminal tests `instanceof ScopedToOrgHierarchyContract`, not
            // trait usage — a terminal using the trait but not implementing
            // the interface throws there (defect 3, gate 2 round 1) rather
            // than silently applying no predicate at all.
            $this->assertInstanceOf(
                ScopedToOrgHierarchyContract::class,
                $cursor,
                "{$model}'s orgAnchorPath() ({$instance->orgAnchorPath()}) does not terminate on a model "
                .'implementing ScopedToOrgHierarchyContract.'
            );
        }

        $this->assertGreaterThan(0, $checked, 'No model uses BindsToVisibleRecord — this guard would pass vacuously.');
    }

    /**
     * Defect 3, gate 2 round 1: the previous version of this guard only
     * checked `ScopedToOrgHierarchy` users that ALSO use `BindsToVisibleRecord`
     * themselves — missing a model like `Contact` or `Site`, which use the
     * trait but are reached only as the TERMINAL of some other model's
     * `orgAnchorPath()`. `constrainAnchorPath()`'s terminal check is
     * `instanceof ScopedToOrgHierarchyContract` with no fallback; a
     * `ScopedToOrgHierarchy` user that forgot the interface fails there at
     * request time (a thrown `LogicException`, not a silent unscoped bind).
     * This runs over every such user, not only the ones a concern user's
     * path happens to reach today, so the NEXT one fails here instead.
     */
    #[Test]
    public function every_scoped_to_org_hierarchy_user_implements_the_contract(): void
    {
        $models = $this->bcmsModelClasses();
        $checked = 0;

        foreach ($models as $model) {
            if (! in_array(ScopedToOrgHierarchy::class, class_uses_recursive($model), true)) {
                continue;
            }

            $checked++;

            $this->assertInstanceOf(
                ScopedToOrgHierarchyContract::class,
                new $model,
                "{$model} uses ScopedToOrgHierarchy but does not `implements ScopedToOrgHierarchyContract` — "
                .'a future orgAnchorPath() terminating on it, or a route binding it directly, '
                .'would either throw at request time or (if BindsToVisibleRecord is bypassed) fail open.'
            );
        }

        $this->assertGreaterThan(0, $checked, 'No model uses ScopedToOrgHierarchy — this guard would pass vacuously.');
    }

    /** @return list<class-string<Model>> */
    private function bcmsModelClasses(): array
    {
        $classes = [];

        foreach (glob(app_path('Models/Bcms/*.php')) ?: [] as $file) {
            $class = 'App\\Models\\Bcms\\'.basename($file, '.php');

            if (class_exists($class) && is_subclass_of($class, Model::class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /* ------------------------------------------------------------------ */
    /*  §6.3 — the organisation-level map is pinned and asserted whole */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_organisation_level_map_is_pinned_whole_and_no_other_model_is_organisation_level(): void
    {
        // Guards the guard: if someone adds a routed model here instead of
        // teaching it to classify itself, this fails and names the addition.
        // IdentityConnector/IdentitySyncRun/IdentitySyncChange are BCMS Phase
        // 2C's (ADR 0018 §8); DrSystem/DrTest are Phase 10's (ADR 0020 §3).
        $this->assertSame([
            Programme::class,
            ProgrammeObligation::class,
            ManagementReview::class,
            ExerciseProgramme::class,
            BiaCampaign::class,
            Alert::class,
            AlertRecipient::class,
            AlertTemplate::class,
            IdentityConnector::class,
            IdentitySyncRun::class,
            IdentitySyncChange::class,
            DrSystem::class,
            DrTest::class,
            TrainingRecord::class,
        ], array_keys(self::ORGANISATION_LEVEL_MODELS));

        foreach (self::ORGANISATION_LEVEL_MODELS as $model => $reason) {
            $this->assertNotSame('', trim($reason), "{$model} has no reason recorded.");
            $this->assertFalse(
                in_array(BindsToVisibleRecord::class, class_uses_recursive($model), true),
                "{$model} is pinned as organisation-level but also uses BindsToVisibleRecord — pick one."
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Behaviour, per anchor family (§6.4) */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_kano_user_opening_a_lagos_plan_by_uuid_gets_404_and_a_same_unit_user_succeeds(): void
    {
        $lagosPlan = Plan::query()->create([
            'plan_type' => PlanType::Bcp->value, 'title' => 'Lagos BCP', 'status' => 'draft',
            'version' => 1, 'content' => [], 'business_unit_id' => $this->lagos->id,
        ]);

        $kanoUser = $this->userWith(['bcms.plan.view', 'bcms.plan.manage'], 'kano-plan@khb.test', $this->kano);
        $lagosUser = $this->userWith(['bcms.plan.view'], 'lagos-plan@khb.test', $this->lagos);

        $this->actingAs($kanoUser)->get(route('bcms.plans.show', $lagosPlan))->assertNotFound();

        // A write verb 404s identically, and changes nothing (§3 — visibility
        // is not verb-dependent). $kanoUser holds `bcms.plan.manage` too, so
        // the 404 below is provably the binding filter, not a permission
        // short-circuit: SubstituteBindings runs ahead of `permission:` in the
        // web middleware stack, so a permission failure would 403, not 404.
        $this->actingAs($kanoUser)
            ->patch(route('bcms.plans.update', $lagosPlan), ['title' => 'Hijacked'])
            ->assertNotFound();
        $this->assertSame('Lagos BCP', $lagosPlan->fresh()->title);

        $this->actingAs($lagosUser)->get(route('bcms.plans.show', $lagosPlan))->assertOk();
    }

    #[Test]
    public function a_kano_user_opening_a_lagos_call_tree_by_uuid_gets_404_and_a_same_unit_user_succeeds(): void
    {
        $lagosTree = CallTree::query()->create([
            'name' => 'Lagos Branch Tree', 'tree_type' => CallTreeType::Department->value,
            'version' => '1.0', 'status' => 'draft', 'review_frequency_days' => 180,
            'source' => 'manual', 'business_unit_id' => $this->lagos->id,
        ]);

        $kanoUser = $this->userWith(['bcms.calltree.view'], 'kano-tree@khb.test', $this->kano);
        $lagosUser = $this->userWith(['bcms.calltree.view'], 'lagos-tree@khb.test', $this->lagos);

        $this->actingAs($kanoUser)->get(route('bcms.call-trees.show', $lagosTree))->assertNotFound();
        $this->actingAs($lagosUser)->get(route('bcms.call-trees.show', $lagosTree))->assertOk();
    }

    #[Test]
    public function a_kano_user_opening_a_lagos_process_by_uuid_gets_404_and_a_same_unit_user_succeeds(): void
    {
        $lagosProcess = Process::query()->create([
            'code' => 'LAG-001', 'name' => 'Lagos Process', 'status' => 'active',
            'business_unit_id' => $this->lagos->id,
        ]);

        $kanoUser = $this->userWith(['bcms.strategy.view'], 'kano-process@khb.test', $this->kano);
        $lagosUser = $this->userWith(['bcms.strategy.view'], 'lagos-process@khb.test', $this->lagos);

        $this->actingAs($kanoUser)->get(route('bcms.strategy.show', $lagosProcess))->assertNotFound();
        $this->actingAs($lagosUser)->get(route('bcms.strategy.show', $lagosProcess))->assertOk();
    }

    #[Test]
    public function a_kano_user_closing_a_lagos_finding_gets_404_and_a_same_unit_user_succeeds(): void
    {
        $lagosFinding = Finding::query()->create([
            'reference' => 'F-LAG-1', 'classification' => 'observation', 'description' => 'Lagos finding',
            'status' => 'open', 'affected_business_unit_id' => $this->lagos->id,
        ]);

        $kanoUser = $this->userWith(['bcms.finding.manage'], 'kano-finding@khb.test', $this->kano);
        $lagosUser = $this->userWith(['bcms.finding.manage'], 'lagos-finding@khb.test', $this->lagos);

        $this->actingAs($kanoUser)->post(route('bcms.findings.close', $lagosFinding))->assertNotFound();
        $this->assertSame('open', $lagosFinding->fresh()->status);

        $this->actingAs($lagosUser)->post(route('bcms.findings.close', $lagosFinding))->assertRedirect();
    }

    #[Test]
    public function a_kano_user_updating_a_lagos_exercise_definition_gets_404_and_a_same_unit_user_succeeds(): void
    {
        $programme = ExerciseProgramme::query()->create(['year' => 2027, 'name' => 'Group Programme 2027']);
        $type = ExerciseType::query()->firstOrFail();

        $lagosDefinition = ExerciseDefinition::query()->create([
            'exercise_programme_id' => $programme->getKey(), 'exercise_type_id' => $type->getKey(),
            'name' => 'Lagos Fire Drill', 'business_unit_id' => $this->lagos->id,
        ]);

        $kanoUser = $this->userWith(['bcms.exercise.manage'], 'kano-def@khb.test', $this->kano);
        $lagosUser = $this->userWith(['bcms.exercise.manage'], 'lagos-def@khb.test', $this->lagos);

        $body = [
            'exercise_type_id' => $type->getKey(), 'name' => 'Renamed', 'frequency_per_year' => 1,
            'distribution_mode' => DistributionMode::Even->value, 'duration_minutes' => 120,
            'lead_time_days' => 10, 'min_notice_days' => 3,
        ];

        $this->actingAs($kanoUser)
            ->patch(route('bcms.exercise-definitions.update', $lagosDefinition), $body)
            ->assertNotFound();
        $this->assertSame('Lagos Fire Drill', $lagosDefinition->fresh()->name);

        $this->actingAs($lagosUser)
            ->patch(route('bcms.exercise-definitions.update', $lagosDefinition), $body)
            ->assertRedirect();
        $this->assertSame('Renamed', $lagosDefinition->fresh()->name);
    }

    #[Test]
    public function a_kano_user_updating_a_lagos_call_tree_gets_404_and_a_same_unit_user_succeeds(): void
    {
        // CallTree is the one anchor family that has BOTH a GET show route and
        // a mutating (PATCH) update route bound to the same model — the write
        // verb the ADR requires ("all verbs, including GET" implies the
        // reverse holds too: a write must 404 identically to the read) was
        // untested; the GET-only test above cannot stand in for it.
        $lagosTree = CallTree::query()->create([
            'name' => 'Lagos Branch Tree', 'tree_type' => CallTreeType::Department->value,
            'version' => '1.0', 'status' => 'draft', 'review_frequency_days' => 180,
            'source' => 'manual', 'business_unit_id' => $this->lagos->id,
        ]);

        $kanoUser = $this->userWith(['bcms.calltree.view', 'bcms.calltree.manage'], 'kano-tree-w@khb.test', $this->kano);
        $lagosUser = $this->userWith(['bcms.calltree.manage'], 'lagos-tree-w@khb.test', $this->lagos);

        $this->actingAs($kanoUser)
            ->patch(route('bcms.call-trees.update', $lagosTree), ['name' => 'Hijacked'])
            ->assertNotFound();
        $this->assertSame('Lagos Branch Tree', $lagosTree->fresh()->name);

        $this->actingAs($lagosUser)
            ->patch(route('bcms.call-trees.update', $lagosTree), ['name' => 'Renamed'])
            ->assertRedirect();
        $this->assertSame('Renamed', $lagosTree->fresh()->name);
    }

    /* ------------------------------------------------------------------ */
    /*  §4 point 5 / Amendment 1 — the named-user arm is a reviewed MAP, */
    /*  not a single class, and the anchor-path walk is not transitive. */
    /*  `no_model_but_exercise_occurrence_declares_a_named_user_visibility_arm` */
    /*  is retired (it pinned a fact only true because ReadinessTask had */
    /*  not been written yet) and replaced by the three assertions below. */
    /* ------------------------------------------------------------------ */

    /**
     * Amendment 1's map, restated as data so the three tests below can walk
     * it rather than repeat it. Every model here MUST also appear in
     * `BindsToVisibleRecord`'s derived set (§6.2) — this map is not a
     * separate classification, it is what a derived (or, in principle, an
     * anchor) model ORs on top of its own predicate.
     *
     * @var array<class-string<Model>, array{specs: list<string>, reason: string}>
     */
    private const NAMED_USER_VISIBILITY = [
        ExerciseOccurrence::class => [
            'specs' => ['facilitator_id', 'participants.user_id'],
            'reason' => "Phase 5's core loop: the T-10 invitation the product itself sent must not "
                .'404. The facilitator runs an occurrence in a unit they may not be assigned to.',
        ],
        ReadinessTask::class => [
            'specs' => ['owner_id', 'occurrence.facilitator_id'],
            'reason' => 'owner_id: ReadinessService.php:83 makes the facilitator the default owner, and a '
                .'reassignee must be able to act on their own task. occurrence.facilitator_id: the facilitator '
                .'holds bcms.readiness.override and answers for the gate — the person who decides whether the '
                .'exercise may start must be able to clear what blocks it, including a task owned by somebody else.',
        ],
        // Phase 9, closing a Gate 2 finding carried over from the Phase 7.5
        // code review (ADR 0017 Amendment 1 assigns it here): all three of
        // these anchor through `occurrence.definition`, exactly the shape
        // ReadinessTask does, and shipped with no named-user arm at all — a
        // cross-unit facilitator who reaches the occurrence through ITS OWN
        // arm 404s the moment they try to act on the AAR, an inject or the
        // exercise's evidence, because `constrainAnchorPath()`'s walk down
        // `occurrence.definition` only consults the DEFINITION's own
        // `scopeVisibleTo()` — it does not re-apply the occurrence's own
        // named-user arm partway through the chain.
        Aar::class => [
            'specs' => ['occurrence.facilitator_id'],
            'reason' => 'The facilitator drafts and edits the report (bcms.aar.manage) regardless of which '
                .'unit the occurrence sits in. The AAR\'s other actor, the approver, is not a stored id ahead of '
                .'approval — there is no approver_id column to name, only approved_by, written once approval '
                .'happens — so there is nothing to declare for that role. A participant does not act on the AAR '
                .'routes at all; they score objectives, on a different model.',
        ],
        Evidence::class => [
            'specs' => ['occurrence.facilitator_id'],
            'reason' => 'occurrence.facilitator_id: the facilitator manages the exercise\'s evidence regardless '
                .'of unit. uploaded_by is deliberately absent: every evidence route nests under '
                .'occurrences/{occurrence} with scopeBindings(), so the parent segment is resolved through '
                .'ExerciseOccurrence\'s own visibility first and an uploader who is not facilitator, participant '
                .'or in-unit 404s there before this arm is consulted — a same-unit-uploader test proved the fact '
                .'unreachable, so it is not claimed.',
        ],
        ExerciseInject::class => [
            'specs' => ['occurrence.facilitator_id'],
            'reason' => 'Only the facilitator releases an inject (bcms.exercise.facilitate); no other role '
                .'writes bcms_exercise_injects, so the occurrence\'s own facilitator is the one fact worth naming.',
        ],
    ];

    #[Test]
    public function the_named_user_visibility_map_is_pinned_and_asserted_whole(): void
    {
        $this->assertSame(
            [ExerciseOccurrence::class, ReadinessTask::class, Aar::class, Evidence::class, ExerciseInject::class],
            array_keys(self::NAMED_USER_VISIBILITY),
            'A model besides the five pinned here now declares a named-user arm — '
            .'ADR 0017 Amendment 1 pins this map exactly; widening it is a diff here with a reason, not a '
            .'silent addition to a model file.'
        );

        foreach (self::NAMED_USER_VISIBILITY as $model => $entry) {
            $this->assertNotSame('', trim($entry['reason']), "{$model} has no reason recorded.");

            $this->assertSame(
                $entry['specs'],
                (new $model)->orgVisibilityNamedUsers(),
                "{$model}'s declared orgVisibilityNamedUsers() no longer matches the pinned map — "
                .'update whichever one is stale.'
            );
        }
    }

    #[Test]
    public function every_named_user_visibility_spec_resolves_to_a_real_column(): void
    {
        // §6 point 2's `Finding::orgScopeColumn()` assertion, applied to the
        // other half of the trait: a spec naming a column that does not exist
        // throws on first use (`AppliesNamedUserVisibility::orNamedUserVisibility()`
        // builds `where`/`whereHas` straight off it) and only for the user it
        // was written to help — exactly the "uncalled contract" shape ADR
        // 0017 was written about.
        foreach (self::NAMED_USER_VISIBILITY as $model => $entry) {
            $instance = new $model;

            foreach ($entry['specs'] as $spec) {
                if (! str_contains($spec, '.')) {
                    $this->assertTrue(
                        Schema::hasColumn($instance->getTable(), $spec),
                        "{$model}'s named-user spec '{$spec}' names a column that does not exist on "
                        .$instance->getTable().'.'
                    );

                    continue;
                }

                [$relation, $column] = explode('.', $spec, 2);

                $this->assertTrue(
                    method_exists($instance, $relation),
                    "{$model}'s named-user spec '{$spec}' names a relation '{$relation}' that does not exist."
                );

                $related = $instance->{$relation}()->getRelated();

                $this->assertTrue(
                    Schema::hasColumn($related->getTable(), $column),
                    "{$model}'s named-user spec '{$spec}' names a column '{$column}' that does not exist on "
                    .$related->getTable().'.'
                );
            }
        }
    }

    #[Test]
    public function a_cross_unit_readiness_task_owner_can_complete_their_own_task_a_bystander_cannot(): void
    {
        [$occurrence] = $this->lagosOccurrenceFacilitatedFromKano();

        $owner = $this->userWith(['bcms.exercise.facilitate'], 'ready-owner@khb.test', $this->kano);
        $bystander = $this->userWith(['bcms.exercise.facilitate'], 'ready-bystander@khb.test', $this->kano);

        $task = ReadinessTask::query()->create([
            'occurrence_id' => $occurrence->getKey(), 'title' => 'Confirm dial-in numbers',
            'owner_id' => $owner->getKey(), 'due_offset_days' => 1, 'status' => 'open', 'is_blocking' => false,
        ]);

        // The bystander is assigned to the same unit as the owner (Kano),
        // neither of which is Lagos — so this 404 is provably the named-user
        // arm at work, not a plain unit mismatch.
        $this->actingAs($bystander)
            ->post(route('bcms.readiness-tasks.complete', $task))
            ->assertNotFound();
        $this->assertSame('open', $task->fresh()->status);

        $this->actingAs($owner)
            ->post(route('bcms.readiness-tasks.complete', $task))
            ->assertRedirect();
        $this->assertSame('complete', $task->fresh()->status);
    }

    #[Test]
    public function a_cross_unit_facilitator_can_override_a_blocking_readiness_task_a_bystander_cannot(): void
    {
        [$occurrence, $facilitator] = $this->lagosOccurrenceFacilitatedFromKano();

        $bystander = $this->userWith(['bcms.readiness.override'], 'override-bystander@khb.test', $this->kano);

        // The facilitator fixture only holds `bcms.exercise.facilitate`;
        // override is a separate grant (ReadinessController's own docblock:
        // "held by fewer people... because overriding is deciding to run an
        // exercise unprepared").
        $facilitator->givePermissionTo(Permission::findOrCreate('bcms.readiness.override', 'web'));

        $task = ReadinessTask::query()->create([
            'occurrence_id' => $occurrence->getKey(), 'title' => 'Confirm crisis room booked',
            'due_offset_days' => 1, 'status' => 'open', 'is_blocking' => true,
        ]);

        $this->actingAs($bystander)
            ->post(route('bcms.readiness-tasks.override', $task), ['reason' => 'Bystander should not reach this.'])
            ->assertNotFound();
        $this->assertSame('open', $task->fresh()->status);

        $this->actingAs($facilitator)
            ->post(route('bcms.readiness-tasks.override', $task), ['reason' => 'Crisis room double-booked; proceeding.'])
            ->assertRedirect();
        $this->assertSame('waived', $task->fresh()->status);
    }

    #[Test]
    public function a_cross_unit_participant_can_confirm_attendance_on_an_exercise_occurrence(): void
    {
        [$occurrence] = $this->lagosOccurrenceFacilitatedFromKano();

        $participant = $this->userWith(['bcms.exercise.view'], 'participant@khb.test', $this->kano);
        $bystander = $this->userWith(['bcms.exercise.view'], 'attendance-bystander@khb.test', $this->kano);

        // The invitation the product itself sent — materialised before the
        // T-10 reminder goes out, per ADR 0017 §4 point 5's own example.
        ExerciseParticipant::query()->create([
            'occurrence_id' => $occurrence->getKey(), 'user_id' => $participant->getKey(),
            'invitation_status' => 'invited',
        ]);

        $this->actingAs($bystander)
            ->post(route('bcms.occurrences.confirm-attendance', $occurrence), ['response' => 'accept'])
            ->assertNotFound();

        $this->actingAs($participant)
            ->post(route('bcms.occurrences.confirm-attendance', $occurrence), ['response' => 'accept'])
            ->assertRedirect();
    }

    /**
     * A Lagos-anchored occurrence facilitated by a Kano-assigned user —
     * cross-unit by construction, so any success below is provably the
     * named-user arm and not a plain unit match.
     *
     * @return array{0: ExerciseOccurrence, 1: User}
     */
    private function lagosOccurrenceFacilitatedFromKano(): array
    {
        $programme = ExerciseProgramme::query()->firstOrCreate(
            ['year' => 2027, 'name' => 'Named-User Programme 2027']
        );
        $type = ExerciseType::query()->firstOrFail();

        $definition = ExerciseDefinition::query()->create([
            'exercise_programme_id' => $programme->getKey(), 'exercise_type_id' => $type->getKey(),
            'name' => 'Named-User Drill', 'business_unit_id' => $this->lagos->id,
        ]);

        $facilitator = $this->userWith(['bcms.exercise.facilitate'], 'named-user-facilitator@khb.test', $this->kano);

        $occurrence = ExerciseOccurrence::query()->create([
            'definition_id' => $definition->getKey(), 'sequence_no' => 1,
            'facilitator_id' => $facilitator->getKey(), 'status' => 'planned',
        ]);

        return [$occurrence, $facilitator];
    }

    /* ------------------------------------------------------------------ */
    /*  Phase 9 / Gate 2 — Aar, Evidence and ExerciseInject each need their */
    /*  own named-user arm; the occurrence's arm does not carry through. */
    /* ------------------------------------------------------------------ */

    /**
     * `bcms.aars.update` is a STANDALONE route (no `occurrences/{occurrence}`
     * prefix, no `->scopeBindings()`) — it binds `Aar` directly, so success
     * here is provably `Aar::orgVisibilityNamedUsers()`'s own arm at work,
     * not an occurrence-level gate the request also had to clear.
     */
    #[Test]
    public function a_cross_unit_facilitator_can_update_their_own_aar_a_bystander_cannot(): void
    {
        [$occurrence, $facilitator] = $this->lagosOccurrenceFacilitatedFromKano();
        $facilitator->givePermissionTo(Permission::findOrCreate('bcms.aar.manage', 'web'));

        // Same unit as the facilitator (Kano), not Lagos — so a 404 here is
        // provably the named-user arm, not a plain unit mismatch, and a
        // success for the facilitator cannot be explained by ordinary
        // org-hierarchy visibility either.
        $bystander = $this->userWith(['bcms.aar.manage'], 'aar-bystander@khb.test', $this->kano);

        $aar = Aar::query()->create([
            'occurrence_id' => $occurrence->getKey(), 'status' => 'draft',
            'quantitative_results' => [], 'participant_feedback' => [],
        ]);

        $this->actingAs($bystander)
            ->patch(route('bcms.aars.update', $aar), ['summary' => 'Bystander should not reach this.'])
            ->assertNotFound();
        $this->assertNull($aar->fresh()->summary);

        $this->actingAs($facilitator)
            ->patch(route('bcms.aars.update', $aar), ['summary' => 'Facilitator can edit their own report.'])
            ->assertRedirect();
        $this->assertSame('Facilitator can edit their own report.', $aar->fresh()->summary);
    }

    /**
     * `bcms.occurrences.injects.release` nests under the occurrence and
     * `->scopeBindings()`'s the inject to it, so the parent occurrence
     * parameter resolves for the facilitator via ITS OWN arm first — but
     * `ExerciseInject::resolveRouteBindingQuery()` is then consulted
     * SEPARATELY for the inject itself, and `constrainAnchorPath()`'s walk
     * down `occurrence.definition` only asks the DEFINITION's own
     * `scopeVisibleTo()`, never the occurrence's `facilitator_id` arm. Before
     * this phase's fix the facilitator passed the parent gate and still
     * 404'd on the inject — exactly the Gate 2 finding.
     */
    #[Test]
    public function a_cross_unit_facilitator_can_release_their_own_inject_a_bystander_cannot(): void
    {
        [$occurrence, $facilitator] = $this->lagosOccurrenceFacilitatedFromKano();
        $bystander = $this->userWith(['bcms.exercise.facilitate'], 'inject-bystander@khb.test', $this->kano);

        $inject = ExerciseInject::query()->create([
            'occurrence_id' => $occurrence->getKey(), 'sequence' => 1,
            'release_offset_minutes' => 5, 'title' => 'A scripted event',
        ]);

        $this->actingAs($bystander)
            ->post(route('bcms.occurrences.injects.release', [$occurrence, $inject]))
            ->assertNotFound();
        $this->assertNull($inject->fresh()->released_at);

        $this->actingAs($facilitator)
            ->post(route('bcms.occurrences.injects.release', [$occurrence, $inject]))
            ->assertRedirect();
        $this->assertNotNull($inject->fresh()->released_at);
    }

    /**
     * `bcms.evidence.destroy` is the real write verb Evidence's own arm
     * protects: `.store` never binds an `Evidence` model at all (there is no
     * row yet), so it cannot exercise this fix. The row here is uploaded by a
     * THIRD user, neither the facilitator nor the bystander, so a facilitator
     * success is provably the `occurrence.facilitator_id` arm, not the
     * `uploaded_by` one.
     */
    #[Test]
    public function a_cross_unit_facilitator_can_delete_evidence_on_their_own_occurrence_a_bystander_cannot(): void
    {
        [$occurrence, $facilitator] = $this->lagosOccurrenceFacilitatedFromKano();
        $bystander = $this->userWith(['bcms.exercise.facilitate'], 'evidence-bystander@khb.test', $this->kano);
        $uploader = $this->userWith(['bcms.exercise.facilitate'], 'evidence-uploader@khb.test', $this->lagos);

        // `hash` is deliberately not fillable (Gate 2 advisory 8) — built
        // unsaved and `forceFill()`'d, the same one-INSERT shape
        // `EvidenceService::upload()` uses.
        $evidence = new Evidence([
            'occurrence_id' => $occurrence->getKey(), 'owner_type' => Evidence::KIND_OCCURRENCE,
            'owner_id' => $occurrence->getKey(), 'kind' => 'file', 'file_name' => 'sheet.txt',
            'file_path' => 'bcms/evidence/test/sheet.txt', 'mime' => 'text/plain', 'size' => 10,
            'uploaded_by' => $uploader->getKey(),
        ]);
        $evidence->forceFill(['hash' => str_repeat('a', 64)]);
        $evidence->save();

        $this->actingAs($bystander)
            ->delete(route('bcms.evidence.destroy', [$occurrence, $evidence]))
            ->assertNotFound();
        $this->assertNull($evidence->fresh()->deleted_at);

        $this->actingAs($facilitator)
            ->delete(route('bcms.evidence.destroy', [$occurrence, $evidence]))
            ->assertRedirect();
        $this->assertNotNull($evidence->fresh()->deleted_at);
    }

    /**
     * `uploaded_by` is NOT a named-user arm on Evidence, and this test pins
     * why: the evidence routes nest under `occurrences/{occurrence}` with
     * `scopeBindings()`, so an uploader who is neither the facilitator nor a
     * participant nor in-unit is refused at the parent segment. If someone
     * later declares `uploaded_by` as an arm without flattening the routes,
     * the arm is dead code — this test asserts the honest behaviour (404 for
     * both the uploader and the same-unit bystander) so the claim cannot be
     * re-made silently.
     */
    #[Test]
    public function an_uploader_who_is_not_facilitator_participant_or_in_unit_is_refused_at_the_occurrence_segment(): void
    {
        [$occurrence] = $this->lagosOccurrenceFacilitatedFromKano();

        $uploader = $this->userWith(['bcms.exercise.facilitate'], 'evidence-uploaded-by-uploader@khb.test', $this->kano);
        $bystander = $this->userWith(['bcms.exercise.facilitate'], 'evidence-uploaded-by-bystander@khb.test', $this->kano);

        $evidence = new Evidence([
            'occurrence_id' => $occurrence->getKey(), 'owner_type' => Evidence::KIND_OCCURRENCE,
            'owner_id' => $occurrence->getKey(), 'kind' => 'file', 'file_name' => 'photo.txt',
            'file_path' => 'bcms/evidence/test/photo.txt', 'mime' => 'text/plain', 'size' => 10,
            'uploaded_by' => $uploader->getKey(),
        ]);
        $evidence->forceFill(['hash' => str_repeat('b', 64)]);
        $evidence->save();

        $this->actingAs($bystander)
            ->delete(route('bcms.evidence.destroy', [$occurrence, $evidence]))
            ->assertNotFound();
        $this->assertNull($evidence->fresh()->deleted_at);

        $this->actingAs($uploader)
            ->delete(route('bcms.evidence.destroy', [$occurrence, $evidence]))
            ->assertNotFound();
        $this->assertNull($evidence->fresh()->deleted_at);
    }

    /* ------------------------------------------------------------------ */
    /*  §7 — an id in the request body gets the same predicate as the URL */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function findings_store_refuses_a_cross_unit_process_id_with_a_validation_error(): void
    {
        // ADR 0017 §7: FindingController::store's affected_process_id and
        // affected_plan_id were a bare `exists:table,id` — a cross-tenant
        // existence oracle. VisibleToUser must refuse an out-of-scope id with
        // an ordinary validation error, never a silent cross-unit create and
        // never a 404 (a 404 here would still be an existence oracle, just a
        // narrower one — the ADR's whole point is that this is a body id, not
        // a route id, so the answer has to be "invalid input").
        $lagosProcess = Process::query()->create([
            'code' => 'LAG-002', 'name' => 'Lagos Only Process', 'status' => 'active',
            'business_unit_id' => $this->lagos->id,
        ]);

        $kanoUser = $this->userWith(['bcms.finding.manage'], 'kano-finding-store@khb.test', $this->kano);

        $this->actingAs($kanoUser)->post(route('bcms.findings.store'), [
            'source' => 'audit',
            'classification' => 'observation',
            'description' => 'Attempted cross-unit finding',
            'affected_process_id' => $lagosProcess->getKey(),
        ])->assertSessionHasErrors('affected_process_id');

        $this->assertSame(0, Finding::query()->where('description', 'Attempted cross-unit finding')->count());

        // The same-unit id is accepted, proving the failure above is the
        // visibility predicate and not a shape/validation problem with the
        // field itself.
        $kanoProcess = Process::query()->create([
            'code' => 'KAN-002', 'name' => 'Kano Process', 'status' => 'active',
            'business_unit_id' => $this->kano->id,
        ]);

        $this->actingAs($kanoUser)->post(route('bcms.findings.store'), [
            'source' => 'audit',
            'classification' => 'observation',
            'description' => 'Same unit finding',
            'affected_process_id' => $kanoProcess->getKey(),
        ])->assertSessionDoesntHaveErrors('affected_process_id');

        $this->assertSame(1, Finding::query()->where('description', 'Same unit finding')->count());
    }

    /* ------------------------------------------------------------------ */
    /*  §4 — the three sanctioned cross-unit paths, plus the null arm */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_organisation_level_record_is_visible_to_a_user_assigned_to_nothing(): void
    {
        $groupPlan = Plan::query()->create([
            'plan_type' => PlanType::Cmp->value, 'title' => 'Group Crisis Plan', 'status' => 'draft',
            'version' => 1, 'content' => [], 'business_unit_id' => null,
        ]);

        $user = $this->userWith(['bcms.plan.view'], 'anyone@khb.test', $this->kano);

        $this->actingAs($user)->get(route('bcms.plans.show', $groupPlan))->assertOk();
    }

    #[Test]
    public function a_user_assigned_to_the_parent_unit_with_descendants_sees_the_childs_record(): void
    {
        $branchPlan = Plan::query()->create([
            'plan_type' => PlanType::Bcp->value, 'title' => 'Branch BCP', 'status' => 'draft',
            'version' => 1, 'content' => [], 'business_unit_id' => $this->groupBranch->id,
        ]);

        // Assigned to the PARENT, `includes_descendants` true — a divisional
        // head's assignment, per ADR 0017 §4 point 2.
        $divisionalHead = $this->userWith(['bcms.plan.view'], 'head@khb.test', $this->group);

        $this->actingAs($divisionalHead)->get(route('bcms.plans.show', $branchPlan))->assertOk();
    }

    #[Test]
    public function a_holder_of_all_units_sees_every_unit_s_record(): void
    {
        $lagosPlan = Plan::query()->create([
            'plan_type' => PlanType::Drp->value, 'title' => 'Lagos DRP', 'status' => 'draft',
            'version' => 1, 'content' => [], 'business_unit_id' => $this->lagos->id,
        ]);

        $cro = $this->userWith(['bcms.plan.view', RcsaScope::ALL_UNITS], 'cro@khb.test', null);

        $this->actingAs($cro)->get(route('bcms.plans.show', $lagosPlan))->assertOk();
    }

    /* ------------------------------------------------------------------ */
    /*  §5 — a nested child is constrained to ITS parent, not just visible */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_section_cannot_be_addressed_through_a_plan_that_is_not_its_own(): void
    {
        $ownPlan = Plan::query()->create([
            'plan_type' => PlanType::Bcp->value, 'title' => 'Kano Plan', 'status' => 'draft',
            'version' => 1, 'content' => [], 'business_unit_id' => $this->kano->id,
        ]);
        $otherPlan = Plan::query()->create([
            'plan_type' => PlanType::Bcp->value, 'title' => 'Also Kano Plan', 'status' => 'draft',
            'version' => 1, 'content' => [], 'business_unit_id' => $this->kano->id,
        ]);

        $section = PlanSection::query()->create([
            'plan_id' => $otherPlan->getKey(), 'section_key' => 'intro', 'title' => 'Intro',
            'sort_order' => 1, 'source_binding' => [],
        ]);

        $user = $this->userWith(['bcms.plan.manage'], 'kano-sections@khb.test', $this->kano);

        // Both plans are visible to this user (same unit). The section
        // belongs to $otherPlan, not $ownPlan — addressing it through the
        // wrong parent must still 404, which is exactly what `->scopeBindings()`
        // plus `resolveChildRouteBinding()`'s relation constraint buys.
        $this->actingAs($user)
            ->patch(route('bcms.plans.sections.update', ['plan' => $ownPlan, 'section' => $section]), [
                'title' => 'Hijacked section',
            ])
            ->assertNotFound();

        $this->actingAs($user)
            ->patch(route('bcms.plans.sections.update', ['plan' => $otherPlan, 'section' => $section]), [
                'title' => 'Correctly addressed',
            ])
            ->assertRedirect();

        $this->assertSame('Correctly addressed', $section->fresh()->title);
    }

    #[Test]
    public function a_kano_user_cannot_reach_a_lagos_plans_section_through_it(): void
    {
        $lagosPlan = Plan::query()->create([
            'plan_type' => PlanType::Bcp->value, 'title' => 'Lagos Plan', 'status' => 'draft',
            'version' => 1, 'content' => [], 'business_unit_id' => $this->lagos->id,
        ]);
        $section = PlanSection::query()->create([
            'plan_id' => $lagosPlan->getKey(), 'section_key' => 'intro', 'title' => 'Intro',
            'sort_order' => 1, 'source_binding' => [],
        ]);

        $kanoUser = $this->userWith(['bcms.plan.manage'], 'kano-lagos-section@khb.test', $this->kano);

        $this->actingAs($kanoUser)
            ->patch(route('bcms.plans.sections.update', ['plan' => $lagosPlan, 'section' => $section]), [
                'title' => 'Hijacked',
            ])
            ->assertNotFound();
        $this->assertSame('Intro', $section->fresh()->title);
    }

    /* ------------------------------------------------------------------ */
    /*  Defect 2, gate 2 round 1 — the null arm applies to DERIVED models */
    /*  too, and does not gain a parent-must-exist predicate along the way */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_null_user_derived_binding_is_unaffected_by_a_soft_deleted_anchor(): void
    {
        $plan = Plan::query()->create([
            'plan_type' => PlanType::Bcp->value, 'title' => 'To Be Superseded', 'status' => 'draft',
            'version' => 1, 'content' => [], 'business_unit_id' => $this->lagos->id,
        ]);
        $section = PlanSection::query()->create([
            'plan_id' => $plan->getKey(), 'section_key' => 'intro', 'title' => 'Intro',
            'sort_order' => 1, 'source_binding' => [],
        ]);

        // A soft-deleted plan — OccurrenceGenerator::reschedule() does the
        // exercise-engine equivalent of this to an occurrence. Nothing in
        // this test acts as anyone, so Auth::user() is null: a queue worker,
        // the scheduler, a console command.
        $plan->delete();

        $query = PlanSection::query();
        (new PlanSection)->constrainToVisibleRecord($query);

        $this->assertNotNull(
            $query->where('id', $section->getKey())->first(),
            'A null-user (system/queue) context lost a derived row because its anchor was soft-deleted. '
            .'ADR 0017\'s Consequences say unauthenticated and system contexts are unaffected — wrapping the '
            .'query in whereHas() regardless adds an existence requirement that was never there before this '
            .'trait existed.'
        );
    }

    #[Test]
    public function an_all_units_holders_derived_binding_is_also_unaffected_by_a_soft_deleted_anchor(): void
    {
        $plan = Plan::query()->create([
            'plan_type' => PlanType::Bcp->value, 'title' => 'To Be Superseded', 'status' => 'draft',
            'version' => 1, 'content' => [], 'business_unit_id' => $this->lagos->id,
        ]);
        $section = PlanSection::query()->create([
            'plan_id' => $plan->getKey(), 'section_key' => 'intro', 'title' => 'Intro',
            'sort_order' => 1, 'source_binding' => [],
        ]);

        $plan->delete();

        $cro = $this->userWith([RcsaScope::ALL_UNITS], 'cro-null-arm@khb.test', null);

        $this->actingAs($cro);

        $query = PlanSection::query();
        (new PlanSection)->constrainToVisibleRecord($query);

        $this->assertNotNull(
            $query->where('id', $section->getKey())->first(),
            'A holder of rcsa_scope.all_units lost a derived row because its anchor was soft-deleted, which '
            .'is the same defect the null-user case above guards against.'
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Defect 4, gate 2 round 1 / ADR 0017 Amendment 2 — Finding was the */
    /*  one anchor no list scoped: the index, its two pickers and the */
    /*  summary counts. */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_findings_index_shows_a_kano_user_no_lagos_finding(): void
    {
        Finding::query()->create([
            'reference' => 'F-LAG-INDEX', 'classification' => 'observation', 'description' => 'Lagos only',
            'status' => 'open', 'affected_business_unit_id' => $this->lagos->id,
        ]);
        Finding::query()->create([
            'reference' => 'F-KANO-INDEX', 'classification' => 'observation', 'description' => 'Kano only',
            'status' => 'open', 'affected_business_unit_id' => $this->kano->id,
        ]);

        $kanoUser = $this->userWith(['bcms.finding.view'], 'kano-findings-index@khb.test', $this->kano);

        $this->actingAs($kanoUser)
            ->get(route('bcms.findings.index'))
            ->assertInertia(function ($page) {
                $references = array_column($page->toArray()['props']['findings']['data'], 'reference');

                $this->assertSame(['F-KANO-INDEX'], $references);
            });
    }

    #[Test]
    public function the_findings_index_pickers_do_not_offer_a_lagos_process_or_plan_to_a_kano_user(): void
    {
        $lagosProcess = Process::query()->create([
            'code' => 'LAG-PICK', 'name' => 'Lagos Picker Process', 'status' => 'active',
            'business_unit_id' => $this->lagos->id,
        ]);
        $kanoProcess = Process::query()->create([
            'code' => 'KANO-PICK', 'name' => 'Kano Picker Process', 'status' => 'active',
            'business_unit_id' => $this->kano->id,
        ]);
        $lagosPlan = Plan::query()->create([
            'plan_type' => PlanType::Bcp->value, 'title' => 'Lagos Picker Plan', 'status' => 'draft',
            'version' => 1, 'content' => [], 'business_unit_id' => $this->lagos->id,
        ]);
        $kanoPlan = Plan::query()->create([
            'plan_type' => PlanType::Bcp->value, 'title' => 'Kano Picker Plan', 'status' => 'draft',
            'version' => 1, 'content' => [], 'business_unit_id' => $this->kano->id,
        ]);

        $kanoUser = $this->userWith(['bcms.finding.view'], 'kano-findings-pickers@khb.test', $this->kano);

        $this->actingAs($kanoUser)
            ->get(route('bcms.findings.index'))
            ->assertInertia(function ($page) use ($kanoProcess, $lagosProcess, $kanoPlan, $lagosPlan) {
                $props = $page->toArray()['props'];

                $processIds = array_column($props['options']['processes'], 'id');
                $planIds = array_column($props['options']['plans'], 'id');

                $this->assertContains($kanoProcess->getKey(), $processIds);
                $this->assertNotContains($lagosProcess->getKey(), $processIds);
                $this->assertContains($kanoPlan->getKey(), $planIds);
                $this->assertNotContains($lagosPlan->getKey(), $planIds);
            });
    }

    #[Test]
    public function the_findings_summary_counts_a_kano_users_own_unit_only(): void
    {
        Finding::query()->create([
            'reference' => 'F-LAG-SUM', 'classification' => 'observation', 'description' => 'Lagos only',
            'status' => 'open', 'affected_business_unit_id' => $this->lagos->id,
        ]);
        Finding::query()->create([
            'reference' => 'F-KANO-SUM', 'classification' => 'observation', 'description' => 'Kano only',
            'status' => 'open', 'affected_business_unit_id' => $this->kano->id,
        ]);

        $kanoUser = $this->userWith(['bcms.finding.view'], 'kano-findings-summary@khb.test', $this->kano);

        $this->actingAs($kanoUser)
            ->get(route('bcms.findings.index'))
            ->assertInertia(fn ($page) => $page->where('summary.open', 1));
    }

    /* ------------------------------------------------------------------ */
    /*  Defect 4 continued — FindingService derives affected_business_unit_id */
    /*  at raise time (ADR 0017 Amendment 2), one test per source that */
    /*  carries a unit. */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_finding_raised_against_a_named_process_takes_that_processs_unit(): void
    {
        $process = Process::query()->create([
            'code' => 'DERIVE-PROC', 'name' => 'Derive Process', 'status' => 'active',
            'business_unit_id' => $this->lagos->id,
        ]);

        $finding = app(FindingService::class)->raise(
            FindingSource::Audit,
            FindingClassification::Observation,
            'Raised against a process',
            null,
            ['affected_process_id' => $process->getKey()],
        );

        $this->assertSame($this->lagos->id, $finding->affected_business_unit_id);
    }

    #[Test]
    public function a_finding_raised_against_a_named_plan_takes_that_plans_unit(): void
    {
        $plan = Plan::query()->create([
            'plan_type' => PlanType::Bcp->value, 'title' => 'Derive Plan', 'status' => 'draft',
            'version' => 1, 'content' => [], 'business_unit_id' => $this->lagos->id,
        ]);

        $finding = app(FindingService::class)->raise(
            FindingSource::PlanReview,
            FindingClassification::Observation,
            'Raised from a plan review',
            $plan,
        );

        $this->assertSame($this->lagos->id, $finding->affected_business_unit_id);
    }

    #[Test]
    public function a_finding_raised_from_an_aar_takes_its_occurrences_definitions_unit(): void
    {
        $programme = ExerciseProgramme::query()->firstOrCreate(['year' => 2028, 'name' => 'Derive AAR Programme']);
        $type = ExerciseType::query()->firstOrFail();
        $definition = ExerciseDefinition::query()->create([
            'exercise_programme_id' => $programme->getKey(), 'exercise_type_id' => $type->getKey(),
            'name' => 'Derive AAR Drill', 'business_unit_id' => $this->lagos->id,
        ]);
        $occurrence = ExerciseOccurrence::query()->create([
            'definition_id' => $definition->getKey(), 'sequence_no' => 1, 'status' => 'planned',
        ]);
        $aar = Aar::query()->create(['occurrence_id' => $occurrence->getKey(), 'status' => 'draft']);

        $finding = app(FindingService::class)->raise(
            FindingSource::Aar,
            FindingClassification::Observation,
            'Raised from an AAR',
            $aar,
        );

        $this->assertSame($this->lagos->id, $finding->affected_business_unit_id);
    }

    #[Test]
    public function a_finding_raised_from_a_call_tree_test_takes_the_trees_unit(): void
    {
        $tree = CallTree::query()->create([
            'name' => 'Derive Tree', 'tree_type' => CallTreeType::Department->value,
            'version' => '1.0', 'status' => 'draft', 'review_frequency_days' => 180,
            'source' => 'manual', 'business_unit_id' => $this->lagos->id,
        ]);
        $test = CallTreeTest::query()->create(['call_tree_id' => $tree->getKey()]);

        $finding = app(FindingService::class)->raise(
            FindingSource::CallTreeTest,
            FindingClassification::Observation,
            'Raised from a call tree test',
            $test,
        );

        $this->assertSame($this->lagos->id, $finding->affected_business_unit_id);
    }

    #[Test]
    public function a_finding_raised_with_no_unit_bearing_source_leaves_the_unit_null(): void
    {
        // `audit` and `gap_analysis` genuinely carry no unit — ADR 0017
        // Amendment 2: "leaves it null only when the source genuinely has no
        // unit." A null unit is organisation-level by ADR 0006's null arm,
        // which is correct here: an internal-audit finding with nothing
        // named is not yet attributable to one division.
        $finding = app(FindingService::class)->raise(
            FindingSource::Audit,
            FindingClassification::Observation,
            'A generic audit finding naming nothing',
        );

        $this->assertNull($finding->affected_business_unit_id);
    }

    /* ------------------------------------------------------------------ */

    /** @param list<string> $permissions */
    private function userWith(
        array $permissions,
        string $email = 'bc@khb.test',
        ?BusinessUnit $unit = null,
        bool $includesDescendants = true,
    ): User {
        $user = User::create([
            'name' => Str::title(Str::before($email, '@')), 'email' => $email,
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id,
            'business_unit_id' => $unit?->id, 'is_active' => true,
        ]);

        if ($permissions !== []) {
            $role = Role::findOrCreate('bcms-visibility-'.md5($email), 'web');

            foreach ($permissions as $name) {
                $role->givePermissionTo(Permission::findOrCreate($name, 'web'));
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
}
