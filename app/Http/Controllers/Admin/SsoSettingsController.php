<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrganizationSsoSetting;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * Lets a client configure their own identity provider after purchase.
 *
 * Separate from OrganizationSettingsController and behind its own permission:
 * a mistake here is an authentication bypass, not a cosmetic setting, so the
 * ability to change it should be grantable independently of the rest of the
 * organization profile.
 */
class SsoSettingsController extends Controller
{
    public function edit()
    {
        return view('admin.settings.sso', [
            'sso' => $this->setting(),
            'roles' => Role::query()->orderBy('name')->pluck('name'),
        ]);
    }

    public function update(Request $request)
    {
        $setting = $this->setting();

        $validated = $request->validate([
            'enabled' => 'nullable|boolean',
            'driver' => ['required', Rule::in([OrganizationSsoSetting::DRIVER_OIDC, OrganizationSsoSetting::DRIVER_SAML])],
            'label' => 'required|string|max:120',
            'slug' => [
                'required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9-]*$/',
                Rule::unique('organization_sso_settings', 'slug')->ignore($setting->id),
            ],

            // OIDC — https only: an http endpoint would put the authorization
            // code and the client secret exchange on the wire in clear text.
            'oidc_client_id' => 'nullable|string|max:255',
            'oidc_client_secret' => 'nullable|string|max:2000',
            'oidc_auth_url' => 'nullable|url|starts_with:https://|max:500',
            'oidc_token_url' => 'nullable|url|starts_with:https://|max:500',
            'oidc_userinfo_url' => 'nullable|url|starts_with:https://|max:500',
            'oidc_scopes' => 'nullable|string|max:500',

            // SAML
            'saml_idp_entity_id' => 'nullable|string|max:500',
            'saml_idp_sso_url' => 'nullable|url|starts_with:https://|max:500',
            'saml_idp_slo_url' => 'nullable|url|starts_with:https://|max:500',
            'saml_idp_x509_cert' => 'nullable|string|max:20000',
            'saml_sp_x509_cert' => 'nullable|string|max:20000',
            'saml_sp_private_key' => 'nullable|string|max:20000',
            'saml_email_attribute' => 'nullable|string|max:190',
            'saml_name_attribute' => 'nullable|string|max:190',

            'groups_claim' => 'nullable|string|max:190',
            'allowed_domains' => 'nullable|string|max:2000',
            'role_map' => 'nullable|array',
            'role_map.*.group' => 'nullable|string|max:190',
            'role_map.*.role' => 'nullable|string|max:190',
            'default_roles' => 'nullable|array',
            'default_roles.*' => 'string|max:190',
            'auto_provision' => 'nullable|boolean',
            'sync_roles_on_login' => 'nullable|boolean',
        ]);

        $attributes = [
            'enabled' => $request->boolean('enabled'),
            'driver' => $validated['driver'],
            'label' => $validated['label'],
            'slug' => $validated['slug'],
            'oidc_client_id' => $validated['oidc_client_id'] ?? null,
            'oidc_auth_url' => $validated['oidc_auth_url'] ?? null,
            'oidc_token_url' => $validated['oidc_token_url'] ?? null,
            'oidc_userinfo_url' => $validated['oidc_userinfo_url'] ?? null,
            'oidc_scopes' => $this->splitList($validated['oidc_scopes'] ?? '') ?: ['openid', 'profile', 'email'],
            'saml_idp_entity_id' => $validated['saml_idp_entity_id'] ?? null,
            'saml_idp_sso_url' => $validated['saml_idp_sso_url'] ?? null,
            'saml_idp_slo_url' => $validated['saml_idp_slo_url'] ?? null,
            'saml_idp_x509_cert' => $validated['saml_idp_x509_cert'] ?? null,
            'saml_sp_x509_cert' => $validated['saml_sp_x509_cert'] ?? null,
            'saml_email_attribute' => $validated['saml_email_attribute'] ?? null,
            'saml_name_attribute' => $validated['saml_name_attribute'] ?? null,
            'groups_claim' => ($validated['groups_claim'] ?? null) ?: 'groups',
            'allowed_domains' => $this->splitList($validated['allowed_domains'] ?? ''),
            'role_map' => $this->roleMap($validated['role_map'] ?? []),
            'default_roles' => $this->knownRoles($validated['default_roles'] ?? []),
            'auto_provision' => $request->boolean('auto_provision'),
            'sync_roles_on_login' => $request->boolean('sync_roles_on_login'),
            'updated_by' => auth()->id(),
        ];

        // Secrets are write-only from the UI: the form never renders the stored
        // value, so an empty field means "leave it alone", not "clear it".
        // Clearing is an explicit checkbox.
        foreach (['oidc_client_secret' => 'clear_oidc_client_secret', 'saml_sp_private_key' => 'clear_saml_sp_private_key'] as $field => $clearFlag) {
            if (filled($validated[$field] ?? null)) {
                $attributes[$field] = $validated[$field];
            } elseif ($request->boolean($clearFlag)) {
                $attributes[$field] = null;
            }
        }

        $setting->fill($attributes)->save();

        // Refuse to advertise a half-configured provider as enabled: turning it
        // on with fields missing would send users to a broken sign-in.
        if ($setting->enabled && ($missing = $setting->missingRequirements()) !== []) {
            $setting->forceFill(['enabled' => false])->save();

            return back()->with('warning',
                'Settings saved, but single sign-on stays off until these are provided: '.implode(', ', $missing).'.'
            );
        }

        return back()->with('success', 'Single sign-on settings updated.');
    }

    /**
     * The client's configuration row, created on first visit so the form has
     * something to bind to.
     */
    private function setting(): OrganizationSsoSetting
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

    /**
     * @return list<string>
     */
    private function splitList(string $value): array
    {
        return collect(preg_split('/[\s,]+/', $value) ?: [])
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Only map to roles this application actually defines — the map is an
     * allowlist, and a client must not be able to invent an authorization
     * principal by typing its name.
     *
     * @param  array<int, array{group?: string, role?: string}>  $rows
     * @return array<string, string>
     */
    private function roleMap(array $rows): array
    {
        $known = Role::query()->pluck('name')->all();
        $map = [];

        foreach ($rows as $row) {
            $group = trim((string) ($row['group'] ?? ''));
            $role = trim((string) ($row['role'] ?? ''));

            if ($group !== '' && in_array($role, $known, true)) {
                $map[$group] = $role;
            }
        }

        return $map;
    }

    /**
     * @param  array<int, string>  $roles
     * @return list<string>
     */
    private function knownRoles(array $roles): array
    {
        $known = Role::query()->pluck('name')->all();

        return collect($roles)->filter(fn ($role) => in_array($role, $known, true))->unique()->values()->all();
    }
}
