<?php

namespace Tests\Feature\Widgets;

use App\Models\Dashboard;
use App\Models\GraphObject;
use App\Models\ObjectType;
use App\Models\User;
use App\Models\WidgetDefinition;
use App\Services\Widgets\DashboardResolver;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\WidgetDashboardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * WP-12 — the seeder is the fix, so the seeder is what gets pinned.
 *
 * Business HQ was retired because the org tree said "No dashboard published
 * for Enterprise" on most nodes. Nothing about the widget engine was wrong:
 * WidgetDashboardSeeder ran its dashboard half only for the demo bank, and
 * dashboards.organization_id was NOT NULL, so a tenant that was not the demo
 * bank had zero rows in the table.
 *
 * These tests run the seeder against a tenant that is NOT the demo bank —
 * which is every real installation — and assert it comes out with a working
 * dashboard on every node.
 */
class SystemDashboardSeedTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        foreach (['hq.view', 'dashboard.view', 'risk.view'] as $permission) {
            Permission::findOrCreate($permission);
        }
        $this->actor->givePermissionTo(['hq.view', 'dashboard.view', 'risk.view']);
    }

    private function runWidgetSeeder(): void
    {
        // The seeder manages its own tenant context; clear ours so it starts
        // from the same place a real `db:seed` would.
        $organizationId = TenantContext::organizationIdOrNull();
        TenantContext::clear();

        try {
            (new WidgetDashboardSeeder)->run();
        } finally {
            if ($organizationId !== null) {
                TenantContext::set($organizationId);
            }
        }
    }

    #[Test]
    public function seeding_without_the_demo_bank_still_publishes_dashboards(): void
    {
        $this->assertSame(0, Dashboard::withoutGlobalScopes()->count());

        $this->runWidgetSeeder();

        $system = Dashboard::withoutGlobalScopes()->whereNull('organization_id')->get();

        $this->assertGreaterThan(0, $system->count(), 'A tenant that is not the demo bank got no dashboards.');
        $this->assertTrue(
            $system->every(fn (Dashboard $d) => $d->is_published),
            'A seeded system dashboard that is not published is invisible to DashboardResolver.'
        );
        $this->assertTrue(
            $system->contains(fn (Dashboard $d) => $d->object_type_id === null),
            'Without a type-agnostic default, any node type without its own composition falls to the empty state.'
        );
    }

    /**
     * The failure this whole work package exists to remove: a node type with
     * no composition of its own must still render something.
     */
    #[Test]
    public function every_node_type_resolves_to_a_dashboard(): void
    {
        $this->runWidgetSeeder();

        $resolver = app(DashboardResolver::class);

        $nodeTypes = ObjectType::withoutGlobalScopes()
            ->whereNull('organization_id')
            ->where('is_node_type', true)
            ->get();

        $this->assertGreaterThan(5, $nodeTypes->count(), 'The object type registry did not install.');

        $unresolved = [];

        foreach ($nodeTypes as $type) {
            $object = GraphObject::withoutGlobalScopes()->create([
                'organization_id' => $this->organization->id,
                'object_type_id' => $type->id,
                'name' => $type->code.' node',
                'code' => 'T-'.$type->id,
                'status' => 'active',
            ]);

            if ($resolver->resolveFor($object, $this->actor) === null) {
                $unresolved[] = $type->code;
            }
        }

        $this->assertSame([], $unresolved, 'These node types render "No dashboard published": '.implode(', ', $unresolved));
    }

    /**
     * The architecture claim, tested rather than asserted in a deck: one
     * widget library, many compositions. Every placement on every seeded
     * dashboard must point at a widget that exists and is visible to the
     * tenant — a dangling widget_id renders an error panel to the customer.
     */
    #[Test]
    public function every_seeded_placement_points_at_a_real_widget(): void
    {
        $this->runWidgetSeeder();

        $known = WidgetDefinition::withoutGlobalScopes()->pluck('id')->map(fn ($id) => (int) $id)->flip();

        $dangling = [];

        foreach (Dashboard::withoutGlobalScopes()->get() as $dashboard) {
            foreach ($dashboard->tabList() as $tab) {
                foreach ($tab['layout'] as $placement) {
                    $id = (int) ($placement['widget_id'] ?? 0);

                    if (! $known->has($id)) {
                        $dangling[] = $dashboard->code.'/'.$tab['code'].'#'.$id;
                    }
                }
            }
        }

        $this->assertSame([], $dangling, 'Dangling widget placements: '.implode(', ', $dangling));
    }

    /**
     * A system dashboard must reference only system widgets. Referencing a
     * tenant widget would render an error panel for every OTHER tenant, and
     * the failure would only show up in the tenant that did not seed it.
     */
    #[Test]
    public function system_dashboards_reference_only_system_widgets(): void
    {
        $this->runWidgetSeeder();

        $tenantWidgetIds = WidgetDefinition::withoutGlobalScopes()
            ->whereNotNull('organization_id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->flip();

        $leaks = [];

        foreach (Dashboard::withoutGlobalScopes()->whereNull('organization_id')->get() as $dashboard) {
            foreach ($dashboard->placedWidgetIds() as $id) {
                if ($tenantWidgetIds->has((int) $id)) {
                    $leaks[] = $dashboard->code.'#'.$id;
                }
            }
        }

        $this->assertSame([], $leaks, 'System dashboards referencing tenant widgets: '.implode(', ', $leaks));
    }

    /** Re-seeding must not duplicate rows, nor invalidate saved user layouts. */
    #[Test]
    public function reseeding_is_idempotent_and_does_not_bump_version(): void
    {
        $this->runWidgetSeeder();

        $before = Dashboard::withoutGlobalScopes()->get()->keyBy('code');

        $this->runWidgetSeeder();

        $after = Dashboard::withoutGlobalScopes()->get()->keyBy('code');

        $this->assertSame($before->count(), $after->count(), 'Re-seeding duplicated dashboards.');

        foreach ($before as $code => $dashboard) {
            $this->assertSame(
                $dashboard->version,
                $after[$code]->version,
                "Re-seeding bumped {$code}'s version, which discards every user's saved layout."
            );
        }
    }

    /**
     * The same widget definition, two nodes, two answers. This is the whole
     * bet — context-bound widgets — and it is what the retired surface was
     * the only screen for.
     */
    #[Test]
    public function one_dashboard_renders_each_node_in_its_own_context(): void
    {
        $this->runWidgetSeeder();

        $enterprise = ObjectType::withoutGlobalScopes()->whereNull('organization_id')->where('code', 'Enterprise')->firstOrFail();

        $dashboards = Dashboard::withoutGlobalScopes()
            ->whereNull('organization_id')
            ->where('object_type_id', $enterprise->id)
            ->get();

        $this->assertCount(1, $dashboards, 'Exactly one system dashboard should be composed for Enterprise.');

        $widgetIds = $dashboards->first()->placedWidgetIds();

        $this->assertNotEmpty($widgetIds);

        // Every widget it places is context-bound — none is pinned to a fixed
        // node, which would make the dashboard show the same numbers whatever
        // node the reader is standing on.
        $bindings = WidgetDefinition::withoutGlobalScopes()
            ->whereIn('id', $widgetIds)
            ->pluck('context_binding')
            ->unique()
            ->values()
            ->all();

        $this->assertSame([], array_intersect($bindings, ['fixed_node']), 'A seeded dashboard pins a widget to a fixed node.');
    }
}
