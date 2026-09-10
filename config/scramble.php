<?php

return [
    /*
     * Which routes to document. String or array form; use Scramble::routes() for custom selection.
     *
     * 'api_path' => [
     *     'include' => 'api',
     *     'exclude' => ['api/internal'],
     * ],
     *
     * Without *, patterns match path segments (api matches api and api/users, not apiary).
     * With *, Str::is is used (e.g. api/v*).
     *
     * One static include → default server is /{include} and paths are stripped (/users).
     * Multiple includes or wildcards → server defaults to / and paths stay full (/api/users).
     * Override with `servers`, or use Scramble::registerApi() for separate bases.
     */
    // The documentation endpoints are excluded from the documentation. A spec
    // that describes how to fetch itself is noise in every generated client.
    'api_path' => [
        'include' => 'api',
        'exclude' => ['api/docs', 'api/docs.json'],
    ],

    /*
     * Your API domain. By default, app domain is used. This is also a part of the default API routes
     * matcher, so when implementing your own, make sure you use this config if needed.
     */
    'api_domain' => null,

    /*
     * The path where your OpenAPI specification will be exported.
     */
    'export_path' => 'api.json',

    /*
     * Cache configuration for the generated OpenAPI document.
     *
     * Use `scramble:cache` to warm the cache and `scramble:clear` to invalidate it.
     */
    'cache' => [
        'key' => 'scramble.openapi',
        'store' => 'file',
    ],

    'info' => [
        'version' => env('API_VERSION', '1.0.0'),

        'description' => <<<'MARKDOWN'
        The Atheris ERM API.

        ## Authentication

        Bearer tokens only. Issue a personal access token from **Administration →
        API tokens**, or a machine-to-machine token with
        `php artisan api:token`. Send it as `Authorization: Bearer <token>`.

        The session cookie is deliberately **not** accepted: a token carries
        scopes, a session does not, and accepting both would make every scope
        restriction bypassable from a browser tab the user already has open.

        ## Scopes

        A token may do only what its scopes allow **and** what the user behind it
        is permitted to do. The narrower of the two wins, always — a token with
        `risk.delete` held by someone without that permission cannot delete.

        ## Tenancy

        Every request is scoped to the token owner's organization. There is no
        parameter that widens it.

        ## Versioning

        The version is in the path (`/api/v1`). A version stays available for at
        least 12 months after its successor ships. Removals are announced with a
        `Deprecation` and `Sunset` header on every response from the affected
        endpoint before they happen.

        ## Conventions

        - Filtering: `?filter[status]=open&filter[rating]=High,Critical`
        - Sorting: `?sort=-created_at,title`
        - Sparse fieldsets: `?fields[risks]=risk_code,title`
        - Includes: `?include=owner,category`
        - Pagination: cursor by default — follow `links.next`.
        - `Idempotency-Key` on POST and PUT replays the first response rather
          than repeating the write.
        MARKDOWN,
    ],

    'ui' => [
        'title' => 'Atheris ERM API',
    ],

    'renderer' => 'elements',

    'renderers' => [
        /*
         * Stoplight Elements config options: https://docs.stoplight.io/docs/elements/b074dc47b2826-elements-configuration-options
         */
        'elements' => [
            'view' => 'scramble::docs',
            'theme' => 'light',
            'hideTryIt' => false,
            'hideSchemas' => false,
            'logo' => '',
            'tryItCredentialsPolicy' => 'include',
            'layout' => 'responsive',
            'router' => 'hash',
        ],
        /*
         * Scalar API reference config options: https://scalar.com/products/api-references/configuration
         */
        'scalar' => [
            'view' => 'scramble::scalar',
            'cdn' => 'https://cdn.jsdelivr.net/npm/@scalar/api-reference',
            'theme' => 'laravel',
            'proxyUrl' => 'https://proxy.scalar.com',
            'darkMode' => false,
            'showDeveloperTools' => 'never',
            'agent' => ['disabled' => true],
            'credentials' => 'include',
        ],
    ],

    /*
     * The list of servers of the API. By default, when `null`, server URL will be created from
     * `scramble.api_path` and `scramble.api_domain` config variables. When providing an array, you
     * will need to specify the local server URL manually (if needed).
     *
     * Example of non-default config (final URLs are generated using Laravel `url` helper):
     *
     * ```php
     * 'servers' => [
     *     'Live' => 'api',
     *     'Prod' => 'https://scramble.dedoc.co/api',
     * ],
     * ```
     */
    'servers' => null,

    /**
     * Determines how Scramble stores the descriptions of enum cases.
     * Available options:
     * - 'description' – Case descriptions are stored as the enum schema's description using table formatting.
     * - 'extension' – Case descriptions are stored in the `x-enumDescriptions` enum schema extension.
     *
     *    @see https://redocly.com/docs-legacy/api-reference-docs/specification-extensions/x-enum-descriptions
     * - false - Case descriptions are ignored.
     */
    'enum_cases_description_strategy' => 'description',

    /**
     * Determines how Scramble stores the names of enum cases.
     * Available options:
     * - 'names' – Case names are stored in the `x-enumNames` enum schema extension.
     * - 'varnames' - Case names are stored in the `x-enum-varnames` enum schema extension.
     * - false - Case names are not stored.
     */
    'enum_cases_names_strategy' => false,

    /**
     * When Scramble encounters deep objects in query parameters, it flattens the parameters so the generated
     * OpenAPI document correctly describes the API. Flattening deep query parameters is relevant until
     * OpenAPI 3.2 is released and query string structure can be described properly.
     *
     * For example, this nested validation rule describes the object with `bar` property:
     * `['foo.bar' => ['required', 'int']]`.
     *
     * When `flatten_deep_query_parameters` is `true`, Scramble will document the parameter like so:
     * `{"name":"foo[bar]", "schema":{"type":"int"}, "required":true}`.
     *
     * When `flatten_deep_query_parameters` is `false`, Scramble will document the parameter like so:
     *  `{"name":"foo", "schema": {"type":"object", "properties":{"bar":{"type": "int"}}, "required": ["bar"]}, "required":true}`.
     */
    'flatten_deep_query_parameters' => true,

    /*
     * The specification is a complete map of the platform's API — every
     * endpoint, every field name, every filter. On a risk register holding
     * examination findings and loss events that is reconnaissance material, so
     * it sits behind the same authentication as the rest of the application
     * plus an explicit permission, rather than behind Scramble's default
     * "local environment only" check.
     *
     * The permission also satisfies WP-00's invariant that every route in the
     * web or api group carries a `permission:` or `can:` guard.
     */
    'middleware' => [
        'web',
        'auth',
        'permission:api.docs',
    ],

    'extensions' => [],

    /*
     * Automatically document API security (OpenAPI `security` / `securitySchemes`) based on route
     * middleware.
     *
     * Disabled by default. Uncomment the line below to enable `MiddlewareAuthSecurityStrategy`.
     * When at least one documented route uses middleware matching the configured patterns (by default
     * `auth` and `auth:*`), bearer auth is applied globally. Routes without matching middleware are
     * marked as public (`security: []`).
     *
     * Set to `null` explicitly to disable. If you already configure security manually via
     * `afterOpenApiGenerated` / `extendOpenApi`, keep this disabled to avoid duplicate schemes.
     *
     * Customize with a class-string or [class, options]:
     *
     * 'security_strategy' => [
     *     \Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy::class,
     *     [
     *         'middleware' => ['auth', 'auth:*'],
     *         'scheme' => \Dedoc\Scramble\Support\Generator\SecurityScheme::http('bearer'),
     *     ],
     * ],
     */
    /*
     * Documents the bearer scheme from the routes' own middleware, so the spec
     * cannot claim an endpoint is public when it is guarded — or the reverse,
     * which is worse: a spec that shows auth on an endpoint that has none is
     * how an unguarded route survives review.
     */
    'security_strategy' => [
        \Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy::class,
        [
            'middleware' => ['auth:sanctum', 'auth', 'auth:*'],
            'scheme' => \Dedoc\Scramble\Support\Generator\SecurityScheme::http('bearer'),
        ],
    ],
];
