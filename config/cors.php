<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS)
|--------------------------------------------------------------------------
|
| WHY THIS FILE EXISTS. Until now the application shipped no config/cors.php at
| all, so Laravel's packaged default applied — and that default is
| `'allowed_origins' => ['*']` over `api/*`. Nothing in the codebase set
| allowed_origins anywhere, so no one had ever made a decision about it; the
| policy was whatever the framework happened to ship.
|
| THE PRACTICAL IMPACT WAS LIMITED, AND SAYING SO IS MORE USEFUL THAN
| OVERSTATING IT. The v1 API authenticates by bearer token only
| (config/sanctum.php sets `'guard' => []`), `supports_credentials` was false,
| and CORS is a browser-enforced policy — it does not affect curl, a server-side
| integration, or anything that is not a browser honouring the same-origin
| policy. A wildcard origin with no credentials therefore did not, on its own,
| let a foreign page read a customer's risk register: the page would still need a
| token, and a token is not a cookie the browser attaches for it.
|
| IT IS STILL WRONG, FOR THREE REASONS.
|
|   1. `*` is not a policy, it is the absence of one. A bank's information
|      security reviewer reads the config directory. "No explicit CORS policy"
|      is a finding on sight, and defending it costs more than closing it.
|
|   2. It is one line away from being exploitable. The day somebody enables
|      Sanctum's stateful (cookie) guard for a first-party SPA — the obvious next
|      step for this codebase, and the reason `stateful` is already configured in
|      config/sanctum.php — a wildcard origin becomes a cross-site read of the
|      whole API. Browsers refuse `*` together with credentials, so the failure
|      would present as a bug, and the natural fix under deadline is to reflect
|      the request's Origin header back, which is strictly worse than `*`.
|
|   3. It advertises the surface. A permissive preflight response tells any page
|      on the internet which methods and headers this API accepts.
|
| SO: THE ALLOWLIST DEFAULTS TO CLOSED. An empty `allowed_origins` means no
| cross-origin browser request is permitted by this deployment. That is the right
| default for an on-premise platform whose UI is served from the same host as its
| API — the first-party application does not make cross-origin requests to
| itself, so nothing legitimate breaks. An environment that genuinely needs a
| browser client on another origin lists it in CORS_ALLOWED_ORIGINS, and that is
| a deliberate, reviewable change to that environment's .env.
|
| ENVIRONMENT VARIABLES
|
|   CORS_ALLOWED_ORIGINS
|       Comma-separated absolute origins, scheme and host and port, no trailing
|       slash. Example:
|           CORS_ALLOWED_ORIGINS=https://risk.examplebank.ng,https://portal.examplebank.ng
|       Leave unset for the closed default. `*` is refused — see below.
|
|   CORS_ALLOWED_ORIGIN_PATTERNS
|       Comma-separated regular expressions, for the one case a list cannot
|       express: per-tenant subdomains. Example:
|           CORS_ALLOWED_ORIGIN_PATTERNS=#^https://[a-z0-9-]+\.examplebank\.ng$#
|       Anchor every pattern with ^ and $. An unanchored pattern such as
|       #examplebank\.ng# matches https://examplebank.ng.attacker.test and gives
|       away everything this file is for.
|
|   CORS_SUPPORTS_CREDENTIALS
|       Off. Turn it on only alongside a real, enumerated origin list, never
|       alongside a pattern you have not read twice. With credentials on, an
|       allowed origin can read authenticated responses using the user's own
|       session cookie, which is cross-site request forgery with the results
|       returned.
|
*/

/**
 * Split a comma-separated environment variable into a clean list.
 *
 * Returns [] for null, an empty string, or a value of only separators, so an
 * operator who writes `CORS_ALLOWED_ORIGINS=` gets the closed default rather
 * than a list containing one empty string — which some CORS middleware treat as
 * a match for the empty Origin header.
 *
 * @return list<string>
 */
$list = static function (?string $value): array {
    return array_values(array_filter(
        array_map('trim', explode(',', (string) $value)),
        static fn (string $item): bool => $item !== '',
    ));
};

/**
 * The configured origins, with `*` removed.
 *
 * REFUSING THE WILDCARD IS THE POINT OF THIS FILE, so it is refused here rather
 * than trusted to review. If an operator sets CORS_ALLOWED_ORIGINS=* — the
 * obvious thing to try when an integration is failing at 2am — the entry is
 * dropped and the deployment stays closed. A wildcard that has to be argued for
 * in a merge request is a decision; one that can be typed into a .env under
 * pressure is an accident waiting for a reviewer to find.
 *
 * @var list<string>
 */
$allowedOrigins = array_values(array_filter(
    $list(env('CORS_ALLOWED_ORIGINS')),
    static fn (string $origin): bool => $origin !== '*',
));

return [

    /*
     * WHAT IS COVERED. `api/*` is the v1 REST surface, `mcp` the JSON-RPC
     * endpoint and `scim/v2/*` the provisioning endpoints — every path in this
     * application that a browser on another origin might be pointed at. The web
     * UI is not listed: it is session and CSRF protected and is only ever loaded
     * from this host, and adding it here would only create a way to relax that.
     *
     * `sanctum/csrf-cookie` is deliberately ABSENT. That route exists to hand a
     * CSRF token to a first-party SPA, and a first-party SPA served from this
     * host does not need CORS to reach it. Listing it would be the first half of
     * enabling cookie-authenticated cross-origin access.
     */
    'paths' => ['api/*', 'mcp', 'scim/v2/*'],

    /*
     * Methods are not the control here — the origin is. A caller that is not on
     * the allowlist gets no CORS headers at all and the browser blocks the
     * response whatever the method, so enumerating verbs would add friction for
     * the integrations we have allowed without narrowing anything for anyone
     * else. The API's own authorization (api.auth + scope:) decides what a
     * method may actually do.
     */
    'allowed_methods' => ['*'],

    /*
     * CLOSED BY DEFAULT. An empty list means no cross-origin browser request is
     * permitted. Populate CORS_ALLOWED_ORIGINS per environment.
     */
    'allowed_origins' => $allowedOrigins,

    /*
     * For per-tenant subdomains, which an explicit list cannot express. Empty by
     * default. Anchor every pattern; see the note above.
     */
    'allowed_origins_patterns' => $list(env('CORS_ALLOWED_ORIGIN_PATTERNS')),

    /*
     * Which request headers a permitted origin may send. Only meaningful for an
     * origin that already passed the allowlist, so `*` costs nothing and avoids
     * an integration failing on a preflight because somebody forgot to list
     * `Idempotency-Key` (see the `idempotency` middleware in routes/api.php).
     */
    'allowed_headers' => ['*'],

    /*
     * Response headers a permitted origin's JavaScript may READ. Deliberately a
     * short, explicit list rather than `*`:
     *
     *   Retry-After / X-RateLimit-*  an integration that cannot read these
     *                                cannot back off correctly, and instead
     *                                retries into the limit.
     *   Deprecation / Sunset         routes/api.php promises these headers
     *                                before a version is withdrawn; a client
     *                                that cannot read them cannot act on them.
     */
    'exposed_headers' => [
        'Retry-After',
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
        'Deprecation',
        'Sunset',
    ],

    /*
     * Preflight cache lifetime, in seconds. Ten minutes: long enough that a
     * chatty client is not preflighting every request, short enough that
     * removing an origin from the allowlist takes effect the same morning rather
     * than being cached in browsers for a day.
     */
    'max_age' => 600,

    /*
     * OFF, and it must stay off unless allowed_origins is a short, explicit list
     * of hosts this organisation controls. With this on, a permitted origin can
     * read authenticated responses using the user's own session cookie.
     */
    'supports_credentials' => filter_var(env('CORS_SUPPORTS_CREDENTIALS', false), FILTER_VALIDATE_BOOLEAN),

];
