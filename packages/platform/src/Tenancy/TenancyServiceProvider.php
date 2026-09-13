<?php

namespace ThirdLine\Platform\Tenancy;

use Illuminate\Support\ServiceProvider;

/**
 * The opt-in half: one tenant per request, job or command.
 *
 * Registered by the application, never auto-discovered. Loading this in an
 * application that is not multi-tenant would put OrganizationScope on every
 * model using BelongsToOrganization and silently filter its queries to a
 * tenant that was never resolved.
 *
 * Phase 7 note: ThirdLine does NOT register this yet. It has no tenancy of its
 * own, and adding one is a data-model decision, not a packaging one.
 */
class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One tenant per request / job / command. Everything that needs the
        // current organization_id resolves this same instance.
        $this->app->singleton(TenantContext::class);
    }
}
