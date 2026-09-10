<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        // Horizon shows job payloads, and a payload is the record it operates
        // on: a queued notification carries its subject and body, an import job
        // carries the file it is reading. On this platform that is loss events
        // and examination findings, so the dashboard is administration, not
        // observability — hence a permission rather than the stock hardcoded
        // list of developer email addresses, which nobody ever fills in and
        // which locks every real operator out of production.
        Gate::define('viewHorizon', fn ($user = null) => $user !== null && $user->can('admin.queues'));
    }
}
