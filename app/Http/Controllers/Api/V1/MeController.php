<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Who am I, and what may this token do?
 *
 * The endpoint every integrator hits first, and the one that saves the support
 * ticket: a 403 elsewhere is explained here, because this says exactly which
 * scopes the token carries and which of them the user behind it actually holds.
 */
class MeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var ApiToken $token */
        $token = $request->attributes->get('api_token');
        $user = $token->actingUser();

        return response()->json([
            'data' => [
                'type' => 'identity',
                'id' => (string) $token->id,
                'attributes' => [
                    'token_name' => $token->name,
                    'token_type' => $token->token_type,
                    'organization_id' => $token->organization_id,
                    'organization' => $token->organization?->name,
                    'expires_at' => $token->expires_at?->toIso8601String(),
                    'rate_limit_per_minute' => $token->rate_limit_per_minute,
                    'user' => $user === null ? null : [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'roles' => $user->getRoleNames()->all(),
                    ],
                    'scopes' => $token->scopes(),
                    // The intersection, which is what actually applies. A token
                    // listing risk.delete that its owner cannot perform is the
                    // single most common cause of "the API says 403 but my
                    // token has the scope".
                    'effective' => collect($token->scopes())
                        ->reject(fn (string $scope) => $scope === '*')
                        ->filter(fn (string $scope) => $token->permits($scope))
                        ->values()
                        ->all(),
                ],
            ],
        ]);
    }
}
