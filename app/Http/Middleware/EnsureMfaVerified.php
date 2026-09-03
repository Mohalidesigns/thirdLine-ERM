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
 * Behind features.mfa_totp. The flow this guards was rebuilt in migration
 * Phase 1 — see config/features.php for what changed and why the flag still
 * defaults to off. With the flag off this middleware passes everyone through,
 * deliberately ignoring mfa_enabled and mfa_required_roles, so a tenant that
 * set either before the rebuild cannot strand its users. With the flag on, an
 * enrolled user without `mfa_verified` in the session is sent to mfa.verify
 * (MfaVerifyController completes sign-in), and a user whose role requires a
 * second factor but who has not enrolled is sent to mfa.setup.
 */class EnsureMfaVerified
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
     * The single answer for every gate — this middleware, the login
     * controller, SsoController::completeSignIn() and the user menu all
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
        // THE GATE. With the flag off mfa.verify and mfa.setup 404 (see
        // routes/auth.php), so passing through is the only behaviour that does
        // not lock a user out of a platform they are entitled to use.
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
