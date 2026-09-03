<?php

namespace Tests\Feature\Widgets;

use App\Models\BusinessUnit;
use App\Models\Dashboard;
use App\Models\User;
use App\Models\WidgetDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * WP-13 acceptance — the draft/live split, and the three reasons Business HQ
 * renders nothing.
 *
 * The work package started from a pair of screenshots. The Dashboards list
 * showed one dashboard, green, "Published". Business HQ on the enterprise node
 * showed "No dashboard published for Enterprise". Both were true. Getting from
 * one to the other took reading four classes, because between them the screens
 * never said:
 *
 *   - that the dashboard was bound to Obligation, a type with no nodes;
 *   - that the layout being edited was the same one being served;
 *   - that a published dashboard can be invisible because of the viewer's
 *     roles rather than because it does not exist.
 *
 * Each test below is one of those three, plus the mechanics that make the
 * first two answerable.
 *
 * Migration Phase 2: the builder's actions are HTTP endpoints and the HQ page
 * is an Inertia component, so the assertions read the page's props — the
 * same facts, no longer smeared through rendered HTML.
 */
class DashboardPublishingTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private BusinessUnit $retail;

    private WidgetDefinition $widgetA;

    private WidgetDefinition $widgetB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        foreach (['hq.view', 'dashboard.view', 'dashboard.manage', 'risk.view'] as $permission) {
            Permission::findOrCreate($permission);
        }

        $this->actor->givePermissionTo(['hq.view', 'dashboard.view', 'dashboard.manage', 'risk.view']);

        $this->retail = BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'code' => 'BU-RT',
            'name' => 'Retail Banking',
            'is_active' => true,
        ]);

        $this->widgetA = $this->makeWidget('wg-live', 'Live Widget');
        $this->widgetB = $this->makeWidget('wg-draft-only', 'Draft Only Widget');
    }

    /* ------------------------------------------------------------------ */
    /*  The draft/live split */
    /* ------------------------------------------------------------------ */

    /**
     * THE bug this work package exists for.
     *
     * The builder autosaves on every add, move and resize. Before the split
     * there was one layout column, so a widget dropped onto a published
     * dashboard was on the board's HQ page before the mouse button came back
     * up. This is the guard that editing is now private until published.
     */
    #[Test]
    public function editing_a_published_dashboard_does_not_change_what_business_hq_renders(): void
    {
        $dashboard = $this->publishedDashboard();
        $node = $this->retail->graphObject();

        $this->addWidget($dashboard, $this->widgetB);

        $dashboard->refresh();

        $this->assertSame(2, $dashboard->draftWidgetCount(), 'the draft took the new widget');
        $this->assertSame(1, $dashboard->publishedWidgetCount(), 'the live layout did not');
        $this->assertTrue($dashboard->hasUnpublishedChanges());

        $this->actingAs($this->actor)->get('/hq/'.$node->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Hq/Show')
                ->has('payloads', 1)
                ->where('payloads.0.title', 'Live Widget'));
    }

    /** Publishing is the moment the draft becomes what everyone else sees. */
    #[Test]
    public function publishing_copies_the_draft_over_the_live_layout(): void
    {
        $dashboard = $this->publishedDashboard();
        $node = $this->retail->graphObject();

        $this->addWidget($dashboard, $this->widgetB);
        $this->actingAs($this->actor)->post(route('risk.dashboards.publish', $dashboard))->assertRedirect();

        $dashboard->refresh();

        $this->assertSame(2, $dashboard->publishedWidgetCount());
        $this->assertFalse($dashboard->hasUnpublishedChanges());

        $this->actingAs($this->actor)->get('/hq/'.$node->id)
            ->assertInertia(fn (Assert $page) => $page
                ->has('payloads', 2)
                ->where('payloads.1.title', 'Draft Only Widget'));
    }

    /**
     * Version is the staleness key for every user's saved layout override
     * (DashboardUserPref), so it must only move when the published
     * arrangement actually moves. The old publish() bumped unconditionally,
     * which meant a dashboard was born at v2 the first time it went live.
     */
    #[Test]
    public function version_bumps_on_republish_but_not_on_the_first_publish(): void
    {
        $dashboard = $this->draftDashboard();

        $dashboard->publish();
        $this->assertSame(1, $dashboard->fresh()->version, 'first publish keeps v1');

        $dashboard->publish();
        $this->assertSame(2, $dashboard->fresh()->version, 'republish retires stale overrides');
    }

    /** The way back out of a rearrangement you thought better of. */
    #[Test]
    public function discarding_changes_resets_the_draft_to_the_live_layout(): void
    {
        $dashboard = $this->publishedDashboard();

        $this->addWidget($dashboard, $this->widgetB);
        $this->actingAs($this->actor)->post(route('risk.dashboards.discard', $dashboard))->assertRedirect();

        $dashboard->refresh();

        $this->assertSame(1, $dashboard->draftWidgetCount());
        $this->assertFalse($dashboard->hasUnpublishedChanges());
    }

    /**
     * Unpublishing takes a dashboard off Business HQ. It must not destroy the
     * layout that was live, or republishing an untouched dashboard would
     * silently promote whatever the draft happens to hold.
     */
    #[Test]
    public function unpublishing_keeps_the_live_layout_intact(): void
    {
        $dashboard = $this->publishedDashboard();

        $this->actingAs($this->actor)->post(route('risk.dashboards.unpublish', $dashboard))->assertRedirect();

        $this->assertFalse($dashboard->fresh()->is_published);
        $this->assertSame(1, $dashboard->fresh()->publishedWidgetCount());
    }

    /**
     * Rows written before the split have published_tabs NULL. They must keep
     * rendering rather than going blank on the deploy that adds the column.
     */
    #[Test]
    public function a_published_row_with_no_live_layout_falls_back_to_its_draft(): void
    {
        $dashboard = $this->publishedDashboard();
        $dashboard->forceFill(['published_tabs' => null])->save();

        $node = $this->retail->graphObject();

        $this->actingAs($this->actor)->get('/hq/'.$node->id)
            ->assertInertia(fn (Assert $page) => $page->where('payloads.0.title', 'Live Widget'));
    }

    /* ------------------------------------------------------------------ */
    /*  Binding — "it publishes fine and appears nowhere" */
    /* ------------------------------------------------------------------ */

    /**
     * The Obligation case, refused at the only moment anyone is paying
     * attention. Obligation is not a node type: Business HQ walks the org
     * tree, so there is no page for such a dashboard to appear on, however
     * many Obligations exist.
     */
    #[Test]
    public function publishing_is_refused_when_the_binding_reaches_no_nodes(): void
    {
        $orphanType = $this->makeObjectType('ObligationTest', 'Obligation', isNodeType: false);

        $dashboard = $this->draftDashboard(['object_type_id' => $orphanType->id]);

        $this->actingAs($this->actor)->post(route('risk.dashboards.publish', $dashboard))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertFalse($dashboard->fresh()->is_published, 'a dashboard nobody could see was not published');
    }

    /** The count that would have made the original bug obvious. */
    #[Test]
    public function the_builder_reports_how_many_nodes_a_binding_reaches(): void
    {
        $dashboard = $this->draftDashboard();

        $this->actingAs($this->actor)->get(route('risk.dashboards.edit', $dashboard))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboards/Edit')
                ->where('binding.renderable', true)
                ->where('binding.node_count', fn ($count) => (int) $count >= 1)
                ->where('binding.name', $this->retail->graphObject()->objectType->name));
    }

    /* ------------------------------------------------------------------ */
    /*  Preview */
    /* ------------------------------------------------------------------ */

    /** A draft, on a real node, with that node's data — before anyone else sees it. */
    #[Test]
    public function preview_renders_the_draft_on_a_node_of_the_bound_type(): void
    {
        $dashboard = $this->publishedDashboard();
        $node = $this->retail->graphObject();

        $this->addWidget($dashboard, $this->widgetB);

        $this->actingAs($this->actor)->get('/hq/'.$node->id.'?preview='.$dashboard->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('preview.id', $dashboard->id)
                ->where('preview.hasUnpublishedChanges', true)
                ->has('payloads', 2)
                ->where('payloads.1.title', 'Draft Only Widget'));
    }

    /**
     * A preview that quietly fell back to the published dashboard would be
     * worse than none: the admin would sign off on a composition they never
     * actually saw.
     */
    #[Test]
    public function preview_is_refused_on_a_node_of_another_type(): void
    {
        $otherType = $this->makeObjectType('PolicyTest', 'Policy', isNodeType: true);

        $dashboard = $this->draftDashboard(['object_type_id' => $otherType->id, 'name' => 'Policy Pack']);
        $node = $this->retail->graphObject();

        $this->actingAs($this->actor)->get('/hq/'.$node->id.'?preview='.$dashboard->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('preview', null)
                ->where('previewRefused', fn ($text) => str_contains((string) $text, 'Pick a Policy node'))
                ->where('payloads', fn ($payloads) => ! collect($payloads)->contains('title', 'Draft Only Widget')));
    }

    /** Previewing unpublished work is an administrator's business, not a viewer's. */
    #[Test]
    public function preview_is_refused_to_a_user_who_cannot_manage_dashboards(): void
    {
        $dashboard = $this->publishedDashboard();

        $this->addWidget($dashboard, $this->widgetB);

        $viewer = User::create([
            'name' => 'Read Only',
            'email' => 'viewer-'.$this->organization->id.'@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);
        $viewer->givePermissionTo(['hq.view', 'risk.view']);

        $node = $this->retail->graphObject();

        $this->actingAs($viewer)->get('/hq/'.$node->id.'?preview='.$dashboard->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('preview', null)
                ->where('canManage', false)
                ->has('payloads', 1)
                ->where('payloads.0.title', 'Live Widget'));
    }

    /* ------------------------------------------------------------------ */
    /*  The empty state's three causes */
    /* ------------------------------------------------------------------ */

    /**
     * The worst of the three, because every other screen says things are
     * working. A dashboard published to three roles, viewed by someone
     * holding none of them, used to render "No dashboard published for
     * Enterprise" — a sentence that is false and sends the admin to build a
     * second dashboard they do not need.
     */
    #[Test]
    public function the_empty_state_says_when_a_dashboard_is_published_but_hidden_by_roles(): void
    {
        $role = \Spatie\Permission\Models\Role::findOrCreate('board-member');

        $this->publishedDashboard(['role_ids' => [$role->id]]);

        $node = $this->retail->graphObject();

        $this->actingAs($this->actor)->get('/hq/'.$node->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('dashboard', null)
                ->has('roleBlocked', 1)
                ->where('roleBlocked.0.role_names', fn ($names) => str_contains((string) $names, 'board-member'))
                ->where('draftsForType', []));
    }

    /** With genuinely nothing published, the offer is to publish, not to build. */
    #[Test]
    public function the_empty_state_offers_an_existing_draft_before_offering_to_create_one(): void
    {
        $this->draftDashboard(['name' => 'Half Finished Pack']);

        $node = $this->retail->graphObject();

        $this->actingAs($this->actor)->get('/hq/'.$node->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('dashboard', null)
                ->where('roleBlocked', [])
                ->where('draftsForType.0.name', 'Half Finished Pack')
                ->where('createUrl', fn ($url) => str_contains((string) $url, 'object_type_id='.$node->object_type_id)));
    }

    /* ------------------------------------------------------------------ */
    /*  Ownership */
    /* ------------------------------------------------------------------ */

    /**
     * "Save as template" inherited organization_id from its source. Copying a
     * system dashboard therefore produced another system dashboard, which
     * dashboard() then refused to open — the button made a row nobody could
     * edit.
     */
    #[Test]
    public function save_as_template_produces_a_tenant_owned_draft(): void
    {
        $dashboard = $this->publishedDashboard();

        $this->actingAs($this->actor)->post(route('risk.dashboards.duplicate', $dashboard))->assertRedirect();

        $copy = Dashboard::query()->where('name', 'like', '%(copy)')->firstOrFail();

        $this->assertSame((int) $this->organization->id, (int) $copy->organization_id);
        $this->assertFalse($copy->is_published);
        $this->assertNull($copy->published_tabs, 'a copy has never been live');
        $this->assertNotSame($dashboard->code, $copy->code);
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    private function addWidget(Dashboard $dashboard, WidgetDefinition $widget): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.dashboards.widgets.store', [$dashboard, 'main']), ['widget_id' => $widget->id])
            ->assertRedirect();
    }

    /**
     * A node type with no objects in it.
     *
     * `category` is NOT NULL on this table and 'governance' is what the
     * registry uses for the non-org-tree types (Policy, Obligation) these
     * tests are standing in for.
     */
    private function makeObjectType(string $code, string $name, bool $isNodeType): \App\Models\ObjectType
    {
        return \App\Models\ObjectType::create([
            'code' => $code,
            'name' => $name,
            'plural_name' => $name.'s',
            'category' => 'governance',
            'is_node_type' => $isNodeType,
            'is_system' => false,
        ]);
    }

    private function makeWidget(string $code, string $name): WidgetDefinition
    {
        return WidgetDefinition::withoutGlobalScopes()->create([
            'organization_id' => null,
            'code' => $code,
            'name' => $name,
            'widget_type' => 'kpi_tile',
            'query' => ['source' => 'risks', 'aggregate' => 'count'],
            'context_binding' => 'inherit_subtree',
            'period_binding' => 'selected',
            'is_system' => true,
        ]);
    }

    /** A dashboard bound to the node type Retail Banking actually is. */
    private function draftDashboard(array $attributes = []): Dashboard
    {
        return Dashboard::create(array_merge([
            'organization_id' => $this->organization->id,
            'code' => 'dash-'.str()->lower(str()->random(8)),
            'name' => 'Unit Dashboard',
            'object_type_id' => $this->retail->graphObject()->object_type_id,
            'role_ids' => null,
            'tabs' => [['code' => 'main', 'label' => 'Dashboard', 'layout' => [
                ['widget_id' => $this->widgetA->id, 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3, 'overrides' => []],
            ]]],
            'published_tabs' => null,
            'is_published' => false,
            'version' => 1,
        ], $attributes));
    }

    private function publishedDashboard(array $attributes = []): Dashboard
    {
        $dashboard = $this->draftDashboard($attributes);
        $dashboard->publish();

        return $dashboard->fresh();
    }
}
