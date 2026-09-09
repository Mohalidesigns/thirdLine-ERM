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

    /** @var array<string, list<string>>|null table => JSON column names; null until loaded */
    private ?array $jsonColumnCache = null;

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
            // json_valid() CHECK constraint. Ask the schema instead of guessing.
            str_ends_with($name, '_json') || $this->isJsonColumn($table, $name) => '[]',
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
     * Is $column a JSON column, whatever the driver calls it?
     *
     * This replaced a hand-maintained allowlist of column names. The list had
     * 16 entries against a schema with 203 JSON columns, and every module that
     * shipped after it was written added more it did not know about — so the
     * suite went green on SQLite and failed on MariaDB with
     * `CONSTRAINT \`table.column\` failed`. A list of names cannot track a
     * schema; the schema can.
     */
    private function isJsonColumn(string $table, string $column): bool
    {
        return in_array($column, $this->jsonColumns($table), true);
    }

    /**
     * JSON columns of $table, according to the database rather than a list.
     *
     * MySQL 8 has a native `json` type. MariaDB does not: it stores JSON as
     * LONGTEXT with an auto-generated `CHECK (json_valid(`col`))` constraint,
     * which is the only thing that distinguishes it from any other text
     * column — so that constraint is what we read. SQLite enforces neither and
     * needs no special case.
     *
     * @return list<string>
     */
    /**
     * JSON columns of $table, according to the database rather than a list.
     *
     * MySQL 8 has a native `json` type. MariaDB does not: it stores JSON as
     * LONGTEXT with an auto-generated `CHECK (json_valid(`col`))` constraint,
     * which is the only thing distinguishing it from any other text column —
     * so that constraint is what we read. SQLite enforces neither and needs no
     * special case.
     *
     * The whole schema is read in one pair of queries on first use, not two
     * queries per table. Per-table lookups meant ~70 information_schema
     * round-trips inside per-test transactions, and information_schema is not
     * a normal table — MariaDB opens table definitions to answer, and the
     * round-trips dominated the run.
     *
     * @return list<string>
     */
    private function jsonColumns(string $table): array
    {
        if ($this->jsonColumnCache === null) {
            $this->jsonColumnCache = $this->loadJsonColumns();
        }

        return $this->jsonColumnCache[$table] ?? [];
    }

    /**
     * @return array<string, list<string>> table => JSON column names
     */
    private function loadJsonColumns(): array
    {
        $connection = DB::connection();

        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return [];
        }

        $schema = $connection->getDatabaseName();
        $map = [];

        // MySQL 8's native type.
        foreach ($connection->select(
            'SELECT table_name, column_name FROM information_schema.columns
             WHERE table_schema = ? AND data_type = ?',
            [$schema, 'json']
        ) as $row) {
            $r = array_values(get_object_vars($row));
            $map[(string) $r[0]][] = (string) $r[1];
        }

        // MariaDB's longtext-plus-CHECK form. Guarded because the columns of
        // information_schema.check_constraints differ between the two engines.
        try {
            foreach ($connection->select(
                'SELECT table_name, check_clause FROM information_schema.check_constraints
                 WHERE constraint_schema = ? AND check_clause LIKE ?',
                [$schema, '%json_valid%']
            ) as $row) {
                $r = array_values(get_object_vars($row));

                if (preg_match('/json_valid\\(`(.+?)`\\)/i', (string) $r[1], $m)) {
                    $map[(string) $r[0]][] = $m[1];
                }
            }
        } catch (\Throwable) {
            // MySQL 8 has no table_name on that view; its native json type is
            // already covered above.
        }

        return array_map(fn (array $c) => array_values(array_unique($c)), $map);
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
