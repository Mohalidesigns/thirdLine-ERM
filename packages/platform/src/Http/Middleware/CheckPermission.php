<?php

namespace ThirdLine\Platform\Http\Middleware;

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
    /**
     * The role that may do everything.
     *
     * Hardcoded, as it already is in HandleInertiaRequests, and matching the
     * `Gate::before` the consuming application registers.
     */
    private const SUPER_ROLE = 'super-admin';

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = Auth::user();

        if (! $user) {
            abort(403, 'Unauthorized action.');
        }

        // A SUPER-ADMIN PASSES WITHOUT CONSULTING THE GRANT TABLE.
        //
        // This middleware used to read `hasPermissionTo()` for everybody, which
        // asks the database directly and so misses the `Gate::before` bypass
        // the application registers for this role. The product then answered
        // one question three different ways: `can()` said yes,
        // HandleInertiaRequests put EVERY permission into the page props — so
        // the sidebar rendered the link — and this middleware said no. The
        // sidebar promised what the route refused, with "Unauthorized action."
        //
        // It surfaced when a new module added permissions: the seeder gives
        // super-admin the whole catalog, but a seeder never re-runs on a
        // deployed tenant, so its grant table silently fell behind by exactly
        // the new permissions and every new screen 403'd for the one role that
        // is supposed to see everything. Trusting the role rather than the
        // grant table is what HandleInertiaRequests already does, and its
        // docblock says why: a role can be created by hand.
        if (method_exists($user, 'hasRole') && $user->hasRole(self::SUPER_ROLE)) {
            return $next($request);
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
