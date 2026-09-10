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
                return $this->deny(sprintf('This token may not %s. %s', $scope, $this->reasonFor($token, $scope)), 403);
            }
        }

        return $next($request);
    }

    /**
     * Why this token was refused, in terms the caller can act on.
     *
     * PREVIOUS BEHAVIOUR: the reason was chosen with
     * `$token->can($scope) || $token->can('*')`. Sanctum's `can()` returns true
     * for ANY ability when the token holds `*`, so a legacy machine token
     * carrying `*` — which `permits()` now deliberately refuses to honour,
     * because a client_credentials token has no user behind it to narrow `*`
     * down to — was told "its scopes allow it, but the user it belongs to does
     * not hold that permission". A machine token has no user, so that sentence
     * pointed its owner at a thing that does not exist.
     *
     * The three cases are now separated, and the legacy-wildcard one names the
     * actual remedy: re-issue the token with explicit scopes.
     */
    private function reasonFor(ApiToken $token, string $scope): string
    {
        $abilities = (array) ($token->abilities ?? []);
        $isMachine = $token->token_type === ApiToken::TYPE_CLIENT;

        if ($isMachine && in_array('*', $abilities, true)) {
            return 'This machine token holds the legacy "*" scope, which is no longer honoured — '
                .'a token that acts as nobody has no user permission to narrow "*" against. '
                .'Re-issue it with explicit scopes, including '.$scope.'.';
        }

        if (in_array('*', $abilities, true) || in_array($scope, $abilities, true)) {
            return 'Its scopes allow it, but the user it belongs to does not hold that permission.';
        }

        return 'Add the '.$scope.' scope to the token.';
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
