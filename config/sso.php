<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Single sign-on
    |--------------------------------------------------------------------------
    |
    | SSO is off unless a provider is configured. Local password login keeps
    | working alongside it — locking an organization out of its own platform
    | because an IdP is misconfigured is not an acceptable failure mode.
    |
    */

    'enabled' => env('SSO_ENABLED', false),

    /*
    | When true, a user who signs in through an IdP has their roles replaced by
    | whatever the group map yields on every login, so removing someone from a
    | group in the IdP removes their access here at their next sign-in. When
    | false, IdP roles are added and locally granted roles are left alone.
    */
    'sync_roles_on_login' => env('SSO_SYNC_ROLES_ON_LOGIN', true),

    /*
    | Provision a user record the first time an unknown but authenticated
    | identity arrives. With this off, users must already exist locally — the
    | stricter posture, and the right default for a regulated deployment where
    | someone must approve each account.
    */
    'auto_provision' => env('SSO_AUTO_PROVISION', false),

    /*
    | The organization new users are provisioned into. Tenancy has no ambient
    | default, so this must be set explicitly for auto-provisioning to work.
    */
    'default_organization_id' => env('SSO_DEFAULT_ORGANIZATION_ID'),

    /*
    | Roles granted to a provisioned user whose IdP groups match nothing in the
    | map. Deliberately the least-privileged role rather than nothing at all,
    | so a mapping gap produces a user who can log in and see very little
    | instead of a confusing hard failure.
    */
    'default_roles' => ['risk-analyst'],

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Every OIDC provider uses the same generic driver; they differ only by
    | endpoint. Identity comes from the userinfo endpoint using the access
    | token rather than from parsing the id_token, so this code never has to
    | verify a JWT signature itself — the classic place OIDC integrations go
    | wrong.
    |
    | `allowed_domains` is an additional guard: even a correctly signed
    | assertion is rejected unless the email lands in a domain you listed.
    |
    */

    'providers' => [

        'entra' => [
            'driver' => 'oidc',
            'label' => 'Microsoft Entra ID',
            'client_id' => env('SSO_ENTRA_CLIENT_ID'),
            'client_secret' => env('SSO_ENTRA_CLIENT_SECRET'),
            'redirect' => env('SSO_ENTRA_REDIRECT'),
            'tenant' => env('SSO_ENTRA_TENANT', 'common'),
            'auth_url' => env('SSO_ENTRA_AUTH_URL', 'https://login.microsoftonline.com/'.env('SSO_ENTRA_TENANT', 'common').'/oauth2/v2.0/authorize'),
            'token_url' => env('SSO_ENTRA_TOKEN_URL', 'https://login.microsoftonline.com/'.env('SSO_ENTRA_TENANT', 'common').'/oauth2/v2.0/token'),
            'userinfo_url' => env('SSO_ENTRA_USERINFO_URL', 'https://graph.microsoft.com/oidc/userinfo'),
            'scopes' => ['openid', 'profile', 'email'],
            'groups_claim' => env('SSO_ENTRA_GROUPS_CLAIM', 'groups'),
            'allowed_domains' => array_filter(explode(',', (string) env('SSO_ENTRA_ALLOWED_DOMAINS', ''))),
        ],

        'google' => [
            'driver' => 'oidc',
            'label' => 'Google Workspace',
            'client_id' => env('SSO_GOOGLE_CLIENT_ID'),
            'client_secret' => env('SSO_GOOGLE_CLIENT_SECRET'),
            'redirect' => env('SSO_GOOGLE_REDIRECT'),
            'auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url' => 'https://oauth2.googleapis.com/token',
            'userinfo_url' => 'https://openidconnect.googleapis.com/v1/userinfo',
            'scopes' => ['openid', 'profile', 'email'],
            'groups_claim' => env('SSO_GOOGLE_GROUPS_CLAIM', 'groups'),
            // Google Workspace does not return group memberships from the
            // standard userinfo endpoint; restrict by hosted domain instead
            // and map roles through SCIM or locally.
            'allowed_domains' => array_filter(explode(',', (string) env('SSO_GOOGLE_ALLOWED_DOMAINS', ''))),
        ],

        'okta' => [
            'driver' => 'oidc',
            'label' => 'Okta',
            'client_id' => env('SSO_OKTA_CLIENT_ID'),
            'client_secret' => env('SSO_OKTA_CLIENT_SECRET'),
            'redirect' => env('SSO_OKTA_REDIRECT'),
            'auth_url' => env('SSO_OKTA_BASE_URL').'/oauth2/default/v1/authorize',
            'token_url' => env('SSO_OKTA_BASE_URL').'/oauth2/default/v1/token',
            'userinfo_url' => env('SSO_OKTA_BASE_URL').'/oauth2/default/v1/userinfo',
            'scopes' => ['openid', 'profile', 'email', 'groups'],
            'groups_claim' => env('SSO_OKTA_GROUPS_CLAIM', 'groups'),
            'allowed_domains' => array_filter(explode(',', (string) env('SSO_OKTA_ALLOWED_DOMAINS', ''))),
        ],

        /*
        | SAML 2.0 — env fallback only.
        |
        | SAML is implemented (see App\Support\Sso\SamlDriver, backed by
        | onelogin/php-saml) and is normally configured per client in the admin
        | UI. These keys exist for single-tenant installs that prefer env
        | configuration.
        */
        'saml' => [
            'driver' => 'saml',
            'label' => 'SAML 2.0',
            'entity_id' => env('SSO_SAML_ENTITY_ID'),
            'sso_url' => env('SSO_SAML_SSO_URL'),
            'slo_url' => env('SSO_SAML_SLO_URL'),
            'idp_x509_cert' => env('SSO_SAML_IDP_X509_CERT'),
            'sp_x509_cert' => env('SSO_SAML_SP_X509_CERT'),
            'sp_private_key' => env('SSO_SAML_SP_PRIVATE_KEY'),
            'groups_claim' => env('SSO_SAML_GROUPS_CLAIM', 'groups'),
            'allowed_domains' => array_filter(explode(',', (string) env('SSO_SAML_ALLOWED_DOMAINS', ''))),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | IdP group -> application role
    |--------------------------------------------------------------------------
    |
    | Keys are group names or object ids as the IdP presents them; values are
    | Spatie role names. Matching is case-insensitive. A group with no entry
    | grants nothing — the map is an allowlist, never a passthrough, so an
    | attacker who can name a group cannot name a role.
    |
    */

    'role_map' => [
        // 'GRC-Administrators'   => 'super-admin',
        // 'GRC-Chief-Risk'       => 'chief-risk-officer',
        // 'GRC-Risk-Managers'    => 'risk-manager',
        // 'GRC-Risk-Owners'      => 'risk-owner',
        // 'GRC-Compliance'       => 'compliance-officer',
        // 'GRC-Board'            => 'board-member',
    ],

    /*
    |--------------------------------------------------------------------------
    | SCIM 2.0 provisioning
    |--------------------------------------------------------------------------
    |
    | Tokens are held hashed, one per organization, and compared in constant
    | time. Generate with `php artisan scim:token {organization}`.
    |
    */

    'scim' => [
        'enabled' => env('SCIM_ENABLED', false),
    ],

];
