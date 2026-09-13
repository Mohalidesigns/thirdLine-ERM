<?php

namespace Tests\Feature\Widgets;

use App\Models\BusinessUnit;
use App\Models\Dashboard;
use App\Models\WidgetDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * Migration Phase 2 — the builder's endpoints edit the DRAFT and only the
 * draft, and a GridStack change round-trips through POST …/layout in the
 * exact shape resources/js/widgets/builder.js always sent.
 */
class DashboardLayoutRoundTripTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private BusinessUnit $retail;

    private WidgetDefinition $widget;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        foreach (['hq.view', 'dashboard.view', 'dashboard.manage'] as $permission) {
            Permission::findOrCreate($permission);
        }
        $this->actor->givePermissionTo(['hq.view', 'dashboard.view', 'dashboard.manage']);

        $this->retail = BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'code' => 'BU-RT',
            'name' => 'Retail Banking',
            'is_active' => true,
        ]);

        $this->widget = WidgetDefinition::withoutGlobalScopes()->create([
            'organization_id' => null,
            'code' => 'wg-count',
            'name' => 'Risk Count',
            'widget_type' => 'kpi_tile',
            'query' => ['source' => 'risks', 'aggregate' => 'count'],
            'context_binding' => 'inherit_subtree',
            'period_binding' => 'selected',
            'is_system' => true,
        ]);
    }

    #[Test]
    public function a_gridstack_change_is_written_back_to_the_draft_layout(): void
    {
        $dashboard = $this->dashboard();

        $this->actingAs($this->actor)
            ->postJson(route('risk.dashboards.layout', $dashboard), [
                'tab' => 'main',
                'items' => [['position' => 0, 'x' => 6, 'y' => 2, 'w' => 6, 'h' => 4]],
            ])
            ->assertNoContent();

        $placement = $dashboard->fresh()->tabList()[0]['layout'][0];

        $this->assertSame([6, 2, 6, 4], [$placement['x'], $placement['y'], $placement['w'], $placement['h']]);
        $this->assertSame($this->widget->id, (int) $placement['widget_id'], 'the widget itself is untouched by a move');
    }

    #[Test]
    public function the_layout_endpoint_validates_its_shape(): void
    {
        $dashboard = $this->dashboard();

        $this->actingAs($this->actor)
            ->postJson(route('risk.dashboards.layout', $dashboard), ['tab' => 'main', 'items' => [['position' => 0, 'x' => 'left']]])
            ->assertUnprocessable();
    }

    #[Test]
    public function tabs_can_be_added_renamed_and_removed(): void
    {
        $dashboard = $this->dashboard();

        $this->actingAs($this->actor)->post(route('risk.dashboards.tabs.store', $dashboard), ['label' => 'Controls'])->assertRedirect();

        $tabs = $dashboard->fresh()->tabList();
        $this->assertCount(2, $tabs);
        $this->assertSame('Controls', $tabs[1]['label']);
        $code = $tabs[1]['code'];

        $this->actingAs($this->actor)->patch(route('risk.dashboards.tabs.update', [$dashboard, $code]), ['label' => 'Key Controls'])->assertRedirect();
        $this->assertSame('Key Controls', $dashboard->fresh()->tabList()[1]['label']);

        $this->actingAs($this->actor)->delete(route('risk.dashboards.tabs.destroy', [$dashboard, $code]))->assertRedirect();
        $this->assertCount(1, $dashboard->fresh()->tabList());
    }

    #[Test]
    public function widgets_can_be_added_titled_and_removed_on_a_tab(): void
    {
        $dashboard = $this->dashboard();

        $this->actingAs($this->actor)
            ->post(route('risk.dashboards.widgets.store', [$dashboard, 'main']), ['widget_id' => $this->widget->id])
            ->assertRedirect();
        $this->assertSame(2, $dashboard->fresh()->draftWidgetCount());

        $this->actingAs($this->actor)
            ->patch(route('risk.dashboards.widgets.update', [$dashboard, 'main', 1]), ['title' => 'Open risks'])
            ->assertRedirect();
        $this->assertSame('Open risks', $dashboard->fresh()->tabList()[0]['layout'][1]['overrides']['title']);

        $this->actingAs($this->actor)
            ->delete(route('risk.dashboards.widgets.destroy', [$dashboard, 'main', 1]))
            ->assertRedirect();
        $this->assertSame(1, $dashboard->fresh()->draftWidgetCount());
    }

    #[Test]
    public function settings_update_name_binding_and_roles(): void
    {
        $dashboard = $this->dashboard();
        $role = Role::findOrCreate('cro');

        $this->actingAs($this->actor)
            ->patch(route('risk.dashboards.update', $dashboard), ['name' => 'Retail Pack', 'role_ids' => [$role->id]])
            ->assertRedirect();

        $fresh = $dashboard->fresh();
        $this->assertSame('Retail Pack', $fresh->name);
        $this->assertSame([$role->id], array_map('intval', $fresh->role_ids));
    }

    #[Test]
    public function the_editor_page_carries_the_palette_and_the_placed_widgets(): void
    {
        $dashboard = $this->dashboard();

        $this->actingAs($this->actor)->get(route('risk.dashboards.edit', $dashboard))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboards/Edit')
                ->where('dashboard.id', $dashboard->id)
                ->where('activeTab', 'main')
                ->where('tabs.0.layout.0.widget.name', 'Risk Count')
                ->where('placedWidgetIds', [$this->widget->id])
                ->has('palette', fn (Assert $palette) => $palette->each(fn (Assert $group) => $group->has('category')->has('widgets')))
                ->has('urls.layout')
                ->has('urls.publish'));
    }

    #[Test]
    public function the_list_separates_live_from_draft(): void
    {
        $draft = $this->dashboard(['name' => 'Still Drafting']);
        $live = $this->dashboard(['name' => 'On Air', 'code' => 'dash-live']);
        $live->publish();

        $this->actingAs($this->actor)->get(route('risk.dashboards.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboards/Index')
                ->has('live', 1)
                ->where('live.0.name', 'On Air')
                ->has('drafts', 1)
                ->where('drafts.0.name', 'Still Drafting'));
    }

    #[Test]
    public function the_editor_endpoints_are_gated_on_dashboard_manage(): void
    {
        $dashboard = $this->dashboard();
        $this->actor->revokePermissionTo('dashboard.manage');

        $this->actingAs($this->actor)
            ->postJson(route('risk.dashboards.layout', $dashboard), ['tab' => 'main', 'items' => []])
            ->assertForbidden();
        $this->actingAs($this->actor)->post(route('risk.dashboards.publish', $dashboard))->assertForbidden();
    }

    #[Test]
    public function a_system_dashboard_cannot_be_edited_in_place(): void
    {
        $system = $this->dashboard(['code' => 'dash-system']);
        // BelongsToOrganization fills the tenant on create; a system row is made after the fact.
        \Illuminate\Support\Facades\DB::table('dashboards')->where('id', $system->id)->update(['organization_id' => null]);

        $this->actingAs($this->actor)
            ->postJson(route('risk.dashboards.layout', $system), ['tab' => 'main', 'items' => []])
            ->assertForbidden();
    }

    private function dashboard(array $attributes = []): Dashboard
    {
        return Dashboard::create(array_merge([
            'organization_id' => $this->organization->id,
            'code' => 'dash-'.str()->lower(str()->random(8)),
            'name' => 'Unit Dashboard',
            'object_type_id' => $this->retail->graphObject()->object_type_id,
            'role_ids' => null,
            'tabs' => [['code' => 'main', 'label' => 'Dashboard', 'layout' => [
                ['widget_id' => $this->widget->id, 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3, 'overrides' => []],
            ]]],
            'published_tabs' => null,
            'is_published' => false,
            'version' => 1,
        ], $attributes));
    }
}
