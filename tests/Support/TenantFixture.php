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
            // never matches there and a plain string lands in a column carrying
            // a json_valid() CHECK constraint. ASK THE DATABASE which columns
            // those are rather than keeping a list by hand — the list drifted,
            // and five columns added since it was written (connectors.config,
            // webhook_subscriptions.events, scoring_profiles.likelihood_scale,
            // object_lifecycles.states, measure_thresholds.bands) produced 26
            // errors the moment the suite was pointed at a real MariaDB.
            str_ends_with($name, '_json') || $this->isJsonChecked($table, $name) => '[]',
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
     * Columns MariaDB guards with a `json_valid()` CHECK constraint.
     *
     * This replaces a hand-maintained allowlist of column names. The list was
     * correct when written and wrong by the time anybody ran the suite against
     * MariaDB, which is what a hand-maintained list of schema facts always
     * becomes. `information_schema` already knows the answer.
     *
     * Empty on MySQL and SQLite: MySQL reports a native `json` type that the
     * type arm matches, and SQLite has no such constraint. Cached per table.
     *
     * @var array<string, list<string>>
     */
    private array $jsonChecked = [];

    private function isJsonChecked(string $table, string $column): bool
    {
        if (! array_key_exists($table, $this->jsonChecked)) {
            $this->jsonChecked[$table] = $this->jsonCheckedColumns($table);
        }

        return in_array($column, $this->jsonChecked[$table], true);
    }

    /** @return list<string> */
    private function jsonCheckedColumns(string $table): array
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return [];
        }

        try {
            $rows = DB::select(
                'SELECT CONSTRAINT_NAME AS name, CHECK_CLAUSE AS clause
                   FROM information_schema.CHECK_CONSTRAINTS
                  WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ?',
                [DB::connection()->getDatabaseName(), $table],
            );
        } catch (\Throwable) {
            // MySQL 8 has CHECK_CONSTRAINTS but no TABLE_NAME column before
            // 8.0.16, and other builds may not expose it at all. A driver that
            // cannot answer is a driver that does not need the answer.
            return [];
        }

        $columns = [];

        foreach ($rows as $row) {
            if (preg_match('/json_valid\s*\(\s*`?([A-Za-z0-9_]+)`?\s*\)/i', (string) $row->clause, $m)) {
                $columns[] = $m[1];
            }
        }

        return $columns;
    }

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
