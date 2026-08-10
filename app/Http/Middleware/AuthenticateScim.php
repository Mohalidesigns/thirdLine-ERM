<?php

namespace App\Http\Middleware;

use App\Models\ScimToken;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token authentication for the SCIM 2.0 endpoints, and the thing that
 * binds the tenant for them.
 *
 * SCIM callers are directory services, not users: there is no session and no
 * User to resolve an organization from, so the token itself carries the
 * tenancy. Everything downstream then behaves exactly as it does for a web
 * request, including the BelongsToOrganization global scope.
 */
class AuthenticateScim
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('sso.scim.enabled')) {
            return $this->deny('SCIM provisioning is not enabled for this deployment.', 404);
        }

        $plaintext = $this->bearerToken($request);

        if ($plaintext === null) {
            return $this->deny('A bearer token is required.', 401);
        }

        // Look up by hash, not by comparing plaintext: the column is indexed
        // and the digest is fixed-length, so this is a single constant-time
        // equality on a value an attacker cannot grind down character by
        // character through timing.
        $token = ScimToken::query()
            ->withoutGlobalScopes()
            ->where('token_hash', ScimToken::hash($plaintext))
            ->first();

        if (! $token || $token->isExpired()) {
            return $this->deny('Invalid or expired token.', 401);
        }

        TenantContext::set((int) $token->organization_id);

        $token->forceFill(['last_used_at' => now()])->saveQuietly();

        $request->attributes->set('scim_token', $token);

        return $next($request);
    }

    private function bearerToken(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');

        if (! str_starts_with(strtolower($header), 'bearer ')) {
            return null;
        }

        $value = trim(substr($header, 7));

        return $value === '' ? null : $value;
    }

    private function deny(string $detail, int $status): Response
    {
        return response()->json([
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'],
            'detail' => $detail,
            'status' => (string) $status,
        ], $status, ['Content-Type' => 'application/scim+json']);
    }
}
