<?php

use App\Http\Controllers\Bcms\AlertWebhookController;
use App\Http\Controllers\Bcms\CascadeAckController;
use Illuminate\Support\Facades\Route;

/*
 * Gateway-facing BCMS webhooks: a delivery receipt, a provider status
 * callback, and an inbound SMS/WhatsApp/USSD reply. Registered here rather
 * than in routes/web.php, and loaded under the `bcms-webhook` group
 * (bootstrap/app.php) rather than `web`, for the same reason
 * bootstrap/app.php gives for `tprm-portal`: assembled, not inherited. A
 * group that starts by inheriting all of `web` leaks whatever is added to
 * `web` next, and in this case `web` was actively hostile to the traffic
 * these routes exist to receive.
 *
 * GATE 2, BCMS PHASE 7 DEFECT 1. All three routes previously lived inside
 * routes/web.php's `feature:bcms` group and therefore inherited
 * `ValidateCsrfToken`. A gateway posts no `_token` and no session cookie, so
 * `tokensMatch()` failed on every single request and Laravel returned 419
 * before AlertWebhookController::reply()/status() or
 * CascadeAckController::inbound() ever ran — before `signatureOk()` or the
 * HMAC comparison in CascadeAckController got a chance to run at all. That
 * is the entire inbound half of EMNS, including roll-call acknowledgement:
 * the one mechanism by which this product establishes that a named person
 * is alive. `withoutMiddleware([ValidateCsrfToken::class])` on each of the
 * three would have closed the CSRF hole (the precedent is
 * `auth/sso/{slug}/acs` at the top of routes/web.php: "the IdP posts the
 * assertion from the user's browser, so there is no session CSRF token to
 * present; the signed assertion is the authentication" — read HMAC for
 * "signed assertion" here), but it would have left these routes inside
 * `web`'s full stack, including `StartSession`.
 *
 * `StartSession` sits ahead of the per-route throttle in the resolved `web`
 * stack, and config/session.php defaults the driver to `database`. That
 * means every request the rate limiter is about to reject — up to the
 * configured ceiling per bucket — had ALREADY written a session row to
 * MariaDB, on an endpoint with no credential of any kind. Lifting the
 * routes out of `web` entirely removes that amplification as well as the
 * CSRF check, rather than patching around the one defect that was found and
 * leaving the other in place.
 *
 * None of `web`'s machinery applies to a gateway callback: there is no
 * cookie to encrypt, no session to start, no CSRF token to validate, no
 * Inertia page to share props with, and no authenticated user to bind a
 * tenant from (`OrganizationScope` is inert with no tenant resolved either
 * way — both controllers set `TenantContext` by hand from the record the
 * credential resolves to, exactly as the signed calendar feed and the
 * cascade-ack routes already
 * do). What replaces a login on every route below is a per-provider or
 * per-node HMAC compared with `hash_equals`, checked inside the controller
 * — see AlertWebhookController::signatureOk() and
 * CascadeAckController's class docblock. The throttle exists only to bound
 * abuse of an endpoint that cannot carry a per-caller credential; it is not
 * what stops spoofing.
 */
Route::middleware(['feature:bcms'])->group(function () {
    /*
     * A person's roll-call reply, or a delivery receipt, arriving by
     * gateway. Throttled because it is the one route here without a
     * provider-scoped named limiter yet — the in-body token plus the rate
     * limit are what stand between this and a stranger, same as before the
     * move.
     */
    Route::post('bcms/cascade-inbound', [CascadeAckController::class, 'inbound'])
        ->middleware('throttle:60,1')
        ->name('bcms.cascade.inbound');

    /*
     * EMNS provider callbacks (Phase 7). `reply()` REFUSES an unsigned
     * callback outright, even when no secret is configured for `{provider}`
     * — a false SAFE during a live evacuation cannot be taken back. See
     * AlertWebhookController's class docblock for why `status()` is allowed
     * to trust an unconfigured secret differently. Both use named limiters
     * from `AppServiceProvider::registerBcmsWebhookRateLimiters()` (BCMS has
     * no service provider of its own to hold them), keyed on `{provider}+ip`
     * and sized from `config('bcms-gateways.alert_reply_rate_limit_per_minute')`
     * and `config('bcms-gateways.provider_status_rate_limit_per_minute')`
     * respectively — two keys, not the one shared ceiling this route used to
     * read, per ADR 0016 §4: the two routes serve different acceptance
     * criteria and the invariant that matters is that the life-safety
     * route's ceiling is never below criterion 1's demand, not that the two
     * numbers are equal.
     */
    Route::post('bcms/alert-reply/{provider}', [AlertWebhookController::class, 'reply'])
        ->middleware('throttle:bcms-alert-reply')
        ->name('bcms.alerts.reply');
    Route::post('bcms/provider-status/{provider}', [AlertWebhookController::class, 'status'])
        ->middleware('throttle:bcms-provider-status')
        ->name('bcms.alerts.provider-status');
});
