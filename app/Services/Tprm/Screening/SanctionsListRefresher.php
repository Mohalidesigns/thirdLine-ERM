<?php

namespace App\Services\Tprm\Screening;

use App\Models\Tprm\SanctionsEntry;
use App\Models\Tprm\SanctionsList;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Refreshing the locally held sanctions lists.
 *
 * NO LIST DATA SHIPS WITH THE PRODUCT, and that is deliberate rather than
 * unfinished. Designations change weekly; a list frozen at build time would be
 * wrong the day after release and, worse, would look authoritative. Both
 * built-in lists ship EMPTY with a refresh command, and `LocalListDriver`
 * refuses to report "clear" against an empty list — so an installation that
 * has never refreshed cannot mistake silence for a clean result.
 *
 * WE ALSO DO NOT INVENT DESIGNATIONS. Seeding a plausible-looking sanctions
 * entry so a demo has something to find would put fabricated names into an AML
 * control, and somebody would eventually treat one as real.
 *
 * THE PARSERS ARE DEFENSIVE BECAUSE THE SOURCES ARE FILES. The UN publishes
 * XML whose shape has changed more than once; NigSAC publishes a spreadsheet
 * export. A refresh that half-succeeds must leave the previous list intact,
 * which is why the replace happens inside a transaction after the parse rather
 * than as it goes.
 */
class SanctionsListRefresher
{
    /**
     * Refresh one list from its configured source.
     *
     * @return array{refreshed: bool, entries: int, reason: string|null}
     */
    public function refresh(SanctionsList $list): array
    {
        $url = $list->source_url ?: (string) config('tprm.screening.sources.'.$list->code);

        if ($url === '') {
            return $this->fail($list, sprintf(
                'No source URL is configured for %s. Set tprm.screening.sources.%s, or upload the list file '
                .'through the screening settings screen.',
                $list->name,
                $list->code,
            ));
        }

        try {
            $response = Http::timeout(60)->get($url);

            if (! $response->successful()) {
                return $this->fail($list, sprintf('%s returned HTTP %d.', $url, $response->status()));
            }

            $entries = $this->parse($list->code, $response->body());
        } catch (\Throwable $exception) {
            return $this->fail($list, $exception->getMessage());
        }

        if ($entries === []) {
            // A parse that found nothing is a parse that failed, not a list
            // with no designations on it. Replacing a working list with an
            // empty one because a publisher changed their schema is the
            // failure this branch exists to prevent.
            return $this->fail($list, sprintf(
                'The file at %s parsed to no entries. The previous list has been left in place rather than '
                .'replaced with an empty one — a publisher changing their format must not silently empty a '
                .'sanctions list.',
                $url,
            ));
        }

        return $this->store($list, $entries);
    }

    /**
     * Replace a list's entries with a freshly parsed set.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return array{refreshed: bool, entries: int, reason: string|null}
     */
    public function store(SanctionsList $list, array $entries): array
    {
        DB::transaction(function () use ($list, $entries) {
            // Replace rather than merge: a delisting is a change the register
            // has to reflect, and a merge would keep designations the
            // publisher has removed.
            SanctionsEntry::query()->where('list_id', $list->getKey())->delete();

            foreach (array_chunk($entries, 500) as $chunk) {
                $rows = [];

                foreach ($chunk as $entry) {
                    $name = (string) ($entry['name'] ?? '');

                    if (trim($name) === '') {
                        continue;
                    }

                    $rows[] = [
                        'list_id' => $list->getKey(),
                        'external_id' => (string) ($entry['external_id'] ?? md5($name)),
                        'name' => $name,
                        'normalised_name' => SanctionsEntry::normalise($name),
                        'aliases' => json_encode(array_values(array_filter((array) ($entry['aliases'] ?? [])))),
                        'entity_type' => $entry['entity_type'] ?? null,
                        'country' => $entry['country'] ?? null,
                        'date_of_birth' => $entry['date_of_birth'] ?? null,
                        'programme' => $entry['programme'] ?? null,
                        'listed_on' => $entry['listed_on'] ?? null,
                        'raw' => json_encode($entry['raw'] ?? $entry),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                if ($rows !== []) {
                    SanctionsEntry::query()->insert($rows);
                }
            }

            $list->forceFill([
                'entry_count' => SanctionsEntry::query()->where('list_id', $list->getKey())->count(),
                'last_refreshed_at' => now(),
                'last_refresh_status' => SanctionsList::STATUS_OK,
                'last_refresh_error' => null,
            ])->save();
        });

        return ['refreshed' => true, 'entries' => $list->fresh()->entry_count, 'reason' => null];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function parse(string $listCode, string $body): array
    {
        return match ($listCode) {
            SanctionsList::UNSCR => $this->parseUnscr($body),
            SanctionsList::NIGSAC => $this->parseDelimited($body),
            default => $this->parseDelimited($body),
        };
    }

    /**
     * The UN consolidated list's XML.
     *
     * Read defensively: the schema has changed more than once, and a parser
     * that assumed one shape would empty the list the day it changes again —
     * which is why an empty parse is treated as a failure above rather than as
     * a list with nothing on it.
     *
     * @return list<array<string, mixed>>
     */
    private function parseUnscr(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml);
        libxml_use_internal_errors($previous);

        if ($document === false) {
            return [];
        }

        $entries = [];

        foreach (['INDIVIDUALS/INDIVIDUAL', 'ENTITIES/ENTITY'] as $path) {
            foreach ($document->xpath($path) ?: [] as $node) {
                $name = trim(implode(' ', array_filter([
                    (string) ($node->FIRST_NAME ?? ''),
                    (string) ($node->SECOND_NAME ?? ''),
                    (string) ($node->THIRD_NAME ?? ''),
                    (string) ($node->FOURTH_NAME ?? ''),
                ])));

                if ($name === '') {
                    continue;
                }

                $aliases = [];

                foreach ($node->xpath('.//ALIAS_NAME') ?: [] as $alias) {
                    $value = trim((string) $alias);

                    if ($value !== '') {
                        $aliases[] = $value;
                    }
                }

                $entries[] = [
                    'external_id' => (string) ($node->DATAID ?? md5($name)),
                    'name' => $name,
                    'aliases' => $aliases,
                    'entity_type' => str_contains($path, 'INDIVIDUAL') ? 'individual' : 'entity',
                    'country' => trim((string) ($node->NATIONALITY->VALUE ?? '')) ?: null,
                    'programme' => trim((string) ($node->UN_LIST_TYPE ?? '')) ?: null,
                    'listed_on' => trim((string) ($node->LISTED_ON ?? '')) ?: null,
                ];
            }
        }

        return $entries;
    }

    /**
     * A delimited export — the shape NigSAC and most national lists publish.
     *
     * Column names are matched case-insensitively against a handful of
     * spellings, because these files are produced by hand and the header row
     * is never the same twice.
     *
     * @return list<array<string, mixed>>
     */
    private function parseDelimited(string $body): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($body)) ?: [];

        if (count($lines) < 2) {
            return [];
        }

        $header = array_map(
            fn (string $column) => strtolower(trim($column, " \t\"'")),
            str_getcsv(array_shift($lines)),
        );

        $index = fn (array $candidates) => (function () use ($header, $candidates) {
            foreach ($candidates as $candidate) {
                $position = array_search($candidate, $header, true);

                if ($position !== false) {
                    return $position;
                }
            }

            return null;
        })();

        $nameAt = $index(['name', 'full name', 'full_name', 'entity name', 'designated person']);

        if ($nameAt === null) {
            return [];
        }

        $idAt = $index(['id', 'reference', 'ref', 'listing id']);
        $aliasAt = $index(['aliases', 'alias', 'also known as', 'aka']);
        $typeAt = $index(['type', 'entity type', 'category']);
        $countryAt = $index(['country', 'nationality']);
        $dobAt = $index(['dob', 'date of birth', 'date_of_birth']);

        $entries = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $row = str_getcsv($line);
            $name = trim((string) ($row[$nameAt] ?? ''));

            if ($name === '') {
                continue;
            }

            $entries[] = [
                'external_id' => $idAt === null ? md5($name) : trim((string) ($row[$idAt] ?? md5($name))),
                'name' => $name,
                'aliases' => $aliasAt === null
                    ? []
                    : array_values(array_filter(array_map('trim', explode(';', (string) ($row[$aliasAt] ?? ''))))),
                'entity_type' => $typeAt === null ? null : (trim((string) ($row[$typeAt] ?? '')) ?: null),
                'country' => $countryAt === null ? null : (trim((string) ($row[$countryAt] ?? '')) ?: null),
                'date_of_birth' => $dobAt === null ? null : (trim((string) ($row[$dobAt] ?? '')) ?: null),
                'raw' => array_combine(array_slice($header, 0, count($row)), $row) ?: ['line' => $line],
            ];
        }

        return $entries;
    }

    /**
     * @return array{refreshed: bool, entries: int, reason: string|null}
     */
    private function fail(SanctionsList $list, string $reason): array
    {
        $list->forceFill([
            'last_refresh_status' => SanctionsList::STATUS_FAILED,
            'last_refresh_error' => $reason,
        ])->save();

        return ['refreshed' => false, 'entries' => $list->entry_count, 'reason' => $reason];
    }
}
