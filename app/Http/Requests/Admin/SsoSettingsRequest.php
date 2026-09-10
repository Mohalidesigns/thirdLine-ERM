<?php

namespace App\Http\Requests\Admin;

use App\Models\OrganizationSsoSetting;
use App\Services\Sso\SsoSettingResolver;
use App\Support\AssignableRoles;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Configure the identity provider (migration Phase 6.2).
 *
 * The rules are the controller's, lifted verbatim — https-only endpoints,
 * write-only secrets, a slug unique across the installation — with one
 * addition.
 *
 * **A DIRECTORY GROUP COULD BE MAPPED TO `super-admin`.** `role_map` and
 * `default_roles` were filtered against `Role::all()`, which is an allowlist
 * against invented roles and no defence at all against a real one. Whoever
 * held `admin.sso` could map a group they belong to — or simply set
 * `default_roles: ["super-admin"]` — and SsoProvisioningService::syncRoles()
 * would hand out the role on their next sign-in. `Gate::before` answers every
 * ability true for a super-admin, so that is the platform, granted by an
 * identity provider the platform does not control.
 *
 * It is the same escalation Phase 6.1 closed on the user form, reached through
 * a different screen and a different permission, and it is closed the same
 * way: `UserPolicy::grantSuperAdmin()` decides, so the answer is identical
 * wherever it is asked. A super-admin configuring this deliberately still can.
 *
 * The login path is left alone on purpose. Once only a super-admin can store
 * the mapping, a super-admin's deliberate choice is what SsoProvisioningService
 * acts on, and second-guessing it there would silently break a configuration
 * somebody consented to.
 */
class SsoSettingsRequest extends FormRequest
{
    private ?OrganizationSsoSetting $setting = null;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->setting());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $assignable = $this->assignableRoles();

        return [
            'enabled' => ['nullable', 'boolean'],
            'driver' => ['required', Rule::in([OrganizationSsoSetting::DRIVER_OIDC, OrganizationSsoSetting::DRIVER_SAML])],
            'label' => ['required', 'string', 'max:120'],
            'slug' => [
                'required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9-]*$/',
                Rule::unique('organization_sso_settings', 'slug')->ignore($this->setting()->id),
            ],

            // OIDC — https only: an http endpoint would put the authorization
            // code and the client secret exchange on the wire in clear text.
            'oidc_client_id' => ['nullable', 'string', 'max:255'],
            'oidc_client_secret' => ['nullable', 'string', 'max:2000'],
            'oidc_auth_url' => ['nullable', 'url', 'starts_with:https://', 'max:500'],
            'oidc_token_url' => ['nullable', 'url', 'starts_with:https://', 'max:500'],
            'oidc_userinfo_url' => ['nullable', 'url', 'starts_with:https://', 'max:500'],
            'oidc_scopes' => ['nullable', 'string', 'max:500'],

            // SAML
            'saml_idp_entity_id' => ['nullable', 'string', 'max:500'],
            'saml_idp_sso_url' => ['nullable', 'url', 'starts_with:https://', 'max:500'],
            'saml_idp_slo_url' => ['nullable', 'url', 'starts_with:https://', 'max:500'],
            'saml_idp_x509_cert' => ['nullable', 'string', 'max:20000'],
            'saml_sp_x509_cert' => ['nullable', 'string', 'max:20000'],
            'saml_sp_private_key' => ['nullable', 'string', 'max:20000'],
            'saml_email_attribute' => ['nullable', 'string', 'max:190'],
            'saml_name_attribute' => ['nullable', 'string', 'max:190'],

            'groups_claim' => ['nullable', 'string', 'max:190'],
            'allowed_domains' => ['nullable', 'string', 'max:2000'],

            'role_map' => ['nullable', 'array'],
            'role_map.*.group' => ['nullable', 'string', 'max:190'],
            // Empty is allowed: a row whose role is blank is a row the user has
            // not finished, and roleMap() drops it rather than failing the save.
            'role_map.*.role' => ['nullable', 'string', 'max:190', Rule::in([...$assignable, ''])],

            'default_roles' => ['nullable', 'array'],
            'default_roles.*' => ['string', 'max:190', Rule::in($assignable)],

            'auto_provision' => ['nullable', 'boolean'],
            'sync_roles_on_login' => ['nullable', 'boolean'],
            'clear_oidc_client_secret' => ['nullable', 'boolean'],
            'clear_saml_sp_private_key' => ['nullable', 'boolean'],
        ];
    }

    /**
     * The roles this actor may have a directory hand out.
     *
     * @return list<string>
     */
    public function assignableRoles(): array
    {
        return AssignableRoles::for($this->user());
    }

    /**
     * The organisation's configuration row, created on first visit so the form
     * has something to bind to.
     */
    public function setting(): OrganizationSsoSetting
    {
        return $this->setting ??= app(SsoSettingResolver::class)->forCurrentTenant();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'role_map.*.role.in' => 'That role cannot be mapped. Only a super-admin may map a group to super-admin.',
            'default_roles.*.in' => 'That role cannot be granted on sign-in. Only a super-admin may choose super-admin.',
        ];
    }
}
