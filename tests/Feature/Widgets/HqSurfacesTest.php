<?php

namespace Tests\Feature\Widgets;

use App\Models\BusinessUnit;
use App\Models\Dashboard;
use App\Models\Risk;
use App\Models\TreatmentPlan;
use App\Models\WidgetDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * WP-08 acceptance — the three navigation surfaces:
 *   /hq/{node} renders the dashboard bound to the node's type with its tabs;
 *   /my shows a user's real obligations, grouped by due bucket;
 *   /search returns permission-correct results.
 */
class HqSurfacesTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private BusinessUnit $retail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        foreach (['hq.view', 'my.view', 'search.view', 'risk.view', 'loss_event.view', 'dashboard.view', 'treatment.view'] as $permission) {
            Permission::findOrCreate($permission);
        }

        $this->actor->givePermissionTo(['hq.view', 'my.view', 'search.view', 'risk.view', 'treatment.view']);

        $this->retail = BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'code' => 'BU-RT',
            'name' => 'Retail Banking',
            'is_active' => true,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Business HQ — restored (WP-12) */
    /* ------------------------------------------------------------------ */

    /**
     * A dashboard with organization_id genuinely NULL.
     *
     * BelongsToOrganization's creating() hook stamps organization_id from
     * TenantContext whenever the attribute is null, and bootDomainFixtures()
     * sets a tenant — so passing 'organization_id' => null is not enough to
     * make a system row. bypass() is the same escape hatch the seeder uses.
     */
    private function makeSystemDashboard(array $attributes): Dashboard
    {
        return \App\Support\Tenancy\TenantContext::bypass(
            fn () => Dashboard::withoutGlobalScopes()->create($attributes),
            'test fixture: system dashboard',
        );
    }

    /**
     * These four assertions are the inverse of the ones that stood here while
     * the surface was retired. They are kept as one test on purpose: what was
     * withdrawn was the ROUTING, and this is the guard that it is back.
     */
    #[Test]
    public function the_hq_surfaces_are_routable_again(): void
    {
        $node = $this->retail->graphObject();

        $this->actingAs($this->actor)->get('/hq/'.$node->id)->assertOk();

        $this->assertTrue(\Illuminate\Support\Facades\Route::has('hq.index'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('hq.show'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('risk.dashboards.index'));
    }

    /** hq.view still gates the surface — restoring it restored the routes only. */
    #[Test]
    public function hq_requires_its_permission(): void
    {
        $node = $this->retail->graphObject();

        $stranger = \App\Models\User::create([
            'name' => 'No Permissions',
            'email' => 'stranger-'.$this->organization->id.'@example.test',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);

        $this->actingAs($stranger)->get('/hq/'.$node->id)->assertForbidden();
    }

    /**
     * The bug that got the surface retired.
     *
     * The org tree rendered "No dashboard published for Enterprise" on most
     * nodes. The cause was three layers down — dashboards.organization_id was
     * NOT NULL and the seeder only ran its dashboard half for the demo bank,
     * so a normal tenant had zero rows and DashboardResolver rightly returned
     * null every time. This test stands on the fixed behaviour: a node whose
     * type has no composition of its own falls through to the published
     * system default, and the empty state is not reached.
     */
    #[Test]
    public function a_node_with_no_dashboard_for_its_type_falls_through_to_the_system_default(): void
    {
        $widget = WidgetDefinition::withoutGlobalScopes()->create([
            'organization_id' => null,
            'code' => 'wg-sys-fallthrough',
            'name' => 'Active Risks',
            'widget_type' => 'kpi_tile',
            'query' => ['source' => 'risks', 'aggregate' => 'count'],
            'context_binding' => 'inherit_subtree',
            'period_binding' => 'selected',
            'is_system' => true,
        ]);

        $this->makeSystemDashboard([
            'organization_id' => null,          // a SYSTEM dashboard
            'code' => 'sys-default',
            'name' => 'Enterprise Risk Management',
            'object_type_id' => null,           // for any type with none of its own
            'role_ids' => null,
            'tabs' => [['code' => 'main', 'label' => 'Dashboard', 'layout' => [
                ['widget_id' => $widget->id, 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3, 'overrides' => []],
            ]]],
            'is_published' => true,
        ]);

        $node = $this->retail->graphObject();

        $response = $this->actingAs($this->actor)->get('/hq/'.$node->id);

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Hq/Show')
            ->where('dashboard.name', 'Enterprise Risk Management')
            ->has('payloads', 1));
    }

    /**
     * A tenant that composes its own dashboard for a type must not go on
     * seeing ours. This is the whole reason system dashboards are safe to
     * publish: they are a default, not a fixture.
     */
    #[Test]
    public function a_tenant_dashboard_shadows_the_system_one_for_the_same_type(): void
    {
        $widget = WidgetDefinition::withoutGlobalScopes()->create([
            'organization_id' => null,
            'code' => 'wg-sys-shadowed',
            'name' => 'Active Risks',
            'widget_type' => 'kpi_tile',
            'query' => ['source' => 'risks', 'aggregate' => 'count'],
            'context_binding' => 'inherit_subtree',
            'period_binding' => 'selected',
            'is_system' => true,
        ]);

        $layout = [['code' => 'main', 'label' => 'Dashboard', 'layout' => [
            ['widget_id' => $widget->id, 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3, 'overrides' => []],
        ]]];

        $this->makeSystemDashboard([
            'organization_id' => null,
            'code' => 'erm-hq',
            'name' => 'System Composition',
            'object_type_id' => null,
            'role_ids' => null,
            'tabs' => $layout,
            'is_published' => true,
        ]);

        Dashboard::withoutGlobalScopes()->create([
            'organization_id' => $this->organization->id,
            'code' => 'erm-hq',
            'name' => 'Our Own Composition',
            'object_type_id' => null,
            'role_ids' => null,
            'tabs' => $layout,
            'is_published' => true,
        ]);

        $node = $this->retail->graphObject();

        $response = $this->actingAs($this->actor)->get('/hq/'.$node->id);

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->where('dashboard.name', 'Our Own Composition'));
    }

    /**
     * role_ids = [] means "no role restriction", the same as NULL. Storing the
     * second and matching only the first made a published dashboard invisible
     * to every user with nothing on screen to say why.
     */
    #[Test]
    public function an_empty_role_list_means_every_role_not_no_role(): void
    {
        $widget = WidgetDefinition::withoutGlobalScopes()->create([
            'organization_id' => null,
            'code' => 'wg-sys-emptyroles',
            'name' => 'Active Risks',
            'widget_type' => 'kpi_tile',
            'query' => ['source' => 'risks', 'aggregate' => 'count'],
            'context_binding' => 'inherit_subtree',
            'period_binding' => 'selected',
            'is_system' => true,
        ]);

        Dashboard::withoutGlobalScopes()->create([
            'organization_id' => $this->organization->id,
            'code' => 'empty-roles',
            'name' => 'Reachable Anyway',
            'object_type_id' => null,
            'role_ids' => [],   // not null — the case that used to match nothing
            'tabs' => [['code' => 'main', 'label' => 'Dashboard', 'layout' => [
                ['widget_id' => $widget->id, 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3, 'overrides' => []],
            ]]],
            'is_published' => true,
        ]);

        $node = $this->retail->graphObject();

        $response = $this->actingAs($this->actor)->get('/hq/'.$node->id);

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->where('dashboard.name', 'Reachable Anyway'));
    }

    /** The builder is behind dashboard.manage, and hq.view is not enough. */
    #[Test]
    public function the_dashboard_builder_is_gated_on_dashboard_manage(): void
    {
        Permission::findOrCreate('dashboard.manage');

        $this->actingAs($this->actor)->get('/risk/dashboards')->assertForbidden();

        $this->actor->givePermissionTo('dashboard.manage');
        $this->actor->forgetCachedPermissions();

        $this->actingAs($this->actor->fresh())->get('/risk/dashboards')->assertOk();
    }

    /**
     * Retiring the surface must not take the rest of the product with it: the
     * dashboards it used to render are still readable, so restoring the routes
     * is all it takes to bring the screens back.
     */
    #[Test]
    public function the_widget_engine_and_its_data_survive_the_retirement(): void
    {
        $widget = WidgetDefinition::create([
            'organization_id' => $this->organization->id,
            'code' => 'hq-test-count',
            'name' => 'Active risks',
            'widget_type' => 'kpi_tile',
            'context_binding' => 'inherit_subtree',
            'period_binding' => 'selected',
            'query' => ['source' => 'risks'],
        ]);

        $dashboard = Dashboard::create([
            'organization_id' => $this->organization->id,
            'code' => 'test-hq',
            'name' => 'Test HQ',
            'object_type_id' => null,
            'tabs' => [
                ['code' => 'main', 'label' => 'Overview', 'layout' => [
                    ['widget_id' => $widget->id, 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3],
                ]],
            ],
            'is_published' => true,
        ]);

        $this->assertTrue($dashboard->fresh()->is_published);
        $this->assertSame([$widget->id], $dashboard->fresh()->placedWidgetIds());
    }

    /* ------------------------------------------------------------------ */
    /*  My Responsibilities */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function my_shows_real_obligations_grouped_by_due_bucket(): void
    {
        $risk = $this->makeRisk(['business_unit_id' => $this->retail->id]);

        TreatmentPlan::create([
            'organization_id' => $this->organization->id,
            'risk_id' => $risk->id,
            'strategy' => 'mitigate',
            'action_title' => 'Recalibrate loan approval limits',
            'owner_id' => $this->actor->id,
            'target_date' => now()->subDays(5)->toDateString(),
            'status' => 'in_progress',
            'progress_pct' => 40,
            'created_by' => $this->actor->id,
        ]);

        // /my renders through Inertia as of migration Phase 0: the obligation
        // arrives as a prop in the overdue bucket rather than as Blade text.
        $this->actingAs($this->actor)->get('/my')
            ->assertOk()
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->component('My/Index')
                ->has('queue.buckets.overdue', 1)
                ->where('queue.buckets.overdue.0.title', fn ($title) => str_contains($title, 'Recalibrate loan approval limits')));
    }

    #[Test]
    public function my_is_empty_for_a_user_who_owes_nothing(): void
    {
        $this->actingAs($this->actor)->get('/my')
            ->assertOk()
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->component('My/Index')
                ->where('queue.total_items', 0));
    }

    #[Test]
    public function non_risk_users_land_on_my_and_risk_users_on_the_dashboard(): void
    {
        $this->actor->givePermissionTo('dashboard.view');

        // With risk.view: the command centre.
        $this->actingAs($this->actor)->get('/')->assertRedirect('/risk/dashboard');

        $this->actor->revokePermissionTo('risk.view');

        $this->actingAs($this->actor)->get('/')->assertRedirect(route('my.index'));
    }

    /* ------------------------------------------------------------------ */
    /*  Global search */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function search_finds_objects_and_respects_module_permissions(): void
    {
        $this->makeRisk([
            'business_unit_id' => $this->retail->id,
            'title' => 'Naira devaluation exposure on trade book',
        ]);

        $this->makeLossEvent([
            'title' => 'Naira settlement failure loss',
            'business_unit_id' => $this->retail->id,
        ]);

        $response = $this->actingAs($this->actor)->getJson('/search/suggest?q=Naira');

        $response->assertOk();
        $names = collect($response->json('results'))->pluck('name');

        // risk.view is held → the risk is findable.
        $this->assertTrue($names->contains('Naira devaluation exposure on trade book'));
        // loss_event.view is NOT held → the loss event must not leak.
        $this->assertFalse($names->contains('Naira settlement failure loss'));
    }

    #[Test]
    public function search_results_link_somewhere_real(): void
    {
        $risk = $this->makeRisk([
            'business_unit_id' => $this->retail->id,
            'title' => 'Concentration limit breach risk',
        ]);

        $response = $this->actingAs($this->actor)->getJson('/search/suggest?q=Concentration');

        $result = collect($response->json('results'))
            ->first(fn (array $r) => $r['name'] === 'Concentration limit breach risk');

        $this->assertNotNull($result);
        $this->assertStringContainsString('/risk/register/'.$risk->id, $result['url']);
    }

    /**
     * Business HQ is back, so every node object has a destination again. An
     * Entity still goes to Scoping rather than HQ: a bank's legal-entity page
     * shows the things an entity is — licences, jurisdictions, ownership — and
     * a generic node dashboard does not. TYPE_MAP wins over the node fallback,
     * and that ordering is what this pins.
     */
    #[Test]
    public function entity_results_link_to_the_scoping_screen_rather_than_hq(): void
    {
        Permission::findOrCreate('entity.view');
        $this->actor->givePermissionTo('entity.view');

        $entityType = \App\Models\EntityType::firstOrCreate(
            ['organization_id' => $this->organization->id, 'code' => 'LE'],
            ['name' => 'Legal Entity', 'level' => 1]
        );

        $entity = \App\Models\Entity::create([
            'organization_id' => $this->organization->id,
            'entity_type_id' => $entityType->id,
            'entity_code' => 'ENT-SRCH',
            'name' => 'Searchable Holdings PLC',
            'status' => 'active',
            'level' => 0,
        ]);

        $result = collect(
            $this->actingAs($this->actor)
                ->getJson('/search/suggest?q=Searchable')
                ->json('results')
        )->first(fn (array $r) => $r['name'] === 'Searchable Holdings PLC');

        $this->assertNotNull($result, 'An entity should be findable in search.');
        $this->assertStringContainsString('/risk/scoping/'.$entity->id, (string) $result['url']);
        $this->assertStringNotContainsString('/hq', (string) $result['url']);
    }

    /**
     * The standing rule of this module: never offer a result you cannot open.
     */
    #[Test]
    public function no_search_result_is_returned_without_a_destination(): void
    {
        $this->makeRisk([
            'business_unit_id' => $this->retail->id,
            'title' => 'Concentration limit breach risk',
        ]);

        foreach (['a', 'e', 'Retail', 'risk'] as $term) {
            $results = $this->actingAs($this->actor)
                ->getJson('/search/suggest?q='.$term)
                ->json('results');

            foreach ($results as $result) {
                $this->assertNotEmpty($result['url'], "Result '{$result['name']}' has no destination.");
                $this->assertStringNotContainsString('/hq', $result['url']);
            }
        }
    }

    #[Test]
    public function search_requires_its_permission(): void
    {
        $this->actor->revokePermissionTo('search.view');

        $this->actingAs($this->actor)->get('/search?q=x')->assertForbidden();
    }
}
