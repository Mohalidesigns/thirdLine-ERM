<?php

namespace App\Services\Sso;

use App\Models\OrganizationSsoSetting;
use Illuminate\Support\Str;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The current tenant's single sign-on configuration row (migration Phase 6.2).
 *
 * Extracted from SsoSettingsController because the Form Request needs the same
 * row the controller does — to ignore its own slug in the uniqueness rule, and
 * to answer the policy — and a firstOrCreate written twice is a firstOrCreate
 * that will eventually disagree with itself.
 *
 * The row is created on first visit so the form has something to bind to. That
 * is a write on a GET, which is unusual enough to say out loud: it is a
 * disabled placeholder carrying nothing but a slug, and creating it lazily is
 * what lets the screen render before a client has configured anything.
 */
class SsoSettingResolver
{
    public function forCurrentTenant(): OrganizationSsoSetting
    {
        $organizationId = TenantContext::organizationId();

        return OrganizationSsoSetting::firstOrCreate(
            ['organization_id' => $organizationId],
            [
                'slug' => $this->defaultSlug($organizationId),
                'enabled' => false,
                'driver' => OrganizationSsoSetting::DRIVER_OIDC,
                'label' => 'Single sign-on',
                'groups_claim' => 'groups',
                'sync_roles_on_login' => true,
            ]
        );
    }

    /**
     * A slug free across the whole installation — the sign-in URL is
     * `/auth/sso/{slug}`, which is resolved before anybody is authenticated
     * and therefore before there is a tenant to scope it by.
     */
    private function defaultSlug(int $organizationId): string
    {
        $base = Str::slug((string) (auth()->user()?->organization?->short_name
            ?: auth()->user()?->organization?->name
            ?: "org-{$organizationId}"));

        $base = $base !== '' ? $base : "org-{$organizationId}";
        $slug = $base;
        $suffix = 2;

        while (OrganizationSsoSetting::query()->withoutGlobalScopes()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
