<?php

namespace App\Http\Middleware\Bcms;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * BCMS Phase 7, Gate 2 round 3, defect 1.
 *
 * The `bcms-webhook` group used to carry `ThirdLine\Platform\Tenancy\ResolveTenant`
 * for exactly one reason: clearing any `TenantContext` left ambient by
 * whatever ran earlier in the process, before
 * `InboundResponseHandler::recipientForNumber()`'s cross-tenant scan runs
 * (`TenantContext::clear()` is what makes `OrganizationScope` inert for that
 * scan — see the class docblock on `TenantContext` and on
 * `routes/bcms-webhooks.php`). `ResolveTenant` earns that job on every other
 * group by also reading `Auth::user()` and binding the tenant from it, but
 * this group never has a session and never will — there is nothing for it to
 * authenticate.
 *
 * `ResolveTenant::handle()` opens with `Auth::user()` unconditionally. With
 * `EncryptCookies` removed from this group (correctly — a gateway callback
 * carries no cookie), a raw, forged `remember_me` cookie is no longer
 * guaranteed to decrypt to garbage before it reaches `SessionGuard`. The
 * cookie name is publicly derivable
 * (`'remember_web_'.sha1(Illuminate\Auth\SessionGuard::class)`), so a crafted
 * cookie drove `Auth::user()` into `EloquentUserProvider::retrieveByToken()`
 * — one `select * from users where id = ? and deleted_at is null` per
 * request, run as GROUP middleware and therefore ahead of the per-route
 * throttle, on an endpoint with no credential of any kind. That is the same
 * amplification shape ADR 0016 §4 already forbids ahead of this group's
 * throttle, one step smaller than the `StartSession` row-write it replaced.
 *
 * This middleware is the narrower replacement: it does the one thing this
 * group ever needed from `ResolveTenant` — `TenantContext::clear()` — and
 * NOTHING else. No `Auth::user()`, no session access, no query of any kind.
 * Do not add anything here that touches the database or the auth guard; that
 * is precisely the mistake this class exists to undo.
 */
class ClearAmbientTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        TenantContext::clear();

        return $next($request);
    }
}
