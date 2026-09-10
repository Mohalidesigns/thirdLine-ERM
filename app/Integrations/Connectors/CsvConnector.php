<?php

namespace App\Integrations\Connectors;

use App\Integrations\Contracts\Connector;
use App\Models\Connector as ConnectorModel;
use App\Support\Http\OutboundUrlGuard;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * WP-07 TASK 4 — reference connector one: a delimited file.
 *
 * The one every institution actually has. A core banking system that exposes no
 * API at all will still drop a CSV on an SFTP share at 2am, and that file is
 * how most KRI values reach a risk platform in practice.
 *
 * SOURCES: a local/disk path, or an http(s) URL. SFTP is configured through
 * Laravel's filesystem disks rather than implemented here — the credentials,
 * host key handling and connection pooling all belong to the disk driver, and
 * reimplementing them inside a connector is how you end up with a second, worse
 * SFTP client.
 */
class CsvConnector implements Connector
{
    public function describe(): array
    {
        return [
            'type' => 'csv',
            'label' => 'CSV / delimited file',
            'description' => 'Reads a delimited file from a configured disk (including SFTP) or an https URL. '
                .'The most common way a core banking system publishes indicator values.',
            'config' => [
                'source' => [
                    'label' => 'Source',
                    'type' => 'select',
                    'options' => ['disk' => 'A configured disk (local, S3, SFTP)', 'url' => 'An https URL'],
                    'required' => true,
                ],
                'disk' => [
                    'label' => 'Disk name',
                    'type' => 'text',
                    'help' => 'The filesystem disk from config/filesystems.php. Configure SFTP there.',
                ],
                'path' => [
                    'label' => 'File path',
                    'type' => 'text',
                    'help' => 'Supports strftime tokens, e.g. kri/%Y-%m-%d.csv for a daily drop.',
                    'required' => true,
                ],
                'delimiter' => ['label' => 'Delimiter', 'type' => 'text', 'help' => 'Defaults to a comma.'],
                'has_header' => ['label' => 'First row is a header', 'type' => 'boolean'],
            ],
            'credentials' => [
                'url_token' => ['label' => 'Bearer token', 'type' => 'password'],
            ],
        ];
    }

    public function authenticate(ConnectorModel $connector): void
    {
        // Disk credentials belong to the disk; a URL token is applied per
        // request in pull(). Nothing to prepare.
    }

    public function testConnection(ConnectorModel $connector): array
    {
        try {
            $rows = 0;

            foreach ($this->read($connector) as $row) {
                $rows++;

                if ($rows >= 5) {
                    break;
                }
            }

            return [
                'ok' => true,
                'message' => $rows === 0
                    // Reachable but empty is worth saying plainly: it is the
                    // state that looks like success and imports nothing.
                    ? 'The file was reached but contains no data rows.'
                    : "Reached the file and read {$rows} row(s).",
                'detail' => ['sample_rows' => $rows],
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function pull(ConnectorModel $connector, ?CarbonInterface $since = null): iterable
    {
        // A CSV drop has no notion of "changed since"; the framework
        // deduplicates on write, so returning everything is correct rather than
        // merely convenient.
        return $this->read($connector);
    }

    public function push(ConnectorModel $connector, array $payload): array
    {
        return ['ok' => false, 'message' => 'The CSV connector reads only.'];
    }

    public function fieldMap(ConnectorModel $connector): array
    {
        foreach ($this->read($connector) as $row) {
            return collect(array_keys($row))->mapWithKeys(fn (string $key) => [$key => $key])->all();
        }

        return [];
    }

    /* ------------------------------------------------------------------ */

    /**
     * @return iterable<int, array<string, mixed>>
     */
    private function read(ConnectorModel $connector): iterable
    {
        $contents = $this->contents($connector);

        $delimiter = $connector->config('delimiter') ?: ',';
        $hasHeader = $connector->config('has_header', true);

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $contents);
        rewind($handle);

        $header = null;
        $index = 0;

        while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            // A wholly empty line is not a record. Left in, it becomes a row of
            // nulls that fails validation and inflates the error count.
            if ($row === [null] || $row === []) {
                continue;
            }

            if ($hasHeader && $header === null) {
                // The BOM Excel writes would otherwise become part of the first
                // column's name, and no field mapping would ever match it.
                $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $row[0]);
                $header = array_map(fn ($h) => trim((string) $h), $row);

                continue;
            }

            yield $index++ => $header === null
                ? $row
                : array_combine(
                    $header,
                    array_pad(array_slice($row, 0, count($header)), count($header), null),
                );
        }

        fclose($handle);
    }

    private function contents(ConnectorModel $connector): string
    {
        // strftime-style tokens, so a daily drop is one configuration rather
        // than one connector per day.
        $path = date_format(now()->toDateTime(), 'Y-m-d') !== null
            ? strtr((string) $connector->config('path'), [
                '%Y' => now()->format('Y'),
                '%m' => now()->format('m'),
                '%d' => now()->format('d'),
            ])
            : (string) $connector->config('path');

        if ($connector->config('source') === 'url') {
            // Same guard as a webhook, and for the same reason: this is an
            // outbound request to an address somebody typed in.
            OutboundUrlGuard::assertSafe($path);

            $response = Http::timeout(30)
                ->when($connector->credential('url_token'), fn ($http, $token) => $http->withToken($token))
                ->get($path);

            if (! $response->successful()) {
                throw new RuntimeException("The source answered {$response->status()}.");
            }

            return $response->body();
        }

        $disk = $connector->config('disk') ?: 'local';

        if (! Storage::disk($disk)->exists($path)) {
            throw new RuntimeException("No file at [{$path}] on the [{$disk}] disk.");
        }

        return (string) Storage::disk($disk)->get($path);
    }
}
