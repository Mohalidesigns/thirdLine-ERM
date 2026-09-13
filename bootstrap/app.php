<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        // No prefix: RFC 7644 puts SCIM at /scim/v2, and directory services
        // are configured with that base URL. routes/api.php declares its own
        // paths in full.
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',

        /*
         * The vendor portal — TPRM Phase 8, FR-PRT-01.
         *
         * Its own route file under its own middleware group, NOT the `web`
         * group with extra guards bolted on. `web` appends ResolveTenant (which
         * reads the tenant from an internal `users` session),
         * HandleInertiaRequests (which shares the internal navigation and
         * permission list with every page) and the licence heartbeat. A vendor
         * has no business receiving any of it, and a group that starts by
         * inheriting all of it leaks whatever is added to `web` next.
         */
        then: function (): void {
            \Illuminate\Support\Facades\Route::middleware('tprm-portal')
                ->prefix('vendor-portal')
                ->name('tprm-portal.')
                ->group(__DIR__.'/../routes/tprm-portal.php');

            // BCMS Phase 7, Gate 2 defect 1. The gateway-facing webhooks
            // (cascade-inbound, alert-reply, provider-status) used to live in
            // routes/web.php and inherit the full `web` group — CSRF and all —
            // for traffic that can never present a session. See
            // routes/bcms-webhooks.php for the incident and the reasoning; this
            // registration is deliberately the same shape as `tprm-portal`
            // above, not a bolt-on to `web`.
            \Illuminate\Support\Facades\Route::middleware('bcms-webhook')
                ->group(__DIR__.'/../routes/bcms-webhooks.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth' => \App\Http\Middleware\EnsureAuthenticated::class,
            'permission' => \ThirdLine\Platform\Http\Middleware\CheckPermission::class,
            'mfa' => \App\Http\Middleware\EnsureMfaVerified::class,
            'tenant' => \ThirdLine\Platform\Tenancy\ResolveTenant::class,
            'scim.auth' => \App\Http\Middleware\AuthenticateScim::class,
            'feature' => \App\Http\Middleware\EnsureFeatureEnabled::class,

            // WP-07. The API authenticates by bearer token and authorizes by
            // scope. `scope:` takes a fixed permission; `scope.resource`
            // derives it from the {resource} in the path, which the generic
            // resource routes need because /risks and /loss-events want
            // different permissions from the same route definition.
            'api.auth' => \App\Http\Middleware\AuthenticateApiToken::class,
            'scope' => \App\Http\Middleware\EnsureTokenScope::class,
            'scope.resource' => \App\Http\Middleware\EnsureResourceScope::class,
            'idempotency' => \App\Http\Middleware\IdempotentRequest::class,

            // TPRM Phase 8. `portal.auth` is the portal's equivalent of `auth`
            // and binds the tenant from the portal user; `portal.guest` keeps a
            // signed-in vendor off the login screen.
            'portal.auth' => \App\Http\Middleware\Tprm\AuthenticatePortal::class,
            'portal.guest' => \App\Http\Middleware\Tprm\RedirectIfPortalAuthenticated::class,
            // The half-authenticated state: password accepted, code outstanding.
            'portal.pending' => \App\Http\Middleware\Tprm\EnsurePendingPortalUser::class,

            // Migration Phase 0: the ThirdLine licensing client. Neither alias is
            // applied to a route group yet — LICENSE_ENFORCE_VALID ships false —
            // so a deployment cannot lock itself out on a validation hiccup.
        ]);

        // ResolveTenant must run after StartSession (so the user is known) but
        // BEFORE SubstituteBindings — route-model binding resolves {risk},
        // {issue} and friends, and without the tenant bound first those lookups
        // would read across tenants. Removals are applied before appends, so
        // pulling SubstituteBindings out and re-appending it behind
        // ResolveTenant gives exactly that ordering.
        // ResolvePeriod sits between the two: it needs the tenant (a period
        // belongs to an organisation) and it must be bound before a controller
        // runs, but it resolves nothing from the route so it does not care
        // where SubstituteBindings lands.
        $middleware->web(
            remove: [SubstituteBindings::class],
            append: [
                \ThirdLine\Platform\Tenancy\ResolveTenant::class,
                \App\Http\Middleware\ResolvePeriod::class,
                SubstituteBindings::class,

                // Migration Phase 0. HandleInertiaRequests shares `tenant` and
                // `period` with every React page, so it has to run after the two
                // middleware above have bound them. SetSecurityHeaders decorates
                // every web response; LicenseHeartbeat is a terminate-time
                // check-in that does nothing until a licence is activated.
                \App\Http\Middleware\HandleInertiaRequests::class,
                \ThirdLine\Platform\Http\Middleware\SetSecurityHeaders::class,
                \ThirdLine\Platform\Licensing\Middleware\LicenseHeartbeat::class,
            ],
        );

        /*
         * The portal's stack, assembled rather than inherited.
         *
         * StartPortalSession REPLACES Illuminate's StartSession — it is a
         * subclass that renames the store — so the group has one session
         * middleware, not two.
         *
         * There is no ResolveTenant here — AuthenticatePortal binds the tenant
         * from the portal user, because the internal one reads a `users`
         * session that a vendor will never have. That makes the ordering
         * against SubstituteBindings load-bearing, and it is fixed in the
         * priority list below rather than here: `portal.auth` is ROUTE
         * middleware, which ordinarily runs after the group's, and without the
         * prepend every `{assessment}` and `{document}` in the portal would be
         * resolved untenanted — the global scope does not filter when no
         * organisation is set, so the binding would happily hand a vendor
         * another tenant's row before any controller ran.
         */
        $middleware->group('tprm-portal', [
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            // Not Illuminate's StartSession: this one is it, with the portal's
            // own cookie name. See the class for why renaming the store beats
            // rewriting `session.cookie` in the container.
            \App\Http\Middleware\Tprm\StartPortalSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\Tprm\HandlePortalInertiaRequests::class,
            \ThirdLine\Platform\Http\Middleware\SetSecurityHeaders::class,
        ]);

        /*
         * BCMS gateway webhooks — assembled empty on purpose, not thinned
         * down from `web`.
         *
         * BCMS Phase 7, Gate 2 defect 1. `bcms/cascade-inbound`,
         * `bcms/alert-reply/{provider}` and `bcms/provider-status/{provider}`
         * were declared in routes/web.php and so inherited the whole `web`
         * group, `ValidateCsrfToken` included. A gateway posts no `_token`
         * and no session cookie, so every one of those requests 419'd before
         * the controller — and its HMAC check — ever ran. That silently
         * killed the entire inbound half of EMNS, including roll-call
         * acknowledgement.
         *
         * None of `web`'s other members belong here either, and each is
         * absent for a specific reason rather than by omission:
         *   - EncryptCookies / StartSession: a gateway carries no cookie and
         *     mints no session. Worse than merely useless — `StartSession`
         *     runs ahead of the per-route throttle in `web`'s resolved
         *     stack, and config/session.php defaults the driver to
         *     `database`, so every request the limiter is about to reject
         *     would first write a session row to MariaDB on a credential-
         *     less endpoint. Leaving these routes in `web` keeps that
         *     amplification even after the CSRF fix.
         *   - HandleInertiaRequests / SetSecurityHeaders / LicenseHeartbeat:
         *     these render or decorate an HTML page; every route here
         *     returns JSON to a machine.
         *
         * Clearing the ambient tenant IS kept, and deliberately: without it
         * this group is `[]`, and the `web` group's own tenant-clearing —
         * which would otherwise have cleared the tenant on every prior
         * unauthenticated request — is the only reason `TenantContext` is
         * ever empty here. That is an absence, not a guard: an ambient
         * tenant left set by whatever ran earlier in the process (test
         * infrastructure, a future Octane worker reusing the container, a
         * queued/inline execution path) would scope
         * `InboundResponseHandler::recipientForNumber()`'s cross-tenant
         * uniqueness scan to one organization and let it silently return
         * the wrong tenant's recipient — recording a false SAFE against
         * someone who never replied. Both controllers still set
         * `TenantContext` explicitly afterwards from the record the HMAC
         * resolves to, exactly as the signed calendar feed does; this just
         * guarantees the slate is clean before they do.
         *
         * BCMS Phase 7, Gate 2 ROUND 3 defect 1. `ResolveTenant` used to sit
         * here for that clearing, but `ResolveTenant::handle()` opens with
         * `Auth::user()` unconditionally — and with `EncryptCookies` also
         * removed from this group (correctly: a gateway carries no cookie),
         * a forged `remember_me` cookie is no longer guaranteed to decrypt to
         * garbage before it reaches `SessionGuard::recaller()`. That put one
         * `users` SELECT ahead of the per-route throttle on every request,
         * including every one the limiter was about to reject — the same
         * amplification shape ADR 0016 §4 forbids ahead of this throttle, one
         * step smaller than the `StartSession` write it replaced.
         * `App\Http\Middleware\Bcms\ClearAmbientTenantContext` replaces it:
         * it does the one thing this group ever needed —
         * `TenantContext::clear()` — and reads no auth guard, no cookie, no
         * session, no database.
         *
         * What stands in for a login on every route in routes/bcms-webhooks.php
         * is an HMAC compared with `hash_equals`, checked inside the
         * controller — see AlertWebhookController::signatureOk() and
         * CascadeAckController's class docblock — plus the per-route
         * throttle already declared there.
         */
        $middleware->group('bcms-webhook', [
            \App\Http\Middleware\Bcms\ClearAmbientTenantContext::class,
        ]);

        // ResolveTenant is REMOVED from the api group in WP-07. It reads the
        // tenant from the session user, and an API request has no session — a
        // machine token has no user at all. AuthenticateApiToken binds the
        // tenant from the token instead, and it must run before
        // SubstituteBindings for the same reason ResolveTenant does on the web:
        // route-model binding resolves {measure}, {object} and friends, and
        // without the tenant bound first those lookups read across tenants.
        //
        // SCIM keeps its own tenant resolution inside AuthenticateScim.
        $middleware->api(
            remove: [SubstituteBindings::class],
            append: [SubstituteBindings::class],
        );

        // api.auth is ROUTE middleware, and route middleware ordinarily runs
        // after the group's. Without this, SubstituteBindings would resolve
        // {measure}, {object} and {jobRun} before the token had bound the
        // tenant, and those lookups would run untenanted — the global scope
        // does not filter when no organization is set. The controllers check
        // ownership explicitly as well, but the ordering is what makes the
        // scope do its job rather than the check having to catch it.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: \App\Http\Middleware\AuthenticateApiToken::class,
        );

        // Same reasoning for the vendor portal: bind the tenant from the portal
        // session before route-model binding resolves anything. See the group
        // definition above.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: \App\Http\Middleware\Tprm\AuthenticatePortal::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * An expired CSRF token on a GUEST form is not an error the user can
         * act on.
         *
         * Laravel answers a stale token with a bare "419 | PAGE EXPIRED" page:
         * no explanation, no link, no form. On the login screen that is the
         * worst place for it — the person is not signed in, so there is no
         * navigation to escape through, and the browser's back button returns
         * the same expired page. It happens for the most ordinary reason
         * there is: leaving the tab open longer than the session lifetime
         * (config/session.php, 120 minutes) and then typing a password.
         *
         * Sending them back to the form with a fresh token and a sentence
         * saying what happened turns a dead end into a retry. Only the guest
         * auth screens are redirected: inside the application a 419 on a form
         * the user has filled in should NOT silently discard their work, and
         * the generic page — however blunt — at least does not pretend the
         * submission succeeded.
         */
        // TYPE-HINTED ON HttpException, NOT TokenMismatchException, and that is
        // not a style choice: Handler::render() calls prepareException() —
        // which turns a TokenMismatchException into an HttpException(419) —
        // BEFORE it runs any render callback. A callback hinted on the
        // original exception is never reached. (Verified by posting a stale
        // token at a running server, not by reading the code.)
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() !== 419 || $request->expectsJson()) {
                return null;
            }

            // Matched on the PATH, not the route name: `POST /login` in
            // routes/auth.php is deliberately unnamed (only the GET carries
            // the `login` name, because that is what redirects target), so a
            // name check would never fire on the one request that actually
            // fails this way.
            $guestForms = ['login', 'register', 'forgot-password', 'reset-password'];

            if (! in_array($request->path(), $guestForms, true)) {
                return null;
            }

            return redirect()
                ->route('login')
                ->withInput($request->except('password', 'password_confirmation'))
                ->with('error', 'That page had been open too long and the security token expired. Please sign in again.');
        });
    })->create();
