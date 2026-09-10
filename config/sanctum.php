<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from the following domains / hosts will receive stateful API
    | authentication cookies. Typically, these should include your local
    | and production domains which access your API via a frontend SPA.
    |
    */

    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
        Sanctum::currentApplicationUrlWithPort(),
        // Sanctum::currentRequestHost(),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | This array contains the authentication guards that will be checked when
    | Sanctum is trying to authenticate a request. If none of these guards
    | are able to authenticate the request, Sanctum will use the bearer
    | token that's present on an incoming request for authentication.
    |
    */

    // Empty on purpose. The default ['web'] makes Sanctum accept the session
    // cookie as API authentication, which would let any authenticated browser
    // session call /api/v1 with the full rights of the logged-in user and no
    // token scope at all — every scope restriction below would be bypassable
    // from a tab the user already has open. The API authenticates by bearer
    // token, and only by bearer token.
    'guard' => [],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | This value controls the number of minutes until an issued token will be
    | considered expired. This will override any values set in the token's
    | "expires_at" attribute, but first-party sessions are not affected.
    |
    | LEFT NULL DELIBERATELY, AND IT IS NOT WHAT ENFORCES EXPIRY HERE.
    |
    | This setting belongs to Sanctum's own guard, and this application does not
    | use it — `'guard' => []` above, and every API request goes through
    | App\Http\Middleware\AuthenticateApiToken instead, which refuses a token
    | whose ApiToken::isExpired() is true. isExpired() reads the per-token
    | `expires_at` column and nothing else. Setting a number here would therefore
    | be worse than useless: it would look like a control in the config file
    | while changing nothing about which requests are accepted.
    |
    | A GLOBAL CEILING IS ALSO THE WRONG SHAPE for this platform. The two kinds
    | of token have genuinely different lifetimes — a personal token is bounded
    | by its owner's account (deactivate the leaver and every token they hold
    | stops working, see AuthenticateApiToken), while a client_credentials token
    | acts as nobody and no leaver process ever touches it. One number cannot be
    | right for both.
    |
    | WHERE THE ENFORCEMENT ACTUALLY IS: App\Models\ApiToken::booted() gives
    | every machine token an expires_at at creation — defaulting to
    | MACHINE_DEFAULT_LIFETIME_DAYS (365) and capped at
    | MACHINE_MAX_LIFETIME_DAYS (730) — across all three creation paths (the
    | admin screen, `php artisan api:token`, and direct model creation).
    | PREVIOUS BEHAVIOUR: with this null and expires_at nullable, a machine token
    | issued without an explicit lifetime never expired at all.
    |
    */

    'expiration' => null,

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | Sanctum can prefix new tokens in order to take advantage of numerous
    | security scanning initiatives maintained by open source platforms
    | that notify developers if they commit tokens into repositories.
    |
    | See: https://docs.github.com/en/code-security/secret-scanning/about-secret-scanning
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | When authenticating your first-party SPA with Sanctum you may need to
    | customize some of the middleware Sanctum uses while processing the
    | request. You may change the middleware listed below as required.
    |
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sanctum Routes
    |--------------------------------------------------------------------------
    |
    | FALSE suppresses /sanctum/csrf-cookie. That route exists for first-party
    | SPA cookie authentication, which this platform does not do — the API is
    | bearer-token only. Leaving it registered would also put an unguarded route
    | in the api group, which RouteAuthorizationTest (WP-00) fails on, and
    | allowlisting it there to keep a route nothing calls would be the wrong way
    | round.
    |
    */

    'routes' => false,

];
