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

    public function handle(Request $request, Closure $next): Response
    {
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
