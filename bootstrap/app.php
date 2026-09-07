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
