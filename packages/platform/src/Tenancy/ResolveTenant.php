<?php

namespace ThirdLine\Platform\Tenancy;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the tenant for the request from the authenticated user.
 *
 * A user with no organization is a hard 403 rather than a silent fallback:
 * the whole point of the tenancy kernel is that there is no default tenant.
 * Unauthenticated requests pass through untouched — the auth middleware, not
 * this one, decides who may proceed.
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user) {
            TenantContext::clear();

            return $next($request);
        }

        if (empty($user->organization_id)) {
            abort(403, 'No organization assigned');
        }

        TenantContext::set((int) $user->organization_id);

        return $next($request);
    }
}
