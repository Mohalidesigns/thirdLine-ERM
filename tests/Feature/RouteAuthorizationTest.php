<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The build fails if any web or api route can be reached without an
 * authorization guard.
 *
 * This is the enforcement half of WP-00 TASK 2: applying `permission:` to 200+
 * routes once is easy, keeping it applied as routes are added is the part that
 * needs a test.
 */
class RouteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Routes that may legitimately carry no permission.
     *
     * Everything here is either part of the unauthenticated authentication
     * flow or per-user account security. Nothing that reads or writes risk
     * data belongs on this list.
     *
     * @var list<string>
     */
    private const ALLOWLIST_URIS = [
        '/',                        // redirect to the dashboard; renders nothing
        'up',                       // health check
        'login',
        'logout',
        'forgot-password',
        'reset-password',
        'reset-password/{token}',
        'mfa/verify',               // completing sign-in
        'mfa/setup',                // enrolling: gating this would make an
        'mfa/enable',               // MFA requirement impossible to satisfy
        'auth/sso/discover',        // pre-authentication by definition:
        'auth/sso/{slug}',          // these routes exist to establish
        'auth/sso/{slug}/callback', // who the user is
        'auth/sso/{slug}/acs',
        'auth/sso/{slug}/metadata',
    ];

    #[Test]
    public function every_web_and_api_route_has_an_authorization_guard(): void
    {
        $unguarded = [];

        foreach ($this->guardableRoutes() as $route) {
            $middleware = $route->gatherMiddleware();

            $hasGuard = collect($middleware)->contains(
                fn ($m) => is_string($m) && (
                    str_starts_with($m, 'permission:')
                    || str_starts_with($m, 'can:')
                    // SCIM callers are directory services, not users, so there
                    // is no role to check. The bearer token is the guard, and
                    // it also carries the tenant.
                    || $m === 'scim.auth'
                    || $m === \App\Http\Middleware\AuthenticateScim::class
                )
            );

            if (! $hasGuard) {
                $unguarded[] = implode('|', $route->methods()).' '.$route->uri();
            }
        }

        $this->assertSame(
            [],
            $unguarded,
            count($unguarded)." route(s) in the web/api groups have neither a `permission:` nor a `can:` middleware:\n  "
            .implode("\n  ", $unguarded)
        );
    }

    #[Test]
    public function every_guarded_route_references_a_permission_that_actually_exists(): void
    {
        // A typo in `permission:risk.veiw` fails closed, which is safe — but it
        // also silently locks every role out of that screen. Catch it here.
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $known = Permission::pluck('name')->all();
        $unknown = [];

        foreach ($this->guardableRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'permission:')) {
                    continue;
                }

                foreach (explode('|', substr($middleware, strlen('permission:'))) as $permission) {
                    if ($permission !== '' && ! in_array($permission, $known, true)) {
                        $unknown[$permission][] = $route->uri();
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $unknown,
            'Routes reference permissions that the seeder never creates: '.json_encode($unknown, JSON_PRETTY_PRINT)
        );
    }

    #[Test]
    public function every_seeded_permission_is_held_by_at_least_one_role(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $orphaned = Permission::doesntHave('roles')->pluck('name')->all();

        $this->assertSame(
            [],
            $orphaned,
            'These permissions exist but no role grants them, so the routes behind them are unreachable: '
            .implode(', ', $orphaned)
        );
    }

    #[Test]
    public function the_allowlist_only_covers_authentication_and_health_routes(): void
    {
        // Guards the guard: if someone adds a risk screen to the allowlist to
        // make the suite pass, this fails.
        $permitted = ['/', 'up', 'login', 'logout', 'forgot-password', 'reset-password', 'reset-password/{token}', 'mfa/verify', 'mfa/setup', 'mfa/enable',
            'auth/sso/discover', 'auth/sso/{slug}', 'auth/sso/{slug}/callback',
            'auth/sso/{slug}/acs', 'auth/sso/{slug}/metadata'];

        $this->assertSame(
            $permitted,
            self::ALLOWLIST_URIS,
            'The route authorization allowlist changed. Only unauthenticated auth-flow, '
            .'health and MFA-enrolment routes may appear on it.'
        );
    }

    /**
     * Routes in the web or api group, minus the allowlist.
     *
     * @return list<RoutingRoute>
     */
    private function guardableRoutes(): array
    {
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();

            $inGroup = collect($middleware)->contains(
                fn ($m) => is_string($m) && str_contains($m, 'Illuminate\\Session\\Middleware\\StartSession')
            ) || in_array('web', $route->middleware(), true) || in_array('api', $route->middleware(), true);

            if (! $inGroup) {
                continue;
            }

            if (in_array($route->uri(), self::ALLOWLIST_URIS, true)) {
                continue;
            }

            $routes[] = $route;
        }

        return $routes;
    }
}
