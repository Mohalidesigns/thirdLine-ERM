<?php

namespace App\Http\Middleware\Tprm;

use App\Models\Tprm\PortalUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The portal's front door — FR-PRT-01, and half of AC-14.
 *
 * IT BINDS THE TENANT FROM THE AUTHENTICATED PORTAL USER, and that is the
 * single most important line in this file. Every portal query then runs under
 * the same global organisation scope the internal application uses, so a
 * vendor cannot read another tenant's assessment even when a controller
 * forgets to filter — which one of them eventually will.
 *
 * THERE IS NO MFA CHECK HERE, AND THAT IS NOT AN OMISSION. A guard session
 * only ever comes into existence inside `PortalAuthService::completeMfa()`,
 * after a code has been verified. So reaching this middleware at all means MFA
 * has already passed. The half-authenticated state — password accepted, code
 * outstanding — is not a session on this guard; it is a pending id in the
 * session, and `EnsurePendingPortalUser` guards it. A middleware that let a
 * half-authenticated session through and then policed it with an exempt list
 * would be one forgotten route name away from an MFA bypass.
 *
 * The session flag is still checked, for one narrow case: an administrator
 * disables an account's second factor while its session is live. Without the
 * check that session keeps working until it expires.
 *
 * `hasSecondFactor()`, NOT `mfa_enabled`. The email method has no enrolment
 * step and therefore never sets that column — reading it directly locked every
 * email-method vendor out of the dashboard they had just signed in to, which
 * is what the probe caught.
 */
class AuthenticatePortal
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var PortalUser|null $user */
        $user = Auth::guard('tprm-portal')->user();

        if ($user === null) {
            return $this->refuse($request, 'Please sign in to continue.');
        }

        if (! $user->canAuthenticate() || ! $user->hasSecondFactor()) {
            return $this->endSession($request, 'This account is no longer active. Contact your client\'s risk team.');
        }

        if ($request->session()->get('tprm_portal_mfa_verified') !== $user->getKey()) {
            return $this->endSession($request, 'Please sign in again.');
        }

        /*
         * The tenant is the one that invited this account, never anything the
         * request supplies. A portal user belongs to exactly one organisation
         * by construction — the unique index is on (organization_id, email) —
         * so there is nothing to choose and nothing to spoof.
         */
        TenantContext::set((int) $user->organization_id);

        return $next($request);
    }

    /**
     * Drop the session and send them back to the entry page.
     *
     * NOT named `terminate()`. Laravel's kernel calls `terminate()` on every
     * middleware instance that defines one — the terminable-middleware
     * convention — so a private helper by that name is invoked by the
     * framework after every portal response, with the wrong arguments. It
     * fails on the first request; the only reason this is a comment rather
     * than an outage is that the AC-14 probe suite ran before anything shipped.
     */
    private function endSession(Request $request, string $message): Response
    {
        $organizationId = Auth::guard('tprm-portal')->user()?->organization_id;

        Auth::guard('tprm-portal')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->refuse($request, $message, $organizationId);
    }

    private function refuse(Request $request, string $message, ?int $organizationId = null): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $request->session()->put('url.intended', $request->fullUrl());

        return redirect()
            ->route('tprm-portal.entry')
            ->with('error', $message);
    }
}
