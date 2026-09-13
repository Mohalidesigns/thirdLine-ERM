<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SsoSettingsRequest;
use App\Models\OrganizationSsoSetting;
use App\Services\Sso\SsoSettingResolver;
use App\Support\AssignableRoles;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

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
    public function __construct(private readonly SsoSettingResolver $resolver) {}

    public function edit()
    {
        $setting = $this->setting();
        Gate::authorize('view', $setting);

        return Inertia::render('Admin/Settings/Sso', [
            'sso' => $this->present($setting),
            // The roles this actor may have a directory hand out — the same
            // list SsoSettingsRequest validates against, so the form offers
            // exactly what the validator accepts.
            'roles' => AssignableRoles::for(request()->user()),
            'drivers' => [OrganizationSsoSetting::DRIVER_OIDC, OrganizationSsoSetting::DRIVER_SAML],
            // What the client hands their directory administrator. Derived
            // from the model's own accessors, as the Blade screen was, so the
            // page cannot drift from what the sign-in routes actually serve.
            'endpoints' => $this->endpoints($setting),
            'missingRequirements' => $setting->missingRequirements(),
        ]);
    }

    /**
     * The stored map as the form's repeating rows.
     *
     * @return list<array{group: string, role: string}>
     */
    private function roleMapRows(OrganizationSsoSetting $setting): array
    {
        $rows = [];

        foreach ((array) ($setting->role_map ?? []) as $group => $role) {
            $rows[] = ['group' => (string) $group, 'role' => (string) $role];
        }

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    private function endpoints(OrganizationSsoSetting $setting): array
    {
        $endpoints = [
            'Sign-in URL' => $setting->signInUrl(),
            'Redirect / Reply URL' => $setting->isSaml() ? $setting->acsUrl() : $setting->callbackUrl(),
        ];

        if ($setting->isSaml()) {
            $endpoints['Service Provider entity ID'] = $setting->spEntityId();
            $endpoints['SP metadata (XML)'] = $setting->metadataUrl();
        }

        return $endpoints;
    }

    /**
     * The row as the form may see it.
     *
     * SECRETS ARE NEVER SENT. The stored client secret and SP private key are
     * reported as booleans — whether one is on file — because a value the page
     * receives is a value in the Inertia payload, in the browser's memory and
     * in any error reporter that captures it. `the_settings_form_never_renders
     * _a_stored_secret` has pinned this since the Blade screen; the assertion
     * is the same and so is the rule.
     *
     * @return array<string, mixed>
     */
    private function present(OrganizationSsoSetting $setting): array
    {
        return array_merge($setting->only([
            'id', 'enabled', 'driver', 'label', 'slug',
            'oidc_client_id', 'oidc_auth_url', 'oidc_token_url', 'oidc_userinfo_url',
            'saml_idp_entity_id', 'saml_idp_sso_url', 'saml_idp_slo_url',
            'saml_idp_x509_cert', 'saml_sp_x509_cert',
            'saml_email_attribute', 'saml_name_attribute',
            'groups_claim', 'auto_provision', 'sync_roles_on_login',
        ]), [
            'oidc_scopes' => implode(' ', (array) ($setting->oidc_scopes ?? [])),
            'allowed_domains' => implode(', ', (array) ($setting->allowed_domains ?? [])),
            'role_map' => $this->roleMapRows($setting),
            'default_roles' => array_values((array) ($setting->default_roles ?? [])),
            'has_oidc_client_secret' => filled($setting->oidc_client_secret),
            'has_saml_sp_private_key' => filled($setting->saml_sp_private_key),
        ]);
    }

    public function update(SsoSettingsRequest $request)
    {
        $setting = $this->setting();

        $validated = $request->validated();
        $assignable = $request->assignableRoles();

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
            'role_map' => $this->roleMap($validated['role_map'] ?? [], $assignable),
            'default_roles' => $this->knownRoles($validated['default_roles'] ?? [], $assignable),
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
     * The organisation's configuration row.
     *
     * Resolved by SsoSettingResolver so the Form Request and the controller
     * see the same row — the request needs it to ignore its own slug in the
     * uniqueness rule and to ask the policy.
     */
    private function setting(): OrganizationSsoSetting
    {
        return $this->resolver->forCurrentTenant();
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
     * Only map to roles this actor may have a directory hand out.
     *
     * The map was always an allowlist against roles that do not exist. Since
     * Phase 6.2 it is also an allowlist against `super-admin`, which does —
     * see SsoSettingsRequest. The request rejects the save; this filter is the
     * belt to that brace, so a row that arrives some other way is dropped
     * rather than stored.
     *
     * @param  array<int, array{group?: string, role?: string}>  $rows
     * @param  list<string>  $known
     * @return array<string, string>
     */
    private function roleMap(array $rows, array $known): array
    {
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
     * @param  list<string>  $known
     * @return list<string>
     */
    private function knownRoles(array $roles, array $known): array
    {
        return collect($roles)->filter(fn ($role) => in_array($role, $known, true))->unique()->values()->all();
    }
}
