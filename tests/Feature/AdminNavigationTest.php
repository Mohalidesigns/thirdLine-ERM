<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * The Administration menu has to be wired, not decorative.
 *
 * Eight admin surfaces were routed and linked from nowhere, and the two that
 * were linked were gated on the super-admin role rather than on the permission
 * their route enforces — so a chief risk officer saw two entries out of ten and
 * a user holding, say, connector.view saw none at all.
 *
 * Every case below asserts both halves of the contract: the sidebar offers the
 * link to a user holding exactly one admin permission, and following that link
 * returns 200 rather than the 403 a role-gated menu produces.
 */
class AdminNavigationTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    /**
     * permission => route name, one row per Administration menu entry.
     *
     * This map is the test's source of truth and is checked for completeness
     * by admin_surfaces_are_all_covered_by_this_map(): a new admin surface
     * behind a new permission fails that test until it is listed here and
     * therefore until it is linked from the sidebar.
     *
     * @var array<string, string>
     */
    private const ADMIN_SURFACES = [
        // Access
        'admin.users' => 'admin.users.index',
        'admin.sso' => 'admin.settings.sso',
        // Configuration
        'admin.metadata' => 'admin.builder',
        'admin.scoring' => 'admin.builder.scoring-profiles',
        'admin.configuration' => 'admin.configuration',
        'admin.settings' => 'admin.settings',
        // Integration
        'webhook.view' => 'admin.webhooks.index',
        'api.tokens' => 'admin.api-tokens.index',
        'connector.view' => 'admin.connectors.index',
        'job.view' => 'admin.jobs.index',
        // Platform (migration Phase 0)
        'license.manage' => 'admin.license',
    ];

    /**
     * The two surfaces restored alongside the Administration menu. They live in
     * the top-level nav rather than under Administration, but they are wired
     * the same way and are worth the same assertion.
     *
     * @var array<string, string>
     */
    /** A Blade page every authenticated user can open — see setUp(). */
    private const SIDEBAR_PAGE = '/risk/dashboard';

    private const RESTORED_SURFACES = [
        'hq.view' => 'hq.index',
        'dashboard.manage' => 'risk.dashboards.index',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        foreach (array_keys(self::ADMIN_SURFACES + self::RESTORED_SURFACES) as $permission) {
            Permission::findOrCreate($permission);
        }

        // The page the sidebar is read from. Nothing about it is admin: it is
        // simply a screen every authenticated user can open, so the menu is
        // observed exactly as a real user would meet it. It was /my until
        // migration Phase 0 and /notifications until Phase 1 ported those pages
        // to Inertia; the Blade sidebar this test guards is now read from the
        // Command Centre, which stays on Blade until Phase 5. (The React sidebar
        // is covered, entry by entry, by NavigationPermissionGateTest.)
        Permission::findOrCreate('dashboard.view');
        $this->actor->givePermissionTo('dashboard.view');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function adminSurfaceProvider(): array
    {
        $cases = [];

        foreach (self::ADMIN_SURFACES as $permission => $routeName) {
            $cases[$permission] = [$permission, $routeName];
        }

        return $cases;
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function restoredSurfaceProvider(): array
    {
        $cases = [];

        foreach (self::RESTORED_SURFACES as $permission => $routeName) {
            $cases[$permission] = [$permission, $routeName];
        }

        return $cases;
    }

    /* ------------------------------------------------------------------ */
    /*  The link exists, and it opens */
    /* ------------------------------------------------------------------ */

    #[Test]
    #[DataProvider('adminSurfaceProvider')]
    public function each_admin_permission_reveals_its_menu_entry(string $permission, string $routeName): void
    {
        $this->actor->givePermissionTo($permission);

        $nav = $this->navigationFor($this->actor);

        $this->assertNotNull($nav['admin'] ?? null, 'The Administration section is absent from the navigation.');
        $this->assertContains(
            $this->pathOf($routeName),
            $this->adminUrls($nav),
            "The Administration menu does not link {$routeName}.",
        );
    }

    #[Test]
    #[DataProvider('adminSurfaceProvider')]
    public function each_admin_menu_entry_opens_for_the_permission_that_reveals_it(string $permission, string $routeName): void
    {
        $this->actor->givePermissionTo($permission);

        $this->actingAs($this->actor)
            ->get(route($routeName))
            ->assertOk();
    }

    /**
     * A permission must reveal its own entry and no other: this is the failure
     * the role gate produced in reverse — a menu wider than the grant behind it.
     */
    #[Test]
    #[DataProvider('adminSurfaceProvider')]
    public function an_admin_permission_reveals_no_other_surface(string $permission, string $routeName): void
    {
        $this->actor->givePermissionTo($permission);

        $urls = $this->adminUrls($this->navigationFor($this->actor));

        foreach (self::ADMIN_SURFACES as $otherPermission => $otherRoute) {
            if ($otherPermission === $permission) {
                continue;
            }

            $this->assertNotContains(
                $this->pathOf($otherRoute),
                $urls,
                "Holding {$permission} revealed {$otherRoute}, which it does not grant.",
            );
        }
    }

    /* ------------------------------------------------------------------ */

    /**
     * The navigation as the signed-in user receives it.
     *
     * THIS USED TO GREP THE SIDEBAR'S HTML for `href=\"...\"` off
     * /risk/dashboard. That page is Inertia as of Phase 5's criterion 7, so its
     * sidebar is React and the links live in the shared `navigation` prop
     * rather than in the server's HTML — the assertion was reading a document
     * that no longer contains them. Reading the prop is also the stricter test:
     * it is the actual contract between NavPresenter and the layout, and it
     * cannot pass on a link that happens to appear in some unrelated markup.
     *
     * @return array<string, mixed>
     */
    private function navigationFor(User $user): array
    {
        return $this->actingAs($user)
            ->get(self::SIDEBAR_PAGE)
            ->assertOk()
            ->inertiaProps('navigation');
    }

    /**
     * Every URL the Administration section links.
     *
     * @param  array<string, mixed>  $nav
     * @return list<string>
     */
    private function adminUrls(array $nav): array
    {
        // The Administration section nests its links inside labelled groups.
        return collect($nav['admin']['groups'] ?? [])
            ->flatMap(fn (array $group) => collect($group['items'] ?? [])->pluck('url'))
            ->values()
            ->all();
    }

    /**
     * Every URL the navigation links, across primary, sections and admin.
     *
     * @param  array<string, mixed>  $nav
     * @return list<string>
     */
    private function allNavUrls(array $nav): array
    {
        $sectionUrls = collect($nav['sections'] ?? [])
            ->flatMap(fn (array $section) => collect($section['items'] ?? [])->pluck('url'));

        return collect($nav['primary'] ?? [])->pluck('url')
            ->merge($sectionUrls)
            ->merge($this->adminUrls($nav))
            ->values()
            ->all();
    }

    /** A route's path, which is what NavPresenter puts in `url`. */
    private function pathOf(string $routeName): string
    {
        return (string) parse_url(route($routeName), PHP_URL_PATH);
    }

    #[Test]
    #[DataProvider('restoredSurfaceProvider')]
    public function each_restored_surface_is_linked_and_opens(string $permission, string $routeName): void
    {
        $this->assertTrue(
            Route::has($routeName),
            "Route [{$routeName}] is missing, so the sidebar entry for [{$permission}] cannot be reached."
        );

        $this->actor->givePermissionTo($permission);

        $this->assertContains(
            $this->pathOf($routeName),
            $this->allNavUrls($this->navigationFor($this->actor)),
            "The navigation does not link {$routeName}.",
        );

        $this->actingAs($this->actor)->get(route($routeName))->assertOk();
    }

    /* ------------------------------------------------------------------ */
    /*  No permission, no section */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_user_with_no_admin_permissions_sees_no_administration_section(): void
    {
        $response = $this->actingAs($this->actor)->get(self::SIDEBAR_PAGE);

        $response->assertOk()
            ->assertDontSee('Administration')
            ->assertDontSee('nav_administration');

        foreach (self::ADMIN_SURFACES as $routeName) {
            $response->assertDontSee('href="'.route($routeName).'"', false);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Highlighting */
    /* ------------------------------------------------------------------ */

    /**
     * Every Administration surface is served by Inertia.
     *
     * This replaces `nested_admin_paths_highlight_exactly_one_entry`, which
     * asserted that a prefix like /admin/builder did not light both the parent
     * entry and its child in the BLADE sidebar. That test could only cover
     * routes Blade still served, and the migration kept taking them away —
     * settings in Phase 6.2, the builder in 6.3, scoring profiles in 6.4, the
     * integrations group in 6.7 — until its last subject was gone.
     *
     * The bug it guarded is now impossible rather than merely absent: the React
     * sidebar's SectionItem matches on the EXACT path (AuthenticatedLayout's
     * isExact), so a parent and a child can never both be active. What is worth
     * asserting instead is the fact that made it impossible.
     */
    #[Test]
    public function every_admin_surface_is_served_by_inertia(): void
    {
        $stillBlade = array_values(array_filter(
            self::ADMIN_SURFACES,
            fn (string $route) => ! \App\Support\Migration\Ported::isRoute($route),
        ));

        $this->assertSame([], $stillBlade, 'These Administration routes are still rendered by Blade: '
            .implode(', ', $stillBlade));
    }

    /* ------------------------------------------------------------------ */
    /*  Completeness */
    /* ------------------------------------------------------------------ */

    /**
     * Every permission guarding a readable admin screen must appear in the map
     * above — which is to say, must be linked from the menu. Adding an
     * eleventh surface behind a new permission fails here until it is wired.
     */
    #[Test]
    public function admin_surfaces_are_all_covered_by_this_map(): void
    {
        $uncovered = [];

        foreach (Route::getRoutes() as $route) {
            /** @var RoutingRoute $route */
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            if (! str_starts_with($route->uri(), 'admin/')) {
                continue;
            }

            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'permission:')) {
                    continue;
                }

                foreach (explode('|', substr($middleware, strlen('permission:'))) as $permission) {
                    if (! array_key_exists($permission, self::ADMIN_SURFACES)) {
                        $uncovered[$permission] = $route->uri();
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $uncovered,
            'Admin permissions with no Administration menu entry: '
            .json_encode($uncovered, JSON_PRETTY_PRINT)
        );
    }
}
