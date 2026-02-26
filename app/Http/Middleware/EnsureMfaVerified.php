<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureMfaVerified
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        // If user doesn't have MFA enabled, allow access
        if (!$user || !$user->mfa_enabled) {
            return $next($request);
        }

        // If MFA is verified in session, allow access
        if (session('mfa_verified')) {
            return $next($request);
        }

        // Redirect to MFA verification
        return redirect()->route('mfa.verify');
    }
}
