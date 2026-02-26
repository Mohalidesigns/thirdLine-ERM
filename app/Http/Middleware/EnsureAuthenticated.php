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
            return redirect('/login')->with('error', 'Please log in to continue.');
        }

        $user = Auth::user();

        // Check if account is active
        if (!$user->is_active) {
            Auth::logout();
            return redirect('/login')->with('error', 'Your account has been deactivated.');
        }

        // Check session timeout (15 minutes inactivity)
        $lastActivityAt = $user->last_activity_at;
        $timeout = 15 * 60; // 15 minutes in seconds

        if ($lastActivityAt && now()->diffInSeconds($lastActivityAt) > $timeout) {
            Auth::logout();
            $request->session()->invalidate();
            return redirect('/login')->with('error', 'Your session has expired due to inactivity.');
        }

        // Update last activity timestamp
        $user->update(['last_activity_at' => now()]);

        return $next($request);
    }
}
