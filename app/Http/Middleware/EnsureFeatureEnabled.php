<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a route behind a flag in config/features.php.
 *
 * Usage: ->middleware('feature:ai_intelligence')
 *
 * A disabled feature 404s rather than 403s. A 403 tells the caller the surface
 * exists and they are merely not allowed in — which for a half-built feature is
 * information we do not want to leak, and which invites support tickets asking
 * for the permission. 404 is the honest answer: in this environment, there is
 * nothing here.
 */
class EnsureFeatureEnabled
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        // Deliberately a loose cast rather than config()->boolean(): that helper
        // throws when the value is not already a bool, and an operator who sets
        // FEATURE_AI_INTELLIGENCE=1 should get a working flag, not a 500.
        if (! filter_var(config("features.{$feature}", false), FILTER_VALIDATE_BOOLEAN)) {
            abort(404);
        }

        return $next($request);
    }
}
