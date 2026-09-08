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
        // The BCMS calendar subscription feed. Outlook and Google fetch a
        // subscribed calendar with no cookie and no bearer token, so a
        // `permission:` middleware could never pass — the SIGNATURE is the
        // credential, per user and tamper-evident, and `ValidateSignature`
        // rejects anything else before the controller runs. What it exposes is
        // one user's own calendar. Phase4ScreensTest covers the tampered-URL,
        // disabled-account and cross-tenant cases in detail.
        'bcms/calendar/{user}/calendar.ics',

        // BCMS Phase 6 — the three cascade acknowledgement routes, and the
        // second member of the signed-capability category the calendar feed
        // opened. The credential is a 16-character HMAC of the test-node
        // id, compared with `hash_equals` and unguessable without the app
        // key; the controller resolves the tenant from the node before it
        // reads anything, because `OrganizationScope` is inert untenanted.
        //
        // WHY NOT `signed`. The URL travels in an SMS. A Laravel signed URL
        // is ~120 characters of query string, which pushes a 160-character
        // message into two segments and doubles the cost of every cascade —
        // and the security property is identical, an unguessable
        // capability in the URL. The short form is a cost decision, not a
        // weaker one.
        //
        // The inbound webhook is the one that cannot carry a per-user
        // credential at all: a gateway posts to it. It is throttled, it
        // matches a token inside the body, and it answers an unmatched
        // reply with `matched: false` rather than an error a gateway would
        // retry. PROVIDER SIGNATURE VERIFICATION IS PHASE 7'S, with the
        // real adapters that know each provider's scheme.
        'bcms/cascade/{token}',
        'bcms/cascade-inbound',
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
                    // WP-07. `scope:` is the API's equivalent of `permission:`
                    // and is STRICTLY STRONGER: it requires the token's scope
                    // AND the permission of the user behind it. `permission:`
                    // cannot be used on these routes because a machine token
                    // has no user for it to check.
                    // `scope.resource` derives the permission from the
                    // {resource} in the path, which the generic routes need
                    // because one definition serves /risks and /loss-events.
                    // ApiAuthorizationTest checks these separately and in more
                    // detail than this test can.
                    || str_starts_with($m, 'scope:')
                    || $m === 'scope.resource'
                    || $m === \App\Http\Middleware\EnsureResourceScope::class
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
                if (! is_string($middleware)) {
                    continue;
                }

                // WP-07: `scope:` names the same permissions as `permission:`,
                // so a typo in one is exactly as damaging as in the other.
                $prefix = match (true) {
                    str_starts_with($middleware, 'permission:') => 'permission:',
                    str_starts_with($middleware, 'scope:') => 'scope:',
                    default => null,
                };

                if ($prefix === null) {
                    continue;
                }

                foreach (explode('|', substr($middleware, strlen($prefix))) as $permission) {
                    foreach (explode(',', $permission) as $one) {
                        if ($one !== '' && ! in_array($one, $known, true)) {
                            $unknown[$one][] = $route->uri();
                        }
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
            'auth/sso/{slug}/acs', 'auth/sso/{slug}/metadata',
            // BCMS Phase 4. A SIGNED capability URL rather than an
            // unauthenticated one: `ValidateSignature` is the guard, and a
            // `permission:` middleware is impossible because the client is a
            // calendar application that sends no session. It is the only
            // member of that category so far; a second one should have to
            // argue for itself here.
            'bcms/calendar/{user}/calendar.ics',
            // BCMS Phase 6. The same category, and the argument is in the
            // constant above: an unguessable HMAC capability in the path
            // rather than a signed query string, because the URL travels in an
            // SMS and a signed one would double the cost of every cascade. The
            // inbound webhook is a gateway callback that can carry no per-user
            // credential; it is throttled and Phase 7 adds provider signature
            // verification with the real adapters.
            'bcms/cascade/{token}',
            'bcms/cascade-inbound'];

        $this->assertSame(
            $permitted,
            self::ALLOWLIST_URIS,
            'The route authorization allowlist changed. Only unauthenticated auth-flow, health, '
            .'MFA-enrolment and signed capability routes may appear on it — and a signed one has to '
            .'be a route a browser session could never reach.'
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
