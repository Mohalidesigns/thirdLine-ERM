<?php

namespace ThirdLine\Platform\Licensing;

use Illuminate\Support\ServiceProvider;
use ThirdLine\Platform\Licensing\Middleware\EnsureLicenseFeature;
use ThirdLine\Platform\Licensing\Middleware\EnsureLicenseValid;

/**
 * The licensing cluster: JWT validation, entitlement enforcement, grace
 * periods, tamper detection, seat reporting and the audit trail under them.
 *
 * OPT-IN, like tenancy and for a stronger reason: registering it creates two
 * tables. A consumer that is not licensed through the ThirdLine licence server
 * should not acquire `license_stores` and `license_audit_logs` by depending on
 * this package for, say, its middleware.
 *
 * Registering it also aliases the two route middleware, so an application that
 * opts in gets `ensure.license.valid` and `ensure.license.feature` without
 * naming package classes in its own bootstrap.
 */
class LicensingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/licensing.php', 'licensing');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        $router = $this->app->make('router');
        $router->aliasMiddleware('ensure.license.valid', EnsureLicenseValid::class);
        $router->aliasMiddleware('ensure.license.feature', EnsureLicenseFeature::class);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/licensing.php' => config_path('licensing.php'),
            ], 'platform-licensing-config');
        }
    }
}
