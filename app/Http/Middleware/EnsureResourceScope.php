<?php

namespace App\Http\Middleware;

use App\Http\Api\ApiResourceRegistry;
use App\Models\ApiToken;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * WP-07 TASK 2 — the scope check for the generic resource routes.
 *
 * `scope:` takes a fixed permission, which the generic routes cannot use: the
 * permission they need depends on which resource the path names. /risks needs
 * risk.view, /loss-events needs loss_event.view, and a POST needs the .create
 * form of whichever it is.
 *
 * Same two-sided rule as EnsureTokenScope — the token's scope AND the
 * permission of the user behind it — and the same failure mode: closed.
 */
class EnsureResourceScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $resource = (string) $request->route('resource');
        $definition = ApiResourceRegistry::find($resource);

        if ($definition === null) {
            return $this->error(404, 'Not found', "There is no [{$resource}] resource. "
                .'GET /api/v1 lists everything this API serves.');
        }

        /** @var ApiToken|null $token */
        $token = $request->attributes->get('api_token');

        if ($token === null) {
            return $this->error(500, 'Misconfigured', 'This endpoint is not correctly configured for token authentication.');
        }

        $action = match ($request->method()) {
            'POST' => 'create',
            'PUT', 'PATCH' => 'edit',
            'DELETE' => 'delete',
            default => 'view',
        };

        $needed = $definition['permissions'][$action] ?? null;

        if ($needed === null) {
            return $this->error(405, 'Read only', "The {$resource} resource is read-only through the API. "
                .'It is produced by the platform rather than supplied to it.');
        }

        if (! $token->permits($needed)) {
            return $this->error(403, 'Forbidden', sprintf(
                'This token may not %s. %s',
                $needed,
                $token->can($needed) || $token->can('*')
                    ? 'Its scopes allow it, but the user it belongs to does not hold that permission.'
                    : 'Add the '.$needed.' scope to the token.',
            ));
        }

        return $next($request);
    }

    private function error(int $status, string $title, string $detail): JsonResponse
    {
        return response()->json([
            'errors' => [['status' => (string) $status, 'title' => $title, 'detail' => $detail]],
        ], $status);
    }
}
