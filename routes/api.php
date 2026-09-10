<?php

use App\Http\Controllers\Api\McpController;
use App\Http\Controllers\Api\V1\GraphController;
use App\Http\Controllers\Api\V1\JobRunController;
use App\Http\Controllers\Api\V1\MeasureSeriesController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\ResourceController;
use App\Http\Controllers\Scim\ScimGroupController;
use App\Http\Controllers\Scim\ScimUserController;
use App\Models\ApiToken;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

/*
 * WP-07 — the rate limit is PER TOKEN, and each token carries its own ceiling.
 *
 * Per IP would be wrong in both directions here: several integrations behind
 * one corporate NAT would throttle each other, and one runaway script would be
 * indistinguishable from the rest of the building. Per token also means a
 * misbehaving integration can be given a lower ceiling without touching anyone
 * else's.
 */
RateLimiter::for('api-token', function (Request $request) {
    /** @var ApiToken|null $token */
    $token = $request->attributes->get('api_token');

    if ($token === null) {
        // Unauthenticated attempts, keyed by IP. Deliberately tight: the only
        // thing an unauthenticated caller can be doing here is guessing tokens.
        return Limit::perMinute(20)->by('api-anon:'.$request->ip());
    }

    return Limit::perMinute(max(1, (int) $token->rate_limit_per_minute))->by('api-token:'.$token->id);
});

/*
 * SCIM 2.0 PROVISIONING.
 *
 * Keyed on the PRESENTED BEARER TOKEN, not the IP: the callers are directory
 * services (Entra ID, Okta), one per customer, and several customers may egress
 * through the same cloud address. Keying on the credential also means the limit
 * applies before AuthenticateScim resolves it, so token guessing is throttled
 * too — which is why `throttle:scim` is listed BEFORE `scim.auth` on the group.
 *
 *   300 per minute per token — Entra ID sends one HTTP request per user or group
 *   change and bursts hard on the first full sync of a directory. A 5,000-staff
 *   bank's initial import is a long tail of requests, not a spike, but the
 *   ceiling has to clear the burst or provisioning fails silently at the
 *   customer end and nobody hears about it for a week.
 *
 *   20 per minute per IP when NO token is presented at all. The only thing an
 *   unauthenticated caller can be doing on these endpoints is probing, and this
 *   mirrors the `api-anon` limit already used in routes/api.php.
 */
RateLimiter::for('scim', function (Request $request) {
    $bearer = $request->bearerToken();

    if ($bearer === null || $bearer === '') {
        return Limit::perMinute(20)->by('scim-anon:'.$request->ip());
    }

    return Limit::perMinute(300)->by('scim:'.hash('sha256', $bearer));
});

/*
|--------------------------------------------------------------------------
| API routes
|--------------------------------------------------------------------------
|
| SCIM 2.0 provisioning. These are exempt from the `permission:` invariant
| that governs routes/web.php because the caller is a directory service, not a
| user: there is no role to check. Authorization is the bearer token, and the
| token also carries the tenant — see AuthenticateScim.
|
| RouteAuthorizationTest asserts that scim.auth is present on every one of
| them, so the exemption cannot be widened by accident.
|
*/

/*
 * PREVIOUS BEHAVIOUR: the SCIM group carried no rate limit at all, so
 * `GET /scim/v2/Users` and the token check in front of it accepted unlimited
 * requests from anyone who could reach the host — both a token-guessing oracle
 * and a way to enumerate a customer's whole staff directory at line rate.
 *
 * `throttle:scim` is listed BEFORE `scim.auth` ON PURPOSE. Middleware runs in
 * the order given, so putting the limiter first means an invalid or absent
 * token is counted too; behind the authentication check it would only ever
 * limit callers who had already succeeded, which is the wrong half.
 *
 * The limiter keys on the PRESENTED BEARER TOKEN rather than the IP, for the
 * same reason `api-token` above does — see its definition for the numbers.
 */
Route::prefix('scim/v2')
    ->middleware(['throttle:scim', 'scim.auth'])
    ->group(function () {
        Route::get('Users', [ScimUserController::class, 'index']);
        Route::post('Users', [ScimUserController::class, 'store']);
        Route::get('Users/{id}', [ScimUserController::class, 'show']);
        Route::put('Users/{id}', [ScimUserController::class, 'update']);
        Route::patch('Users/{id}', [ScimUserController::class, 'patch']);
        Route::delete('Users/{id}', [ScimUserController::class, 'destroy']);

        Route::get('Groups', [ScimGroupController::class, 'index']);
        Route::get('Groups/{id}', [ScimGroupController::class, 'show']);
        Route::patch('Groups/{id}', [ScimGroupController::class, 'patch']);
    });

/*
|--------------------------------------------------------------------------
| REST API v1 (WP-07 TASK 2)
|--------------------------------------------------------------------------
|
| Bearer token only — api.auth resolves the token, refuses it if revoked,
| expired or owned by a deactivated user, and binds the tenant FROM THE TOKEN.
| There is no parameter anywhere below that changes which organization a request
| reads.
|
| Every route carries `scope:` — the API's authorization guard, which requires
| BOTH the token's scope and the permission of the user behind it.
| ApiAuthorizationTest fails if any route here is missing one.
|
| The version is in the path. A version stays available for at least 12 months
| after its successor ships; removals are announced with Deprecation and Sunset
| headers before they happen.
|
*/

Route::prefix('api/v1')
    ->middleware(['api.auth', 'throttle:api-token', 'idempotency'])
    ->group(function () {
        // What this API serves, and which scope each resource needs. An
        // integrator has to be able to discover the surface before they know
        // what to ask for.
        Route::get('/', [ResourceController::class, 'catalogue'])
            ->middleware('scope:dashboard.view')->name('api.v1.catalogue');

        Route::get('me', [MeController::class, 'show'])
            ->middleware('scope:dashboard.view')->name('api.v1.me');

        // Graph traversal, which a flat resource list cannot express: "every
        // risk under this business unit, including its sub-units".
        Route::get('graph/{object}/descendants', [GraphController::class, 'descendants'])
            ->middleware('scope:risk.view')->name('api.v1.graph.descendants');
        Route::get('graph/{object}/ancestors', [GraphController::class, 'ancestors'])
            ->middleware('scope:risk.view')->name('api.v1.graph.ancestors');
        Route::get('graph/{object}/related', [GraphController::class, 'related'])
            ->middleware('scope:risk.view')->name('api.v1.graph.related');

        // A measure over time, which is the shape every reporting client wants
        // and which paging over measure-values makes needlessly hard.
        Route::get('measures/{measure}/series', [MeasureSeriesController::class, 'show'])
            ->middleware('scope:measure.view')->name('api.v1.measures.series');

        Route::get('jobs/{jobRun}', [JobRunController::class, 'show'])
            ->middleware('scope:job.view')->name('api.v1.jobs.show');

        // The generic resource surface. One controller, one allowlist per
        // resource — see App\Http\Api\ApiResourceRegistry.
        //
        // `scope.resource` rather than `scope:` because the scope these need
        // depends on which resource the path names: /risks wants risk.view,
        // /loss-events wants loss_event.view. It resolves the resource from the
        // route and applies the same two-sided check.
        Route::get('{resource}', [ResourceController::class, 'index'])
            ->middleware('scope.resource')->name('api.v1.index');
        Route::post('{resource}', [ResourceController::class, 'store'])
            ->middleware('scope.resource')->name('api.v1.store');
        Route::get('{resource}/{id}', [ResourceController::class, 'show'])
            ->middleware('scope.resource')->name('api.v1.show');
        Route::match(['put', 'patch'], '{resource}/{id}', [ResourceController::class, 'update'])
            ->middleware('scope.resource')->name('api.v1.update');
    });

/*
|--------------------------------------------------------------------------
| MCP server (WP-07 TASK 5)
|--------------------------------------------------------------------------
|
| JSON-RPC 2.0, authenticated with the same bearer token as the REST API — so an
| agent has exactly the rights of the person who issued its token, and there is
| no second credential to audit.
|
| Every tool is read-only. An agent that can write to a risk register can
| fabricate a control test result, and nothing after the fact distinguishes that
| from a real one. Governed writes go through the workflow engine and its
| approval scope, not through here.
|
| dashboard.view is the "may use this application" permission; each tool then
| applies the specific one it needs (risk.view, measure.view, task.view) against
| the token AND its user.
|
*/

Route::post('mcp', [McpController::class, 'handle'])
    ->middleware(['api.auth', 'throttle:api-token', 'scope:dashboard.view'])
    ->name('api.mcp');
