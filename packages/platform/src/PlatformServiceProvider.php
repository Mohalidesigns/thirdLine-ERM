<?php

namespace ThirdLine\Platform;

use Illuminate\Support\ServiceProvider;

/**
 * The always-on half of the platform package.
 *
 * Registers the configuration every consumer needs and nothing that changes
 * behaviour on its own. Tenancy is deliberately NOT here: a single-tenant
 * application must be able to depend on this package without acquiring a
 * global scope that filters every query it makes. See TenancyServiceProvider.
 */
class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/platform.php', 'platform');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/platform.php' => config_path('platform.php'),
            ], 'platform-config');
        }
    }
}
