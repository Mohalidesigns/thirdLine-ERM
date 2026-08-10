<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Handle an incoming request.
     *
     * Accepts one permission or several separated by "|", in which case any
     * one of them grants access.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = Auth::user();

        if (! $user) {
            abort(403, 'Unauthorized action.');
        }

        foreach (explode('|', $permission) as $candidate) {
            if ($candidate === '') {
                continue;
            }

            try {
                if ($user->hasPermissionTo($candidate)) {
                    return $next($request);
                }
            } catch (PermissionDoesNotExist) {
                // A permission the seeder has not created must deny access, not
                // surface a 500. Spatie throws here; an unguarded screen is a
                // worse outcome than a refused one, so fail closed and record
                // it so the gap is visible in the logs.
                logger()->error('Route guarded by an unknown permission', [
                    'permission' => $candidate,
                    'route' => $request->route()?->uri(),
                ]);
            }
        }

        abort(403, 'Unauthorized action.');
    }
}
