<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds an authenticated session at the door until the second factor is done.
 *
 * Applied to the whole `auth` group, so it covers every screen rather than
 * only the ones someone remembered to annotate.
 *
 * ---------------------------------------------------------------------------
 * GATED OFF BY DEFAULT — features.mfa_totp
 * ---------------------------------------------------------------------------
 *
 * The whole MFA flow is behind the `mfa_totp` flag in config/features.php,
 * which defaults to FALSE, because the implementation this middleware guards is
 * broken in three specific ways:
 *
 *   1. SIGN-IN CANNOT COMPLETE. AuthController::login() calls Auth::logout()
 *      before redirecting to mfa.verify, and AuthController::verifyMfa() sets
 *      session('mfa_verified') without ever calling Auth::login() again. Any
 *      user with mfa_enabled = true is permanently locked out: there is no
 *      code path that returns them to an authenticated session.
 *
 *   2. THE CODES ARE NOT RFC 6238 TOTP. AuthController::verifyTotpCode() packs
 *      the time step with pack('N', $time) — four bytes where the spec requires
 *      an eight-byte big-endian counter — so the HMAC is taken over the wrong
 *      message and no authenticator app can produce a code it accepts.
 *
 *   3. THE SHARED SECRET WENT TO A THIRD PARTY. The setup screen built its QR
 *      code with api.qrserver.com, so the enrolling user's browser handed the
 *      TOTP seed and their email address to an external service.
 *
 * PREVIOUS BEHAVIOUR: this middleware redirected an mfa_enabled user to
 * mfa.verify, and a user matched by organizations.settings->mfa_required_roles
 * to mfa.setup. Both destinations are dead ends today — the first cannot be
 * completed at all, the second enrols the user into the first. That is why the
 * gate is here rather than only on the routes: a redirect into a 404 is a
 * broken product, and a redirect into a working enrolment screen for a flow
 * that cannot be verified is a self-inflicted lockout.
 *
 * THIS IS DEFERRED, NOT FORGOTTEN. The rebuild is scheduled for deployment
 * readiness and none of the MFA code has been deleted. config/features.php
 * carries the full list of what must be true before FEATURE_MFA_TOTP is turned
 * on; in short: sign-in completes, the counter is eight bytes and covered by an
 * RFC 6238 known-answer test, the QR code is rendered in-process, recovery
 * exists, and MfaEnforcementTest passes with the flag on.
 *
 * WHEN THE FLAG IS ON, this middleware behaves exactly as it always did. The
 * gate adds one early return and changes nothing else.
 */
class EnsureMfaVerified
{
    /**
     * Routes that must stay reachable, or a user who is required to enrol has
     * no way to enrol and no way to leave.
     *
     * @var list<string>
     */
    private const EXEMPT_ROUTES = [
        'mfa.setup',
        'mfa.enable',
        'mfa.verify',
        'logout',
        'login',
    ];

    /**
     * Is the TOTP flow switched on in this environment?
     *
     * The single answer for every gate — this middleware, AuthController's
     * post-login branch, SsoController::completeSignIn() and the user menu all
     * ask here, so there is one place to change when the rebuild lands and no
     * chance of a surface being re-enabled by half.
     *
     * Deliberately a loose cast rather than config()->boolean(), matching
     * EnsureFeatureEnabled: an operator who writes FEATURE_MFA_TOTP=1 should
     * get a working flag rather than a 500.
     */
    public static function featureEnabled(): bool
    {
        return filter_var(config('features.mfa_totp', false), FILTER_VALIDATE_BOOLEAN);
    }

    public function handle(Request $request, Closure $next): Response
    {
        // THE GATE. With the flag off there is nowhere safe to send anybody:
        // mfa.verify and mfa.setup both 404 (see routes/web.php), and even if
        // they did not, neither can be completed. Passing through is the only
        // behaviour that does not lock a user out of a platform they are
        // entitled to use. Note this deliberately ignores mfa_enabled and
        // mfa_required_roles — a tenant that set either of those before the
        // defect was found must not have its users stranded by it.
        if (! self::featureEnabled()) {
            return $next($request);
        }

        $user = Auth::user();

        if (! $user) {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), self::EXEMPT_ROUTES, true)) {
            return $next($request);
        }

        if (session('mfa_verified')) {
            return $next($request);
        }

        if ($user->mfa_enabled) {
            return $this->deny($request, route('mfa.verify'), 'Multi-factor authentication is required.');
        }

        // Not enrolled, but the organization requires a second factor for this
        // user's role: send them to enrol rather than letting them through
        // with the requirement unmet.
        if ($this->mfaRequiredFor($user)) {
            return $this->deny(
                $request,
                route('mfa.setup'),
                'Your role requires multi-factor authentication. Set it up to continue.'
            );
        }

        return $next($request);
    }

    private function deny(Request $request, string $url, string $message): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 403);
        }

        return redirect()->to($url)->with('warning', $message);
    }

    private function mfaRequiredFor($user): bool
    {
        $required = (array) ($user->organization?->settings['mfa_required_roles'] ?? []);

        return $required !== [] && $user->hasAnyRole($required);
    }
}
