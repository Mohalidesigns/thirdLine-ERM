<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One organization's federation settings, configured by that client through
 * the admin UI.
 *
 * Reads during sign-in happen before any user is authenticated, so the tenant
 * scope cannot be relied on there — resolveBySlug() and resolveByEmailDomain()
 * deliberately step outside it. Every other access goes through the normal
 * scoped query, so one client can never see or edit another's configuration.
 */
class OrganizationSsoSetting extends Model
{
    use BelongsToOrganization;

    public const DRIVER_OIDC = 'oidc';

    public const DRIVER_SAML = 'saml';

    protected $fillable = [
        'organization_id',
        'slug',
        'enabled',
        'driver',
        'label',
        'oidc_client_id',
        'oidc_client_secret',
        'oidc_auth_url',
        'oidc_token_url',
        'oidc_userinfo_url',
        'oidc_scopes',
        'saml_idp_entity_id',
        'saml_idp_sso_url',
        'saml_idp_slo_url',
        'saml_idp_x509_cert',
        'saml_sp_x509_cert',
        'saml_sp_private_key',
        'saml_email_attribute',
        'saml_name_attribute',
        'groups_claim',
        'allowed_domains',
        'role_map',
        'default_roles',
        'auto_provision',
        'sync_roles_on_login',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'auto_provision' => 'boolean',
            'sync_roles_on_login' => 'boolean',
            'oidc_scopes' => 'array',
            'allowed_domains' => 'array',
            'role_map' => 'array',
            'default_roles' => 'array',
            // Encrypted at rest: a database backup or a read-only SQL user must
            // not yield credentials that can impersonate the client's IdP
            // integration.
            'oidc_client_secret' => 'encrypted',
            'saml_sp_private_key' => 'encrypted',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Pre-authentication resolution */
    /* ------------------------------------------------------------------ */

    /**
     * Find an enabled configuration by its sign-in slug.
     *
     * Runs unscoped on purpose: at this point in the flow nobody is signed in,
     * so there is no tenant to scope by — the slug IS the tenant selector.
     */
    public static function resolveBySlug(string $slug): ?self
    {
        return static::query()
            ->withoutGlobalScopes()
            ->where('slug', $slug)
            ->where('enabled', true)
            ->first();
    }

    /**
     * Home-realm discovery: find the configuration that claims this email's
     * domain, so a user can type their address instead of knowing a URL.
     *
     * Only configurations that explicitly list the domain match. A config with
     * an empty allowed_domains list is never discoverable this way, otherwise
     * one client's federation would swallow every unrecognised address.
     */
    public static function resolveByEmailDomain(string $email): ?self
    {
        $domain = strtolower(trim(substr(strrchr($email, '@') ?: '', 1)));

        if ($domain === '') {
            return null;
        }

        return static::query()
            ->withoutGlobalScopes()
            ->where('enabled', true)
            ->get()
            ->first(fn (self $setting) => collect($setting->allowed_domains ?? [])
                ->map(fn ($d) => strtolower(ltrim(trim((string) $d), '@')))
                ->contains($domain));
    }

    /* ------------------------------------------------------------------ */
    /*  Derived URLs — shown to the client so they can configure their IdP */
    /* ------------------------------------------------------------------ */

    public function signInUrl(): string
    {
        return url("/auth/sso/{$this->slug}");
    }

    public function callbackUrl(): string
    {
        return url("/auth/sso/{$this->slug}/callback");
    }

    /** SAML Assertion Consumer Service URL. */
    public function acsUrl(): string
    {
        return url("/auth/sso/{$this->slug}/acs");
    }

    public function metadataUrl(): string
    {
        return url("/auth/sso/{$this->slug}/metadata");
    }

    /** SAML Service Provider entity id. */
    public function spEntityId(): string
    {
        return url("/auth/sso/{$this->slug}/metadata");
    }

    /* ------------------------------------------------------------------ */
    /*  State */
    /* ------------------------------------------------------------------ */

    public function isOidc(): bool
    {
        return $this->driver === self::DRIVER_OIDC;
    }

    public function isSaml(): bool
    {
        return $this->driver === self::DRIVER_SAML;
    }

    /**
     * The fields that must be present before this configuration can be used.
     *
     * @return list<string> human-readable descriptions of what is missing
     */
    public function missingRequirements(): array
    {
        $missing = [];

        if ($this->isOidc()) {
            foreach ([
                'oidc_client_id' => 'Client ID',
                'oidc_client_secret' => 'Client secret',
                'oidc_auth_url' => 'Authorization URL',
                'oidc_token_url' => 'Token URL',
                'oidc_userinfo_url' => 'Userinfo URL',
            ] as $field => $label) {
                if (blank($this->{$field})) {
                    $missing[] = $label;
                }
            }

            return $missing;
        }

        foreach ([
            'saml_idp_entity_id' => 'IdP entity ID',
            'saml_idp_sso_url' => 'IdP sign-on URL',
            'saml_idp_x509_cert' => 'IdP signing certificate',
        ] as $field => $label) {
            if (blank($this->{$field})) {
                $missing[] = $label;
            }
        }

        return $missing;
    }

    public function isUsable(): bool
    {
        return $this->enabled && $this->missingRequirements() === [];
    }

    /**
     * Shape this row like the array config/sso.php produces, so
     * SsoProvisioningService stays protocol- and source-agnostic.
     *
     * @return array<string, mixed>
     */
    public function toProviderConfig(): array
    {
        return [
            'driver' => $this->driver,
            'label' => $this->label,
            'client_id' => $this->oidc_client_id,
            'client_secret' => $this->oidc_client_secret,
            'redirect' => $this->callbackUrl(),
            'auth_url' => $this->oidc_auth_url,
            'token_url' => $this->oidc_token_url,
            'userinfo_url' => $this->oidc_userinfo_url,
            'scopes' => $this->oidc_scopes ?: ['openid', 'profile', 'email'],
            'groups_claim' => $this->groups_claim ?: 'groups',
            'allowed_domains' => $this->allowed_domains ?? [],
            'role_map' => $this->role_map ?? [],
            'default_roles' => $this->default_roles ?? [],
            'auto_provision' => (bool) $this->auto_provision,
            'sync_roles_on_login' => (bool) $this->sync_roles_on_login,
            'organization_id' => $this->organization_id,
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
