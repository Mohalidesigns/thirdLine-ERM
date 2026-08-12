<?php

namespace Tests\Feature\Widgets;

use App\Models\BusinessUnit;
use App\Models\Dashboard;
use App\Models\Risk;
use App\Models\TreatmentPlan;
use App\Models\WidgetDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    /*  Business HQ */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function hq_requires_authentication_and_permission(): void
    {
        $this->get('/hq')->assertRedirect();

        $this->actor->revokePermissionTo('hq.view');
        $this->actingAs($this->actor)->get('/hq')->assertForbidden();
    }

    #[Test]
    public function hq_renders_the_published_dashboard_with_its_tab_set(): void
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

        Dashboard::create([
            'organization_id' => $this->organization->id,
            'code' => 'test-hq',
            'name' => 'Test HQ',
            'object_type_id' => null,
            'tabs' => [
                ['code' => 'main', 'label' => 'Overview', 'layout' => [
                    ['widget_id' => $widget->id, 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3],
                ]],
                ['code' => 'second', 'label' => 'Register', 'layout' => []],
            ],
            'is_published' => true,
        ]);

        $this->makeRisk(['business_unit_id' => $this->retail->id]);

        $node = $this->retail->graphObject();

        $response = $this->actingAs($this->actor)->get('/hq/'.$node->id);

        $response->assertOk()
            ->assertSee('Overview')
            ->assertSee('Register')
            ->assertSee('Retail Banking')
            ->assertSee('Business HQ');
    }

    #[Test]
    public function hq_does_not_resolve_another_tenants_node(): void
    {
        $node = $this->retail->graphObject();

        // A user from a different organization gets a 404, not the page.
        $this->bootDomainFixtures('Other Bank PLC');
        $this->actor->givePermissionTo(['hq.view']);

        $this->actingAs($this->actor)->get('/hq/'.$node->id)->assertNotFound();
    }

    #[Test]
    public function an_unpublished_dashboard_does_not_render(): void
    {
        Dashboard::create([
            'organization_id' => $this->organization->id,
            'code' => 'draft-hq',
            'name' => 'Draft',
            'tabs' => [['code' => 'main', 'label' => 'Draft tab', 'layout' => []]],
            'is_published' => false,
        ]);

        $response = $this->actingAs($this->actor)->get('/hq/'.$this->retail->graphObject()->id);

        $response->assertOk()->assertDontSee('Draft tab');
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

        $response = $this->actingAs($this->actor)->get('/my');

        $response->assertOk()
            ->assertSee('Overdue')
            ->assertSee('Recalibrate loan approval limits');
    }

    #[Test]
    public function my_is_empty_for_a_user_who_owes_nothing(): void
    {
        $response = $this->actingAs($this->actor)->get('/my');

        $response->assertOk()->assertSee('Nothing on your list');
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

    #[Test]
    public function search_requires_its_permission(): void
    {
        $this->actor->revokePermissionTo('search.view');

        $this->actingAs($this->actor)->get('/search?q=x')->assertForbidden();
    }
}
