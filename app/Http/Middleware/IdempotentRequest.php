<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * WP-07 TASK 2 — `Idempotency-Key` on POST and PUT.
 *
 * THE PROBLEM. A client posts a loss event, the connection drops before the
 * response arrives, and the client — correctly — retries. Without this, the
 * institution now has two loss events for one incident, both with a reference
 * code, both in the ORMS return. The client cannot tell, because it never saw
 * the first response.
 *
 * THE GUARANTEE. Same key, same body → the FIRST response, replayed, with
 * `Idempotency-Replayed: true`. Same key, DIFFERENT body → 422, because that is
 * a client bug and quietly replaying would hide it. Same key while the first is
 * still running → 409, because returning "success" for work that has not
 * finished is worse than asking them to wait.
 *
 * WHY A TABLE AND NOT A CACHE. The guarantee has to hold across web nodes and
 * across a cache flush, and it is needed exactly when the network is bad enough
 * for a client to be retrying — which is not the moment to depend on something
 * best-effort.
 */
class IdempotentRequest
{
    /** How long a key is honoured. Long enough for a retry, short enough to prune. */
    private const TTL_HOURS = 24;

    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header('Idempotency-Key'));

        if ($key === '' || ! in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
            return $next($request);
        }

        if (strlen($key) > 191) {
            return $this->error('The Idempotency-Key header may be at most 191 characters.', 400);
        }

        $organizationId = TenantContext::organizationIdOrNull();

        if ($organizationId === null) {
            return $next($request);
        }

        /** @var ApiToken|null $token */
        $token = $request->attributes->get('api_token');
        $hash = hash('sha256', $request->getContent().'|'.$request->method().'|'.$request->path());

        $existing = DB::table('api_idempotency_keys')
            ->where('organization_id', $organizationId)
            ->where('key', $key)
            ->first();

        if ($existing !== null) {
            if ($existing->request_hash !== $hash) {
                return $this->error(
                    'That Idempotency-Key was already used for a different request. '
                    .'Use a new key, or resend the original body.',
                    422,
                );
            }

            if ($existing->state === 'completed') {
                return $this->replay($existing);
            }

            // Still in flight, or abandoned. An abandoned one (the worker died
            // mid-request) is released after the TTL rather than blocking the
            // key forever.
            if ($existing->locked_at !== null && now()->diffInMinutes($existing->locked_at) < 15) {
                return $this->error('A request with that Idempotency-Key is still being processed.', 409);
            }

            DB::table('api_idempotency_keys')->where('id', $existing->id)->update([
                'locked_at' => now(),
                'state' => 'in_flight',
                'updated_at' => now(),
            ]);
        } else {
            try {
                DB::table('api_idempotency_keys')->insert([
                    'organization_id' => $organizationId,
                    'token_id' => $token?->id,
                    'key' => $key,
                    'method' => $request->method(),
                    'path' => $request->path(),
                    'request_hash' => $hash,
                    'state' => 'in_flight',
                    'locked_at' => now(),
                    'expires_at' => now()->addHours(self::TTL_HOURS),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                // Two retries arrived at once. The unique index is what makes
                // this safe; the loser waits rather than doing the work twice.
                return $this->error('A request with that Idempotency-Key is already in progress.', 409);
            }
        }

        $response = $next($request);

        // Only a successful write is worth replaying. Recording a 500 would
        // make a transient failure permanent for 24 hours — the client could
        // never retry it successfully with the same key.
        if ($response->getStatusCode() < 400) {
            DB::table('api_idempotency_keys')
                ->where('organization_id', $organizationId)
                ->where('key', $key)
                ->update([
                    'state' => 'completed',
                    'status_code' => $response->getStatusCode(),
                    'response_body' => $response->getContent(),
                    'response_headers' => json_encode(['Content-Type' => $response->headers->get('Content-Type')]),
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('api_idempotency_keys')
                ->where('organization_id', $organizationId)
                ->where('key', $key)
                ->delete();
        }

        return $response;
    }

    private function replay(object $record): Response
    {
        return response(
            $record->response_body,
            $record->status_code ?? 200,
            array_merge(
                (array) json_decode((string) $record->response_headers, true),
                [
                    // Say so. A client that cannot tell a replay from a fresh
                    // write cannot reconcile its own retry log.
                    'Idempotency-Replayed' => 'true',
                    'Idempotency-Key' => $record->key,
                ],
            ),
        );
    }

    private function error(string $detail, int $status): JsonResponse
    {
        return response()->json([
            'errors' => [[
                'status' => (string) $status,
                'title' => 'Idempotency conflict',
                'detail' => $detail,
            ]],
        ], $status);
    }
}
