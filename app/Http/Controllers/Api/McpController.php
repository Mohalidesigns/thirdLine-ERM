<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Services\Mcp\McpToolRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * WP-07 TASK 5 — the MCP endpoint.
 *
 * JSON-RPC 2.0 over HTTP, which is what the Streamable HTTP transport speaks.
 * Three methods matter: `initialize`, `tools/list` and `tools/call`.
 *
 * AUTHENTICATION IS THE SAME BEARER TOKEN AS THE REST API, deliberately. An
 * agent gets exactly the rights of the person who issued its token — no more,
 * and no separate credential to audit. If a CRO's agent can read the loss
 * register it is because the CRO can; if an analyst's cannot, it is because
 * the analyst cannot.
 *
 * ERRORS ARE JSON-RPC ERRORS, not HTTP ones. A 403 with an HTML body is
 * something an agent cannot act on; `{"error": {"code": -32003, "message":
 * "This token may not risk.view"}}` is something it can explain to its user.
 */
class McpController extends Controller
{
    private const PROTOCOL_VERSION = '2025-06-18';

    public function __construct(private McpToolRegistry $tools) {}

    public function handle(Request $request): JsonResponse
    {
        $id = $request->input('id');
        $method = (string) $request->input('method');

        /** @var ApiToken $token */
        $token = $request->attributes->get('api_token');

        try {
            $result = match ($method) {
                'initialize' => $this->initialize(),
                'notifications/initialized' => null,
                'ping' => new \stdClass,
                'tools/list' => ['tools' => $this->tools->manifest()],
                'tools/call' => $this->callTool($request, $token),
                default => throw new \BadMethodCallException("Unsupported method [{$method}]."),
            };

            // A notification (no id) gets no response body, per JSON-RPC.
            if ($id === null) {
                return response()->json(null, 202);
            }

            return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
        } catch (\BadMethodCallException $e) {
            return $this->error($id, -32601, $e->getMessage());
        } catch (Throwable $e) {
            // -32003 is the convention for "not allowed"; the message says
            // which permission, because an agent that knows why it was refused
            // can tell its user what to ask for.
            return $this->error($id, -32003, $e->getMessage());
        }
    }

    /* ------------------------------------------------------------------ */

    private function initialize(): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => [
                'name' => 'atheris-erm',
                'version' => (string) config('scramble.info.version', '1.0.0'),
            ],
            'instructions' => <<<'TEXT'
            This server reads an Enterprise Risk Management register. Everything
            it returns includes `citations` — object ids in `type:id` form. Cite
            them. A figure from this platform without its source is exactly what
            the platform exists to stop.

            All tools are read-only, and every one runs with the permissions of
            the user whose token you are using. A refusal names the permission
            that is missing; report it rather than working around it.

            `get_measure_series` returns null for periods with no reading. A run
            of nulls means the indicator stopped being collected — which is a
            finding, not a flat line.
            TEXT,
        ];
    }

    private function callTool(Request $request, ApiToken $token): array
    {
        $name = (string) $request->input('params.name');
        $arguments = (array) $request->input('params.arguments', []);

        $result = $this->tools->call($name, $arguments, $token);

        return [
            // MCP wants content blocks. The structured copy is what an agent
            // should actually read; the text block is the fallback for clients
            // that only render text.
            'content' => [[
                'type' => 'text',
                'text' => json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ]],
            'structuredContent' => $result,
            'isError' => false,
        ];
    }

    private function error(mixed $id, int $code, string $message): JsonResponse
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ]);
    }
}
