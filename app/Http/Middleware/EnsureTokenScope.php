<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * WP-07 TASK 2 — the API's authorization guard.
 *
 * `scope:risk.view` on a route means: this token must carry that scope, AND the
 * user behind it must hold that permission. Both. See ApiToken::permits().
 *
 * It is a different middleware from `permission:` because it answers a
 * different question — `permission:` asks only about the logged-in user, and on
 * an API request there may not be one. Using `permission:` here would make a
 * machine token unauthorizable, and using `scope:` on a web route would make
 * every screen depend on a token nobody has. ApiAuthorizationTest asserts that
 * every /api/v1 route carries this one.
 */
class EnsureTokenScope
{
    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        /** @var ApiToken|null $token */
        $token = $request->attributes->get('api_token');

        if ($token === null) {
            // AuthenticateApiToken did not run. That is a routing mistake
            // rather than a client one, and failing closed is the only safe
            // reading of it.
            return $this->deny('This endpoint is not correctly configured for token authentication.', 500);
        }

        foreach ($scopes as $scope) {
            if (! $token->permits($scope)) {
                return $this->deny(sprintf(
                    'This token may not %s. %s',
                    $scope,
                    $token->can($scope) || $token->can('*')
                        ? 'Its scopes allow it, but the user it belongs to does not hold that permission.'
                        : 'Add the '.$scope.' scope to the token.',
                ), 403);
            }
        }

        return $next($request);
    }

    private function deny(string $message, int $status): JsonResponse
    {
        return response()->json([
            'errors' => [[
                'status' => (string) $status,
                'title' => 'Forbidden',
                'detail' => $message,
            ]],
        ], $status);
    }
}
