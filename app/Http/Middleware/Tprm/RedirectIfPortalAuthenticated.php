<?php

namespace App\Http\Middleware\Tprm;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keep a signed-in vendor off the login and invitation screens.
 *
 * It checks ONLY the portal guard. An internal user with a `web` session who
 * opens the portal login page is a guest here and should see the form — they
 * are two different identities, and treating an internal session as "already
 * signed in" would be the first crack in the separation AC-14 tests.
 */
class RedirectIfPortalAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::guard('tprm-portal')->check()) {
            return redirect()->route('tprm-portal.dashboard');
        }

        return $next($request);
    }
}
