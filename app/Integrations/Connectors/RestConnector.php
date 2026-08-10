<?php

namespace App\Integrations\Connectors;

use App\Integrations\Contracts\Connector;
use App\Models\Connector as ConnectorModel;
use App\Support\Http\OutboundUrlGuard;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * WP-07 TASK 4 — reference connector two: a JSON endpoint.
 *
 * Reads a REST endpoint and pulls rows out of the response with a JSONPath-ish
 * pointer, because no two systems agree on where the array lives — some return
 * it at the root, most wrap it in `data`, and a few in `result.items`.
 *
 * PAGINATION IS BOUNDED. `max_pages` exists because a misconfigured cursor is
 * an infinite loop against somebody else's server, and the failure mode is a
 * job that runs until the queue times out while hammering a system this
 * platform does not own.
 */
class RestConnector implements Connector
{
    public function describe(): array
    {
        return [
            'type' => 'rest',
            'label' => 'REST / JSON endpoint',
            'description' => 'Reads a JSON endpoint, optionally paginated, and extracts records with a path expression.',
            'config' => [
                'url' => ['label' => 'Endpoint URL', 'type' => 'text', 'required' => true],
                'method' => ['label' => 'Method', 'type' => 'select', 'options' => ['GET' => 'GET', 'POST' => 'POST']],
                'records_path' => [
                    'label' => 'Path to the records',
                    'type' => 'text',
                    'help' => 'Dot path to the array in the response, e.g. data or result.items. Leave empty if the response IS the array.',
                ],
                'since_param' => [
                    'label' => 'Incremental parameter',
                    'type' => 'text',
                    'help' => 'Query parameter for "changed since", e.g. updated_after. Leave empty to read everything each time.',
                ],
                'page_param' => ['label' => 'Page parameter', 'type' => 'text'],
                'max_pages' => ['label' => 'Maximum pages', 'type' => 'number', 'help' => 'Defaults to 20.'],
                'headers' => ['label' => 'Extra headers (JSON object)', 'type' => 'textarea'],
            ],
            'credentials' => [
                'auth_type' => ['label' => 'Authentication', 'type' => 'select', 'options' => [
                    'none' => 'None', 'bearer' => 'Bearer token', 'basic' => 'Basic', 'header' => 'Custom header',
                ]],
                'token' => ['label' => 'Token or password', 'type' => 'password'],
                'username' => ['label' => 'Username', 'type' => 'text'],
                'header_name' => ['label' => 'Header name', 'type' => 'text'],
            ],
        ];
    }

    public function authenticate(ConnectorModel $connector): void
    {
        // Applied per request in client(); there is no session to establish.
    }

    public function testConnection(ConnectorModel $connector): array
    {
        try {
            $response = $this->request($connector, 1, null);

            $records = $this->extract($connector, $response);

            return [
                'ok' => true,
                'message' => $records === []
                    ? 'The endpoint answered, but no records were found at the configured path.'
                    : 'Reached the endpoint and found '.count($records).' record(s) on the first page.',
                'detail' => [
                    'first_record_keys' => $records === [] ? [] : array_keys((array) $records[0]),
                ],
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function pull(ConnectorModel $connector, ?CarbonInterface $since = null): iterable
    {
        $maxPages = max(1, (int) ($connector->config('max_pages') ?: 20));
        $pageParam = $connector->config('page_param');

        for ($page = 1; $page <= $maxPages; $page++) {
            $records = $this->extract($connector, $this->request($connector, $page, $since));

            if ($records === []) {
                return;
            }

            foreach ($records as $record) {
                yield (array) $record;
            }

            // Without a page parameter there is one page by definition;
            // requesting the same URL twenty times would just read it twenty
            // times.
            if (blank($pageParam)) {
                return;
            }
        }
    }

    public function push(ConnectorModel $connector, array $payload): array
    {
        try {
            $url = (string) $connector->config('url');
            OutboundUrlGuard::assertSafe($url);

            $response = $this->client($connector)->post($url, $payload);

            return ['ok' => $response->successful(), 'message' => 'The endpoint answered '.$response->status().'.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function fieldMap(ConnectorModel $connector): array
    {
        $records = $this->extract($connector, $this->request($connector, 1, null));

        if ($records === []) {
            return [];
        }

        return collect(array_keys((array) $records[0]))
            ->mapWithKeys(fn (string $key) => [$key => $key])
            ->all();
    }

    /* ------------------------------------------------------------------ */

    private function request(ConnectorModel $connector, int $page, ?CarbonInterface $since): array
    {
        $url = (string) $connector->config('url');

        // The same guard a webhook uses. A connector URL is equally
        // user-supplied and equally fetched by the server.
        OutboundUrlGuard::assertSafe($url);

        $query = [];

        if ($param = $connector->config('page_param')) {
            $query[$param] = $page;
        }

        if ($since !== null && ($param = $connector->config('since_param'))) {
            $query[$param] = $since->toIso8601String();
        }

        $method = strtoupper((string) ($connector->config('method') ?: 'GET'));

        $response = $method === 'POST'
            ? $this->client($connector)->post($url, $query)
            : $this->client($connector)->get($url, $query);

        if (! $response->successful()) {
            throw new RuntimeException("The endpoint answered {$response->status()}.");
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw new RuntimeException('The endpoint did not return JSON.');
        }

        return $decoded;
    }

    private function client(ConnectorModel $connector): \Illuminate\Http\Client\PendingRequest
    {
        $headers = json_decode((string) $connector->config('headers'), true);

        $client = Http::timeout(60)
            ->acceptJson()
            ->withHeaders(is_array($headers) ? $headers : []);

        return match ($connector->credential('auth_type')) {
            'bearer' => $client->withToken((string) $connector->credential('token')),
            'basic' => $client->withBasicAuth(
                (string) $connector->credential('username'),
                (string) $connector->credential('token'),
            ),
            'header' => $client->withHeaders([
                (string) $connector->credential('header_name') => (string) $connector->credential('token'),
            ]),
            default => $client,
        };
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<mixed>
     */
    private function extract(ConnectorModel $connector, array $response): array
    {
        $path = $connector->config('records_path');

        $records = blank($path) ? $response : data_get($response, $path);

        if (! is_array($records)) {
            return [];
        }

        // A single object at the path is one record, not zero. Systems that
        // return `{"data": {...}}` for a single result are common enough that
        // treating it as empty would look like "the sync found nothing".
        return array_is_list($records) ? $records : [$records];
    }
}
