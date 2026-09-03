<?php

namespace Tests\Feature;

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

        $sidebar = $this->actingAs($this->actor)->get(self::SIDEBAR_PAGE);

        $sidebar->assertOk()
            ->assertSee('Administration')
            ->assertSee('href="'.route($routeName).'"', false);
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

        $sidebar = $this->actingAs($this->actor)->get(self::SIDEBAR_PAGE);

        foreach (self::ADMIN_SURFACES as $otherPermission => $otherRoute) {
            if ($otherPermission === $permission) {
                continue;
            }

            $sidebar->assertDontSee('href="'.route($otherRoute).'"', false);
        }
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

        $this->actingAs($this->actor)->get(self::SIDEBAR_PAGE)
            ->assertOk()
            ->assertSee('href="'.route($routeName).'"', false);

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
     * admin/settings is a prefix of admin/settings/sso, and admin/builder of
     * admin/builder/scoring-profiles. A naive str_starts_with lights both the
     * parent and the child at once; the highlight has to name one entry.
     */
    #[Test]
    public function nested_admin_paths_highlight_exactly_one_entry(): void
    {
        $this->actor->givePermissionTo(array_keys(self::ADMIN_SURFACES));

        $activeClass = 'font-semibold bg-[#D4AF37] text-[#1A365D]';

        $nested = [
            'admin.settings' => 'Settings',
            'admin.settings.sso' => 'SSO',
            'admin.builder' => 'Metadata Builder',
            'admin.builder.scoring-profiles' => 'Scoring Profiles',
        ];

        foreach ($nested as $routeName => $label) {
            $html = $this->actingAs($this->actor)->get(route($routeName))->assertOk()->getContent();

            $this->assertSame(
                1,
                substr_count($html, $activeClass),
                "Route [{$routeName}] highlights ".substr_count($html, $activeClass)
                .' sidebar entries; exactly one — '.$label.' — is correct.'
            );
        }
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
