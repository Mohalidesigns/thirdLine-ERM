<?php

namespace Tests\Feature\Inertia;

use App\Presenters\NavPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * Migration Phase 0 — the sidebar mirrors the router, entry by entry.
 *
 * AdminNavigationTest generalised to the whole menu: every entry the
 * NavPresenter emits names a route, declares the permission that route's
 * `permission:` middleware enforces, and appears once. A menu entry can then
 * never lead to a 403, and a permission-gated surface can never be reachable
 * from the menu under a different grant than the router applies.
 */
class NavigationPermissionGateTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    /** The 23 module sections transcribed from the Blade sidebar. */
    private const SECTIONS = [
        'scoping', 'risk_register', 'rcsa', 'tprm', 'assessments', 'controls', 'treatment_plans', 'risk_appetite',
        'approvals', 'kri_monitoring', 'reporting_periods', 'loss_events', 'issues', 'campaigns', 'workflows',
        'analysis', 'quantification', 'regulatory', 'imports', 'documents', 'reports', 'emerging', 'ai_intelligence',
    ];

    #[Test]
    public function the_presenter_declares_exactly_the_23_sections(): void
    {
        $this->assertSame(self::SECTIONS, array_column(NavPresenter::sections(), 'key'));
    }

    #[Test]
    public function every_entry_names_a_route_that_exists(): void
    {
        foreach (NavPresenter::allItems() as $item) {
            $this->assertTrue(
                Route::has($item['route']),
                "Nav entry [{$item['label']}] in [{$item['section']}] names route [{$item['route']}], which does not exist."
            );
        }
    }

    #[Test]
    public function every_entrys_permission_equals_its_routes_permission_middleware(): void
    {
        $mismatches = [];

        foreach (NavPresenter::allItems() as $item) {
            $route = Route::getRoutes()->getByName($item['route']);
            $guards = $this->permissionGuards($route);

            if ($guards !== [$item['permission']]) {
                $mismatches[$item['route']] = ['nav' => $item['permission'], 'route' => $guards];
            }
        }

        $this->assertSame([], $mismatches, 'Nav entries whose permission differs from their route guard: '.json_encode($mismatches, JSON_PRETTY_PRINT));
    }

    #[Test]
    public function every_entrys_feature_flag_equals_its_routes_feature_middleware(): void
    {
        $mismatches = [];

        foreach (NavPresenter::allItems() as $item) {
            $route = Route::getRoutes()->getByName($item['route']);
            $flags = $this->featureGuards($route);
            $declared = isset($item['feature']) ? [$item['feature']] : [];

            if ($flags !== $declared) {
                $mismatches[$item['route']] = ['nav' => $declared, 'route' => $flags];
            }
        }

        $this->assertSame([], $mismatches, 'Nav entries whose feature flag differs from their route: '.json_encode($mismatches, JSON_PRETTY_PRINT));
    }

    #[Test]
    public function every_route_in_the_sections_appears_exactly_once(): void
    {
        $counts = [];

        foreach (NavPresenter::sections() as $section) {
            foreach ($section['items'] as $item) {
                $counts[$item['route']] = ($counts[$item['route']] ?? 0) + 1;
            }
        }

        $duplicates = array_filter($counts, fn (int $n) => $n > 1);

        $this->assertSame([], $duplicates, 'Routes linked from more than one section: '.json_encode($duplicates));
        $this->assertCount(95, $counts, 'The 23 sections link 95 distinct routes; the count moved, so a section changed shape.');
    }

    #[Test]
    public function every_permission_on_a_readable_admin_route_has_a_menu_entry(): void
    {
        $linked = collect(NavPresenter::adminGroups())
            ->flatMap(fn (array $group) => array_column($group['items'], 'permission'))
            ->unique()
            ->all();

        $uncovered = [];

        foreach (Route::getRoutes() as $route) {
            /** @var RoutingRoute $route */
            if (! in_array('GET', $route->methods(), true) || ! str_starts_with($route->uri(), 'admin/')) {
                continue;
            }

            foreach ($this->permissionGuards($route) as $permission) {
                if (! in_array($permission, $linked, true)) {
                    $uncovered[$permission] = $route->uri();
                }
            }
        }

        $this->assertSame([], $uncovered, 'Admin permissions with no Administration menu entry: '.json_encode($uncovered));
    }

    #[Test]
    public function a_user_sees_only_the_entries_their_permissions_allow(): void
    {
        $this->bootDomainFixtures();

        foreach (['my.view', 'kri.view', 'admin.users'] as $permission) {
            Permission::findOrCreate($permission);
        }
        $this->actor->givePermissionTo(['my.view', 'kri.view', 'admin.users']);

        $this->actingAs($this->actor)->get('/my')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('navigation.primary', fn ($primary) => collect($primary)->pluck('route')->all() === ['my.index'])
                ->where('navigation.sections', function ($sections) {
                    $sections = collect($sections);

                    return $sections->pluck('key')->all() === ['kri_monitoring']
                        && $sections->first()['items'] !== []
                        && collect($sections->first()['items'])->every(fn ($item) => $item['permission'] === 'kri.view');
                })
                ->where('navigation.admin.groups', fn ($groups) => collect($groups)->flatMap(fn ($g) => collect($g['items'])->pluck('route'))->all() === ['admin.users.index'])
            );
    }

    #[Test]
    public function a_user_with_no_admin_permission_gets_no_administration_section(): void
    {
        $this->bootDomainFixtures();
        Permission::findOrCreate('my.view');
        $this->actor->givePermissionTo('my.view');

        $this->actingAs($this->actor)->get('/my')
            ->assertInertia(fn (Assert $page) => $page->where('navigation.admin', null));
    }

    #[Test]
    public function a_feature_flagged_section_is_withheld_while_the_flag_is_off(): void
    {
        $this->bootDomainFixtures();
        foreach (['my.view', 'ai.view'] as $permission) {
            Permission::findOrCreate($permission);
        }
        $this->actor->givePermissionTo(['my.view', 'ai.view']);

        config(['features.ai_intelligence' => false]);
        $this->actingAs($this->actor)->get('/my')
            ->assertInertia(fn (Assert $page) => $page
                ->where('navigation.sections', fn ($sections) => ! collect($sections)->pluck('key')->contains('ai_intelligence')));

        config(['features.ai_intelligence' => true]);
        $this->actingAs($this->actor)->get('/my')
            ->assertInertia(fn (Assert $page) => $page
                ->where('navigation.sections', fn ($sections) => collect($sections)->pluck('key')->contains('ai_intelligence')));
    }

    /**
     * @return list<string>
     */
    private function permissionGuards(RoutingRoute $route): array
    {
        $found = [];

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'permission:')) {
                foreach (explode('|', substr($middleware, strlen('permission:'))) as $permission) {
                    $found[] = $permission;
                }
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private function featureGuards(RoutingRoute $route): array
    {
        $found = [];

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'feature:')) {
                $found[] = substr($middleware, strlen('feature:'));
            }
        }

        return $found;
    }
}
