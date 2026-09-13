<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * WP-07 TASK 2 — the API's front door.
 *
 * Resolves the bearer token, refuses it if it is revoked or expired, binds the
 * tenant FROM THE TOKEN, and makes the acting user (if any) the authenticated
 * user for the rest of the request.
 *
 * THE TENANT COMES FROM THE TOKEN, NOT FROM A PARAMETER. There is no header,
 * query string or body field that changes which organization a request reads —
 * the only way to reach another tenant's data is to hold another tenant's
 * token. ResolveTenant (the web equivalent) reads it from the session user; the
 * API cannot, because a machine token has no user.
 *
 * WHY NOT JUST `auth:sanctum`. Stock Sanctum would authenticate the token and
 * stop there: it knows nothing about revocation (as distinct from deletion),
 * nothing about the organization, and nothing about a token whose owner has
 * since been deactivated — a departed employee's token would keep working until
 * somebody remembered it existed.
 */
class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $presented = $request->bearerToken();

        if (blank($presented)) {
            return $this->deny('No bearer token was presented.', 401);
        }

        /** @var ApiToken|null $token */
        $token = ApiToken::findToken($presented);

        if ($token === null) {
            return $this->deny('That token is not recognised.', 401);
        }

        if ($token->isRevoked()) {
            return $this->deny('That token has been revoked.', 401);
        }

        if ($token->isExpired()) {
            return $this->deny('That token expired on '.$token->expires_at->toIso8601String().'.', 401);
        }

        if ($token->organization_id === null) {
            // Pre-WP-07 tokens, or a row written by hand. Refused rather than
            // guessed: a token with no tenant cannot be scoped to one, and
            // defaulting to the owner's organization would make the column
            // decorative.
            return $this->deny('That token has no organization and cannot be used.', 403);
        }

        $user = $token->actingUser();

        if ($user !== null) {
            if (! $user->is_active) {
                // The case that matters: somebody leaves, their account is
                // deactivated, and their token keeps working because nobody
                // thought to revoke it separately.
                return $this->deny('The user this token belongs to is no longer active.', 403);
            }

            if ($user->organization_id !== $token->organization_id) {
                return $this->deny('This token does not match its owner\'s organization.', 403);
            }

            auth()->setUser($user);
        }

        TenantContext::set($token->organization_id);

        $request->attributes->set('api_token', $token);

        $token->forceFill([
            'last_used_at' => now(),
            'last_used_ip' => $request->ip(),
        ])->saveQuietly();

        return $next($request);
    }

    private function deny(string $message, int $status): JsonResponse
    {
        return response()->json([
            'errors' => [[
                'status' => (string) $status,
                'title' => $status === 401 ? 'Unauthenticated' : 'Forbidden',
                'detail' => $message,
            ]],
        ], $status);
    }
}
