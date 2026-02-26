<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Auto-login middleware for development environment.
 * Automatically authenticates as the first admin user when no user is logged in.
 */
class AutoLoginDev
{
    public function handle(Request $request, Closure $next)
    {
        if (app()->environment('local') && !Auth::check()) {
            $user = User::first();
            if ($user) {
                Auth::login($user);
            }
        }

        return $next($request);
    }
}
