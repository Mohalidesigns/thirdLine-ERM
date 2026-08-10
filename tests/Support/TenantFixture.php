<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Builds a minimal, schema-valid row in any table for a given organization.
 *
 * The tenancy tests need one row per tenant-scoped table per organization, and
 * hand-written fixtures for ~35 tables would rot the moment a NOT NULL column
 * is added. This walks the live schema instead: it fills every non-nullable
 * column with a type-appropriate value and resolves non-nullable foreign keys
 * by recursively creating the parent row in the same organization.
 *
 * The rows are structurally valid, not semantically meaningful — which is
 * exactly what an isolation assertion needs.
 */
class TenantFixture
{
    /** @var array<string, array<int, int>> table => [organizationId => id] */
    private array $created = [];

    /** @var array<string, true> tables currently being built, for cycle detection */
    private array $building = [];

    private int $counter = 0;

    /** @var array<string, array<string, list<string>>> table => column => allowed enum values */
    private array $enumCache = [];

    /**
     * Create (or reuse) a row in $table belonging to $organizationId and return its id.
     */
    public function make(string $table, int $organizationId, array $overrides = []): int
    {
        if (isset($this->created[$table][$organizationId]) && $overrides === []) {
            return $this->created[$table][$organizationId];
        }

        if (isset($this->building[$table])) {
            throw new RuntimeException("Circular non-nullable foreign key involving [{$table}].");
        }

        $this->building[$table] = true;

        try {
            $row = $this->buildRow($table, $organizationId, $overrides);
            $id = DB::table($table)->insertGetId($row);
        } finally {
            unset($this->building[$table]);
        }

        if ($overrides === []) {
            $this->created[$table][$organizationId] = $id;
        }

        return $id;
    }

    private function buildRow(string $table, int $organizationId, array $overrides): array
    {
        $foreignKeys = $this->foreignKeyMap($table);
        $row = [];

        foreach (Schema::getColumns($table) as $column) {
            $name = $column['name'];

            if ($column['auto_increment']) {
                continue;
            }

            if (array_key_exists($name, $overrides)) {
                $row[$name] = $overrides[$name];

                continue;
            }

            if ($name === 'organization_id') {
                $row[$name] = $organizationId;

                continue;
            }

            // Nullable columns stay null: fewer moving parts, and NULL never
            // violates a unique index.
            if ($column['nullable']) {
                continue;
            }

            if ($column['default'] !== null && $column['default'] !== 'NULL') {
                continue;
            }

            if (isset($foreignKeys[$name])) {
                $row[$name] = $this->resolveForeignKey($foreignKeys[$name], $organizationId);

                continue;
            }

            $row[$name] = $this->valueFor($table, $column);
        }

        return $row;
    }

    private function resolveForeignKey(string $foreignTable, int $organizationId): int
    {
        if ($foreignTable === 'organizations') {
            return $organizationId;
        }

        return $this->make($foreignTable, $organizationId);
    }

    /** @return array<string, string> column => foreign table */
    private function foreignKeyMap(string $table): array
    {
        $map = [];

        foreach (Schema::getForeignKeys($table) as $fk) {
            if (count($fk['columns']) === 1) {
                $map[$fk['columns'][0]] = $fk['foreign_table'];
            }
        }

        return $map;
    }

    private function valueFor(string $table, array $column): mixed
    {
        $this->counter++;
        $n = $this->counter;
        $name = $column['name'];
        $type = strtolower($column['type_name']);
        $fullType = strtolower((string) $column['type']);

        if ($allowed = $this->enumValues($table, $name)) {
            return $allowed[0];
        }

        if (str_contains($fullType, 'enum(')) {
            preg_match_all("/'([^']*)'/", $fullType, $m);

            return $m[1][0] ?? 'x';
        }

        return match (true) {
            $name === 'uuid' || str_contains($fullType, 'char(36)') => (string) Str::uuid(),
            // MariaDB reports a JSON column as longtext, so the json arm below
            // never matches there and a plain string lands in a column with a
            // JSON check constraint. Match on the column name as well.
            str_ends_with($name, '_json') || in_array($name, self::JSON_COLUMNS, true) => '[]',
            in_array($type, ['tinyint', 'bool', 'boolean'], true) && str_contains($fullType, '(1)') => 0,
            in_array($type, ['int', 'integer', 'bigint', 'smallint', 'mediumint', 'tinyint'], true) => 1,
            in_array($type, ['decimal', 'numeric', 'float', 'double', 'real'], true) => 1,
            in_array($type, ['date'], true) => now()->toDateString(),
            in_array($type, ['datetime', 'timestamp'], true) => now()->toDateTimeString(),
            in_array($type, ['time'], true) => '00:00:00',
            in_array($type, ['json', 'jsonb'], true) => '[]',
            in_array($type, ['text', 'longtext', 'mediumtext'], true) => "fixture-{$n}",
            // varchar and friends: keep it short, some columns are char(3)/(2).
            default => $this->boundedString($fullType, $n),
        };
    }

    /**
     * Columns that hold JSON but are not reported as a json type by every
     * driver. Keep this list short — it exists only for MariaDB's longtext.
     *
     * @var list<string>
     */
    private const JSON_COLUMNS = [
        'stages', 'metadata', 'tags', 'settings', 'payload', 'evidence_refs',
        'confidence_levels', 'scenario_ids', 'stress_config', 'regulatory_mapping',
        'contributory_factors', 'expected_risk_reduction', 'actual_risk_reduction',
        'percentile_distribution', 'risk_contributions', 'assessment_criteria',
    ];

    private function boundedString(string $fullType, int $n): string
    {
        preg_match('/\((\d+)\)/', $fullType, $m);
        $length = isset($m[1]) ? (int) $m[1] : 255;
        $value = "fx{$n}";

        return strlen($value) <= $length ? $value : substr((string) $n, -$length);
    }

    /**
     * SQLite renders enum columns as `varchar check ("col" in ('a','b'))`, and
     * getColumns() reports only "varchar" — so the allowed values have to come
     * out of the stored DDL or the insert trips the check constraint.
     *
     * @return list<string>
     */
    private function enumValues(string $table, string $column): array
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return [];
        }

        if (! isset($this->enumCache[$table])) {
            $this->enumCache[$table] = [];

            $ddl = (string) DB::table('sqlite_master')
                ->where('type', 'table')
                ->where('name', $table)
                ->value('sql');

            if (preg_match_all('/"([a-z_0-9]+)"\s+in\s+\(([^)]*)\)/i', $ddl, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    preg_match_all("/'([^']*)'/", $match[2], $values);
                    $this->enumCache[$table][$match[1]] = $values[1];
                }
            }
        }

        return $this->enumCache[$table][$column] ?? [];
    }
}
