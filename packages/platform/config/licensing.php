<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Seats
    |--------------------------------------------------------------------------
    |
    | The class a licence seat is counted from. No default: the licence server
    | is told how many seats are in use, so a wrong guess under-reports usage
    | rather than failing. See ThirdLine\Platform\Licensing\LicensingConfig.
    |
    */

    'user_model' => null,

    /*
    | The route name serving this application's licence screen. An unlicensed
    | user is redirected here and routes under this prefix stay reachable, so
    | they are not trapped. No default: the risk product calls it
    | `admin.license` and ThirdLine calls it `license`.
    */

    'recovery_route' => null,

    // Where a user lands when a MODULE is not licensed: they still have a
    // working deployment, just not that feature.
    'home_route' => null,

    /*
    |--------------------------------------------------------------------------
    | Licensing Server Connection
    |--------------------------------------------------------------------------
    */
    // Canonical production LicensingServer; override per environment in .env.
    // For local development point LICENSE_SERVER_URL at your dev instance; the
    // scheme MUST be https in production (enforced in SyncManager).
    'server_url' => env('LICENSE_SERVER_URL', 'https://license.atherislimited.com'),
    'client_token' => env('LICENSE_CLIENT_TOKEN'),
    'client_id' => env('LICENSE_CLIENT_ID'),
    'client_secret' => env('LICENSE_CLIENT_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Cryptographic Keys
    |--------------------------------------------------------------------------
    */
    'keys' => [
        // Consumer verifies signatures with the PUBLIC key only. The private
        // key must never live on the consumer — licenses are signed exclusively
        // by the LicensingServer.
        //
        // VAPT-038: the public key is the trust root. In production it is ALWAYS
        // the shipped resources/keys/license_public.pem — the LICENSE_PUBLIC_KEY_PATH
        // override is honoured only in local dev (so a dev can point at a local
        // LicensingServer's keypair). This stops an on-prem operator from swapping
        // in their own key and self-signing an unlimited licence offline.
        'public' => env('APP_ENV', 'production') === 'local'
            ? env('LICENSE_PUBLIC_KEY_PATH', base_path('resources/keys/license_public.pem'))
            : base_path('resources/keys/license_public.pem'),
    ],
    'algorithm' => 'RS256',
    'issuer' => 'thirdline-grc-licensing',

    /*
    |--------------------------------------------------------------------------
    | Timing & Grace Period
    |--------------------------------------------------------------------------
    */
    'heartbeat_interval_hours' => env('LICENSE_HEARTBEAT_HOURS', 48),
    'grace_period_days' => env('LICENSE_GRACE_DAYS', 7),
    'max_clock_drift_seconds' => env('LICENSE_MAX_DRIFT', 300),
    'validation_cache_seconds' => env('LICENSE_CACHE_TTL', 300),
    // How often (minutes) to poll the server for revocation, independent of the
    // longer usage-heartbeat interval, so a revoke locks the app promptly.
    'revocation_check_minutes' => env('LICENSE_REVOCATION_CHECK_MINUTES', 30),

    /*
    |--------------------------------------------------------------------------
    | Server-side enforcement
    |--------------------------------------------------------------------------
    | When enabled, the global `ensure.license.valid` gate blocks every
    | authenticated route (except license activation / profile / logout) on an
    | unlicensed, locked, revoked, or tampered install — so an internet-exposed
    | deployment can't be driven past the SPA overlay via direct HTTP. A licensed
    | install in grace mode (server unreachable) is NOT blocked.
    |
    | Ships DISABLED by default so a deploy can never lock out a running install
    | on a licence-validation hiccup. To turn enforcement on, verify the install's
    | licence validates green, then set LICENSE_ENFORCE_VALID=true in .env and run
    | `php artisan config:cache`. The test suite pins this per-test as needed.
    */
    // filter_var, not a bare env(): PHPUnit's <env value="false"> arrives as
    // an empty string, and a config cache built elsewhere may carry either.
    'enforce_valid' => filter_var(env('LICENSE_ENFORCE_VALID', false), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Feature Definitions
    |--------------------------------------------------------------------------
    */
    'available_features' => [
        // ThirdLine's keys, kept so a licence minted for the suite reads the
        // same on both products, plus the five this product's modules need.
        'audit' => 'Audit Management',
        'risk' => 'Risk Management',
        'compliance' => 'Compliance Management',
        'swift_cscf' => 'SWIFT CSCF Module',
        'iso_27001' => 'ISO 27001 ISMS',
        'iso_22301' => 'ISO 22301 BCMS',
        'iso_20000' => 'ISO 20000 ITSMS',
        'iso_45001' => 'ISO 45001 OHSMS',
        'iso_20022' => 'ISO 20022 FMS',
        'pci_dss' => 'PCI DSS v4.0',
        'ndpa' => 'NDPA GAID',
        'icfr' => 'ICFR/SOX Compliance',
        'ai_assistant' => 'AI Assistant',
        'analytics' => 'Advanced Analytics',
        'reporting' => 'Custom Reporting',
        'committee' => 'Audit Committee Portal',
        'evidence_repo' => 'Evidence Repository',
        'performance' => 'Performance Management',
        // Risk product modules.
        'quantification' => 'Risk Quantification & ICAAP',
        'loss_events' => 'Loss Events & Near Misses',
        'regulatory' => 'Regulatory Compliance',
        'integrations' => 'Integrations (API, webhooks, connectors)',
        'workflows' => 'Workflow Engine',
    ],

    /*
    |--------------------------------------------------------------------------
    | Plan Definitions
    |--------------------------------------------------------------------------
    */
    'plans' => [
        'starter' => [
            'label' => 'Starter',
            'features' => ['audit'],
            'max_users' => 5,
            'max_activations' => 1,
            'grace_days' => 3,
        ],
        'professional' => [
            'label' => 'Professional',
            'features' => ['risk', 'analytics', 'reporting', 'loss_events', 'workflows'],
            'max_users' => 25,
            'max_activations' => 2,
            'grace_days' => 7,
        ],
        'enterprise' => [
            'label' => 'Enterprise',
            'features' => [
                'audit', 'risk', 'compliance', 'swift_cscf', 'iso_27001', 'iso_22301',
                'iso_20000', 'iso_45001', 'iso_20022', 'pci_dss', 'ndpa', 'icfr',
                'ai_assistant', 'analytics', 'reporting', 'committee', 'evidence_repo', 'performance',
                'quantification', 'loss_events', 'regulatory', 'integrations', 'workflows',
            ],
            'max_users' => 100,
            'max_activations' => 5,
            'grace_days' => 14,
        ],
    ],
];
