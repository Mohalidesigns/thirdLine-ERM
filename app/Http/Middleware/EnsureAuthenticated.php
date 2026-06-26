<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAuthenticated
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if user is authenticated
        if (!Auth::check()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            // Store the intended URL for redirect after login
            $request->session()->put('url.intended', $request->fullUrl());

            return redirect('/login')->with('error', 'Please log in to continue.');
        }

        $user = Auth::user();

        // Check if account is active
        if (!$user->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return redirect('/login')->with('error', 'Your account has been deactivated.');
        }

        // Check session timeout (30 minutes inactivity).
        // Carbon 3's diffInSeconds is signed by default, so take the absolute
        // value explicitly — otherwise a negative diff can surface weird
        // comparisons against the positive timeout.
        $lastActivityAt = $user->last_activity_at;
        $timeout = 30 * 60; // 30 minutes in seconds

        if ($lastActivityAt && abs(now()->diffInSeconds($lastActivityAt, true)) > $timeout) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return redirect('/login')->with('error', 'Your session has expired due to inactivity.');
        }

        // Update last activity timestamp
        $user->updateQuietly(['last_activity_at' => now()]);

        return $next($request);
    }
}
