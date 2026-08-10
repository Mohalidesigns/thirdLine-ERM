<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;

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
            'permission' => \App\Http\Middleware\CheckPermission::class,
            'mfa' => \App\Http\Middleware\EnsureMfaVerified::class,
            'tenant' => \App\Http\Middleware\ResolveTenant::class,
            'scim.auth' => \App\Http\Middleware\AuthenticateScim::class,
            'feature' => \App\Http\Middleware\EnsureFeatureEnabled::class,
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
                \App\Http\Middleware\ResolveTenant::class,
                \App\Http\Middleware\ResolvePeriod::class,
                SubstituteBindings::class,
            ],
        );

        $middleware->api(
            remove: [SubstituteBindings::class],
            append: [
                \App\Http\Middleware\ResolveTenant::class,
                SubstituteBindings::class,
            ],
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
