<?php

namespace Tests\Feature\Bcms;

use App\Http\Middleware\Bcms\ClearAmbientTenantContext;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\ResolveTenant;

/**
 * ADR 0016 §5, phase-7-inbound-token-contract.md §4 AC 9 — the resolved-stack
 * guard.
 *
 * WHY THIS HAS TO BE ROUTER-LEVEL AND NOT AN HTTP FEATURE TEST.
 * `ValidateCsrfToken::runningUnitTests()` disables CSRF verification for the
 * whole suite, so a `postJson()` against a route that still carried the `web`
 * group's CSRF middleware would return 200 in every test and 419 for every
 * real gateway — exactly the defect Gate 2 found. The only assertion that can
 * fail when that middleware creeps back onto these routes is one that reads
 * the resolved middleware stack directly, before any request is dispatched.
 *
 * `Router::gatherRouteMiddleware()` is used rather than
 * `Route::gatherMiddleware()` because the latter returns group NAMES
 * (`web`, `bcms-webhook`) rather than the classes those groups resolve to —
 * a `bcms-webhook` group quietly filled with `web`'s members later would
 * still read as `['bcms-webhook']` and this guard would not see it.
 *
 * UPDATED FOR BCMS PHASE 7, GATE 2 ROUND 3 DEFECT 1. This file originally
 * asserted `ThirdLine\Platform\Tenancy\ResolveTenant` on the `bcms-webhook`
 * group — correct when it was written, and then overtaken the same day by
 * `bootstrap/app.php`'s swap to `App\Http\Middleware\Bcms\ClearAmbientTenantContext`
 * (see that class's own docblock): `ResolveTenant::handle()` opens with
 * `Auth::user()` unconditionally, and with `EncryptCookies` also removed from
 * this group, a forged `remember_me` cookie was no longer guaranteed to
 * decrypt to garbage before reaching `SessionGuard::recaller()` — one `users`
 * SELECT, as GROUP middleware, ahead of the per-route throttle, on an
 * endpoint with no credential at all. `ClearAmbientTenantContext` does the
 * one thing this group ever needed from `ResolveTenant` —
 * `TenantContext::clear()` — and nothing else. The assertions below were left
 * pinned to the retired class; this run updates them to the one actually on
 * the stack, and turns `ResolveTenant` from "deliberately not forbidden" into
 * forbidden, so putting it back reproduces the reported failure loudly rather
 * than silently.
 */
class Phase7WebhookMiddlewareStackTest extends TestCase
{
    /**
     * Gateway routes: no session, no cookies, no CSRF, and — since the Round
     * 3 swap — no `ResolveTenant` either. `ClearAmbientTenantContext` is
     * deliberately NOT on this forbidden list — see the positive assertion
     * below and `bootstrap/app.php`'s `bcms-webhook` group docblock for why
     * it belongs on this stack rather than off it.
     */
    public static function gatewayRouteNames(): array
    {
        return [
            'bcms.cascade.inbound' => ['bcms.cascade.inbound'],
            'bcms.alerts.reply' => ['bcms.alerts.reply'],
            'bcms.alerts.provider-status' => ['bcms.alerts.provider-status'],
        ];
    }

    #[Test]
    public function the_three_gateway_webhooks_carry_none_of_webs_session_or_csrf_machinery(): void
    {
        $forbidden = [
            StartSession::class,
            EncryptCookies::class,
            ShareErrorsFromSession::class,
            ValidateCsrfToken::class,
            // Reintroducing ResolveTenant on this group is exactly the Round
            // 3 defect 1 regression: it reads Auth::user() unconditionally,
            // which — with EncryptCookies also absent from this group — puts
            // a `users` SELECT ahead of the per-route throttle on every
            // request, including every one the limiter is about to reject.
            ResolveTenant::class,
        ];

        foreach (array_keys(self::gatewayRouteNames()) as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "Route [{$name}] is not registered.");

            $resolved = app('router')->gatherRouteMiddleware($route);

            foreach ($forbidden as $class) {
                $this->assertFalse(
                    $this->stackContains($resolved, $class),
                    "[{$name}] must not carry {$class}, but its resolved stack does: ".implode(', ', $resolved)
                );
            }
        }
    }

    /**
     * The positive half of the guard, and the important one: on the
     * session-less `bcms-webhook` group, `ClearAmbientTenantContext` is not
     * incidental — it is the one thing standing between
     * `InboundResponseHandler::recipientForNumber()`'s cross-tenant scan and
     * an ambient `TenantContext` left set by whatever ran earlier in the
     * process (a prior request in the same worker, a queued job, test
     * infrastructure). Removing it silently is exactly how BCMS Phase 7's
     * Gate 1 defect was created — a false SAFE recorded against somebody who
     * never replied, because the scope had quietly narrowed to one tenant.
     * This must fail loudly if anyone removes it again.
     */
    #[Test]
    public function the_three_gateway_webhooks_still_clear_ambient_tenant_context(): void
    {
        foreach (array_keys(self::gatewayRouteNames()) as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "Route [{$name}] is not registered.");

            $resolved = app('router')->gatherRouteMiddleware($route);

            $this->assertTrue(
                $this->stackContains($resolved, ClearAmbientTenantContext::class),
                "[{$name}] must carry ".ClearAmbientTenantContext::class.' to clear any ambient tenant '.
                'before the cross-tenant scan runs; its resolved stack does not: '.implode(', ', $resolved)
            );
        }
    }

    /**
     * Nothing performing I/O may run ahead of the per-route throttle — the
     * throttle is what stands between an unauthenticated endpoint and abuse,
     * and it must be cheap to reach.
     *
     * `EnsureFeatureEnabled` (a config read) and `ClearAmbientTenantContext`
     * are the only two things allowed ahead of it. `ClearAmbientTenantContext`
     * is a GROUP-level middleware and necessarily resolves ahead of these
     * ROUTE-level ones — reordering the framework's middleware priority list
     * would be a cross-cutting change nobody should make for a single test,
     * so this test widens its allow-list rather than asking for that. What it
     * does NOT do is take that on faith: the second half below verifies, by
     * reading `ClearAmbientTenantContext` itself, that its `handle()` is a
     * bare `TenantContext::clear()` — a property assignment — and never
     * reaches a query builder, an Eloquent call, the auth guard or the
     * database. That is the property this test protects; the allow-list is
     * only its proxy.
     */
    #[Test]
    public function nothing_but_the_feature_flag_check_precedes_the_throttle(): void
    {
        $allowedAhead = [
            \App\Http\Middleware\EnsureFeatureEnabled::class,
            ClearAmbientTenantContext::class,
        ];

        foreach (array_keys(self::gatewayRouteNames()) as $name) {
            $route = Route::getRoutes()->getByName($name);
            $resolved = app('router')->gatherRouteMiddleware($route);

            $throttleIndex = null;

            foreach ($resolved as $index => $middleware) {
                if (str_starts_with($middleware, \Illuminate\Routing\Middleware\ThrottleRequests::class)) {
                    $throttleIndex = $index;

                    break;
                }
            }

            $this->assertNotNull($throttleIndex, "[{$name}] has no throttle middleware at all.");

            foreach (array_slice($resolved, 0, $throttleIndex) as $ahead) {
                $isAllowed = collect($allowedAhead)->contains(
                    fn (string $class) => $ahead === $class || str_starts_with($ahead, $class.':')
                );

                $this->assertTrue(
                    $isAllowed,
                    "[{$name}] runs {$ahead} ahead of its throttle, and it is neither the feature-flag check "
                    .'nor ClearAmbientTenantContext.'
                );
            }
        }

        // The property the allow-list stands in for: `ClearAmbientTenantContext::handle()`
        // performs no I/O at all — it only clears the in-memory `TenantContext`
        // singleton before calling `$next($request)`. A structural check, per
        // AC 8's own reasoning: a timing assertion on CI is a flake, not a
        // guard. Unlike `ResolveTenant`, this class has no authenticated-user
        // branch to skip past — the whole method is the property under test.
        $reflection = new ReflectionClass(ClearAmbientTenantContext::class);
        $source = (string) file_get_contents((string) $reflection->getFileName());

        $handleStart = strpos($source, 'public function handle');
        $this->assertNotFalse($handleStart, 'Could not locate ClearAmbientTenantContext::handle() — has it been restructured?');

        $handleBody = substr($source, $handleStart);

        $this->assertStringContainsString(
            'TenantContext::clear()',
            $handleBody,
            'handle() must clear TenantContext before the throttle is reached.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/Auth::|DB::|::query\(\)|::find\(|::create\(|Eloquent|session\(/',
            $handleBody,
            'handle() performs I/O or reads the auth guard/session — that defeats the "cheap to reach ahead of '
            .'the throttle" guarantee this class exists to give: '.$handleBody
        );
    }

    /**
     * The complement (AC 9's second half): the two BROWSER cascade routes
     * are real Inertia pages behind a real session and must keep
     * `StartSession` and `ValidateCsrfToken` — the credential there is a
     * signed token in the URL PLUS the ordinary session/CSRF machinery for
     * the page that reads it, not a bare HMAC on an unauthenticated POST.
     * A regression here would be exactly backwards: hardening a page a real
     * browser renders while leaving the gateway webhooks exposed.
     */
    #[Test]
    public function the_two_browser_cascade_routes_still_carry_session_and_csrf(): void
    {
        $required = [StartSession::class, ValidateCsrfToken::class];

        foreach (['bcms.cascade.ack', 'bcms.cascade.ack.store'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "Route [{$name}] is not registered.");

            $resolved = app('router')->gatherRouteMiddleware($route);

            foreach ($required as $class) {
                $this->assertTrue(
                    $this->stackContains($resolved, $class),
                    "[{$name}] must carry {$class}, and its resolved stack does not: ".implode(', ', $resolved)
                );
            }
        }
    }

    /** @param  list<string>  $resolved */
    private function stackContains(array $resolved, string $class): bool
    {
        foreach ($resolved as $middleware) {
            if ($middleware === $class || str_starts_with($middleware, $class.':')) {
                return true;
            }
        }

        return false;
    }
}
