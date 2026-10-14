<?php

namespace App\Services\Configuration;

use App\Models\ConfigBundle;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * WP-05 TASK 4 — turn an organisation's configuration into a portable artefact.
 *
 * Everything an id points at is exported as the natural key of what it points
 * at, so a bundle means the same thing in an environment whose auto-increments
 * ran in a different order. That is the difference between a configuration
 * bundle and a database dump with extra steps.
 */
class ConfigurationExporter
{
    /** Domain tag of the v2 checksum recipe (ADR 0026 section 2). */
    public const CHECKSUM_TAG_V2 = 'config_bundles/v2';

    /**
     * Build the payload without persisting it. Used by the diff and the
     * snapshot path as well as by export proper.
     *
     * @return array<string, mixed>
     */
    public function payload(?int $organizationId = null): array
    {
        $organizationId ??= TenantContext::organizationId();

        $sections = [];

        foreach (ConfigurationSchema::sections() as $name => $definition) {
            $sections[$name] = $this->exportSection($name, $definition, $organizationId);
        }

        foreach (ConfigurationSchema::reservedSections() as $name) {
            // Declared empty rather than omitted — see the schema docblock.
            $sections[$name] = [];
        }

        return [
            'format_version' => 2,
            'exported_from' => config('app.env'),
            'sections' => $sections,
        ];
    }

    /**
     * Export and persist a bundle.
     */
    public function export(
        string $code,
        string $name,
        ?string $description = null,
        ?int $organizationId = null,
        bool $isSnapshot = false,
    ): ConfigBundle {
        $organizationId ??= TenantContext::organizationId();
        $payload = $this->payload($organizationId);

        $version = 1 + (int) ConfigBundle::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('code', $code)
            ->max('version');

        return ConfigBundle::create([
            'organization_id' => $organizationId,
            'code' => $code,
            'name' => $name,
            'version' => $version,
            'description' => $description,
            'payload' => $payload,
            'checksum' => self::checksum($payload),
            'exported_at' => now(),
            'exported_by' => auth()->id(),
            'source_environment' => config('app.env'),
            'is_snapshot' => $isSnapshot,
        ]);
    }

    /**
     * The bundle as the file an operator carries to another environment.
     *
     * The one writer for the screen's download and `config:export --file`.
     * JSON_PRESERVE_ZERO_FRACTION is load-bearing: without it a float `100.0`
     * in the payload is written as `100` and read back as an int, and a format
     * 2 bundle then reads as altered. `serialize_precision` is pinned to -1 for
     * the call so the file does not depend on the php.ini it was written under.
     * ADR 0026 section 3.
     */
    public function document(ConfigBundle $bundle): string
    {
        $previous = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');

        try {
            return json_encode([
                'code' => $bundle->code,
                'name' => $bundle->name,
                'version' => $bundle->version,
                'checksum' => $bundle->checksum,
                'exported_at' => $bundle->exported_at?->toIso8601String(),
                'source_environment' => $bundle->source_environment,
                'payload' => $bundle->payload,
            ], JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR);
        } finally {
            ini_set('serialize_precision', $previous === false ? '-1' : $previous);
        }
    }

    /**
     * sha256 over the payload, by the recipe its `format_version` names.
     *
     * The recipe is chosen by a field the digest covers, never by trial
     * (ADR 0026 section 2): absent or 1 is the frozen v1 recipe that every
     * bundle exported before the ADR was sealed under; 2 is the tagged
     * canonical-text recipe; anything else is a newer release's and is refused
     * rather than guessed at.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws \InvalidArgumentException for a format this release does not read
     */
    public static function checksum(array $payload): string
    {
        $format = (int) ($payload['format_version'] ?? 1);

        return match ($format) {
            1 => self::checksumV1($payload),
            2 => hash('sha256', self::CHECKSUM_TAG_V2.'|'.CanonicalJson::encode($payload)),
            default => throw new \InvalidArgumentException(
                "This bundle was exported by a newer release (format {$format}); this release reads formats 1 and 2."
            ),
        };
    }

    /**
     * FROZEN. Legacy bundles are verified under this exact recipe; changing a
     * byte of it makes every bundle exported before ADR 0026 read as altered.
     * Pinned by BundleChecksumRecipeTest. ADR 0026 section 2.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function checksumV1(array $payload): string
    {
        return hash('sha256', json_encode(self::canonicalise($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Recursively key-sorted, lists kept in order. Serves the v1 checksum
     * recipe (frozen) and ConfigurationDiffer::equivalent(). It is not the v2
     * canonical form: `ksort` here is the default `SORT_REGULAR`, which is not
     * transitive over mixed int and string keys. `CanonicalJson` is.
     *
     * @param  mixed  $value
     * @return mixed
     */
    public static function canonicalise($value)
    {
        if (! is_array($value)) {
            return $value;
        }

        $canonical = array_map([self::class, 'canonicalise'], $value);

        // Only associative arrays are sorted. Sorting a list would reorder a
        // lifecycle's states, which is meaningful.
        if (! array_is_list($canonical)) {
            ksort($canonical);
        }

        return $canonical;
    }

    /* ------------------------------------------------------------------ */
    /*  Internals */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $definition
     * @return list<array<string, mixed>>
     */
    private function exportSection(string $section, array $definition, int $organizationId): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable($definition['table'])) {
            return [];
        }

        $query = DB::table($definition['table']);

        if ($definition['tenantScoped']) {
            // System rows (organization_id NULL) are the platform's, not the
            // tenant's. Exporting them would make every bundle claim ownership
            // of the seeded registry, and importing it elsewhere would fork it.
            $query->where('organization_id', $organizationId);
        } else {
            // Sections keyed off a scoped parent inherit its scope.
            $query = $this->scopeByParent($query, $definition, $organizationId);
        }

        if (\Illuminate\Support\Facades\Schema::hasColumn($definition['table'], 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        $rows = [];

        foreach ($query->get() as $record) {
            $record = (array) $record;

            // A system row belongs to the platform's own seed, which every
            // environment already has. Exporting it would produce a bundle
            // whose import forks the shared registry into a tenant copy.
            if ($definition['systemFlag'] !== null && ! empty($record[$definition['systemFlag']])) {
                continue;
            }

            $rows[] = $this->exportRow($section, $definition, $record);
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function scopeByParent($query, array $definition, int $organizationId)
    {
        foreach ($definition['references'] as $column => $reference) {
            $parent = ConfigurationSchema::sections()[$reference['section']] ?? null;

            if ($parent === null || ! $parent['tenantScoped']) {
                continue;
            }

            $query->whereIn($column, DB::table($parent['table'])
                ->where('organization_id', $organizationId)
                ->select('id'));

            break;
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function exportRow(string $section, array $definition, array $record): array
    {
        $row = [];

        foreach ($definition['columns'] as $column) {
            if (! array_key_exists($column, $record)) {
                continue;
            }

            $value = $record[$column];

            if (in_array($column, $definition['json'], true) && is_string($value)) {
                $value = json_decode($value, true);
            }

            $row[$column] = $value;
        }

        // Foreign keys become the natural key of what they point at.
        $refs = [];

        foreach ($definition['references'] as $column => $reference) {
            $refs[$column] = $record[$column] === null
                ? null
                : $this->naturalKeyOf($reference['section'], (int) $record[$column]);
        }

        if ($refs !== []) {
            $row['_refs'] = $refs;
        }

        // The two JSON arrays of object type ids that no per-column map can
        // express. Translated explicitly rather than left as ids that mean
        // nothing in the destination.
        if ($section === 'object_relationship_types') {
            foreach (['from_type_ids', 'to_type_ids'] as $column) {
                $ids = $record[$column] ?? null;
                $ids = is_string($ids) ? json_decode($ids, true) : $ids;

                $row[$column] = $ids === null ? null : array_values(array_filter(array_map(
                    fn ($id) => $this->naturalKeyOf('object_types', (int) $id),
                    (array) $ids
                )));
            }
        }

        return $row;
    }

    /** @var array<string, array<int, string|null>> */
    private array $keyCache = [];

    /**
     * The natural key of a row in another section, given its id here.
     */
    private function naturalKeyOf(string $section, int $id): ?string
    {
        if (isset($this->keyCache[$section][$id])) {
            return $this->keyCache[$section][$id];
        }

        $definition = ConfigurationSchema::sections()[$section] ?? null;

        if ($definition === null) {
            return null;
        }

        $record = DB::table($definition['table'])->where('id', $id)->first();

        if ($record === null) {
            return null;
        }

        $record = (array) $record;
        $parts = [];

        foreach ($definition['key'] as $column) {
            // A composite key whose first part is itself a foreign key —
            // object_attributes is keyed on (object_type_id, code) — resolves
            // that part recursively, so the whole key is environment-free.
            $reference = $definition['references'][$column] ?? null;

            $parts[] = $reference !== null && $record[$column] !== null
                ? (string) $this->naturalKeyOf($reference['section'], (int) $record[$column])
                : (string) ($record[$column] ?? '');
        }

        return $this->keyCache[$section][$id] = implode('::', $parts);
    }
}
