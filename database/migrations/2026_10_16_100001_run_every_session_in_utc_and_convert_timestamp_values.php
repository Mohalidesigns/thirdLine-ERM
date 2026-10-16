<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0029 — every database session runs in UTC, and the TIMESTAMP values
 * already written are converted once.
 *
 * THE TRAP. A `TIMESTAMP` is stored as a UTC instant and converted through the
 * SESSION zone on every write and read. Until this release no connection set a
 * session zone, so every session ran on the server's `SYSTEM` zone (CEST on
 * the production VPS) while the application wrote UTC strings. Writing
 * '2026-10-16 06:00:00' under CEST stores the instant 04:00Z, and reading it
 * back under CEST returns '06:00:00' again: the round trip is exact, so
 * nothing could see that the stored instant was wrong. `config/database.php`
 * now pins `'timezone' => '+00:00'`, and that pin ALONE would make every
 * existing app-written TIMESTAMP read one or two hours early, and every row of
 * both audit chains read as tampered. This migration is the other half: it
 * rewrites each stored instant so that, read under UTC, it shows the string
 * the application wrote. It must ship in the same deploy as the pin, with
 * nothing writing in between (scripts/deploy.sh takes the site down for it).
 *
 * THE OLD ZONE is `@@global.time_zone`, not the migrating session's zone. No
 * earlier release ever set a session zone, so every row went through the global
 * one. The session is no witness: on an uncached checkout the new config is
 * already live and the session is already `+00:00`. A named zone on a server
 * without time-zone tables cannot be converted from (`CONVERT_TZ` returns
 * NULL); that throws before anything is written rather than guessing an offset.
 *
 * WHICH COLUMNS. Every TIMESTAMP column found in information_schema at run
 * time, not a frozen list: the set differs between environments and a missed
 * column would be shifted silently by the pin.
 *
 * `risk_audit_trail.changed_at` is NOT updated. Two triggers forbid every
 * UPDATE on that table, and dropping them would lift the append-only guard on
 * the evidence table. It is retyped TIMESTAMP -> DATETIME instead, under the
 * OLD session zone: the ALTER reads each value through that zone and stores the
 * literal it produces, which is exactly the string the application sealed. An
 * ALTER is not a row update, so the triggers neither fire nor need touching.
 * That also takes the ERM chain out of session-zone conversion for good.
 *
 * THE LEDGER. `db_timezone_conversions` gets one row per converted table,
 * written in the same transaction as that table's UPDATE. MySQL cannot wrap 271
 * tables and a DDL statement in one transaction, and the `migrations` row is
 * written only after up() returns; without the ledger a failure on table 140
 * would leave 139 converted and a re-run would convert them a second time, a
 * silent two-hour shift. A re-run resumes at the first table with no row, in
 * the zone the first attempt used.
 *
 * SKIPS. A UTC-equivalent old zone (CI containers, any host that was always
 * UTC) and a database with no organisation (a fresh install, whose rows were
 * all written in this same migrate run under the pinned config) are recorded
 * as a `'*'` ledger row and converted nowhere. The ALTER above runs on every
 * path so every environment ends with one shape.
 *
 * DEPLOY ORDER. This migration must run alone in its deploy: a migration that
 * sorts before it in the same run already writes under the pinned `+00:00`
 * (`composer install` deletes the config cache, so the pin is live), into
 * tables that have not been converted yet, and the conversion would shift those
 * rows. scripts/deploy.sh keeps the site down from before `composer install`
 * until the end for the same reason (ADR 0029 section 3a, precondition 0).
 *
 * THE SESSION IS LEFT ON +00:00 for the rest of the process. A later migration
 * in the same run writes into converted tables, so it must write UTC.
 *
 * down() reverses the conversion, because rolling code back past this release
 * without rolling the data back is the same trap in the other direction.
 * `changed_at` stays DATETIME (zone-free, correct under any session).
 *
 * Raw SQL and the query builder only, no Eloquent: its behaviour cannot drift
 * when a model changes. Identifiers come only from information_schema.
 */
return new class extends Migration
{
    private const LEDGER = 'db_timezone_conversions';

    public function up(): void
    {
        if (! $this->isMysqlFamily()) {
            return;
        }

        if (! Schema::hasTable(self::LEDGER)) {
            Schema::create(self::LEDGER, function (Blueprint $table) {
                $table->id();
                $table->string('table_name', 64)->unique();
                $table->text('column_names');
                $table->string('outcome', 32);
                $table->string('from_zone', 64);
                $table->string('system_time_zone', 64)->nullable();
                $table->unsignedBigInteger('rows_affected')->default(0);
                $table->dateTime('converted_at');
            });
        }

        // Throws on an unusable zone, before anything below writes.
        $old = $this->resolveOldZone();

        $this->convergeColumnToDatetime('risk_audit_trail', 'changed_at', $old);

        $this->setSessionZone('+00:00');

        if ($this->ledger()->where('table_name', '*')->exists()) {
            return;
        }

        if ($this->isUtcEquivalent($old)) {
            $this->recordSkip('skipped_utc_equivalent', $old);

            return;
        }

        if (! $this->ledger()->where('outcome', 'converted')->exists() && ! $this->hasTenants()) {
            $this->recordSkip('skipped_fresh_database', $old);

            return;
        }

        $converted = $this->convertFrom($old);

        if (! app()->runningUnitTests()) {
            echo '    ADR 0029: converted '.count($converted).' tables from '.$old.' to +00:00 ('
                .array_sum($converted).' rows changed).'.PHP_EOL;
        }
    }

    /**
     * The zone the existing TIMESTAMP values were written under.
     *
     * A resumed run uses the zone its first attempt recorded; otherwise it is
     * `@@global.time_zone`, validated against both January and July so that a
     * named zone with no time-zone tables fails here and not halfway through.
     */
    public function resolveOldZone(): string
    {
        $recorded = [];

        if (Schema::hasTable(self::LEDGER)) {
            $recorded = $this->ledger()->where('outcome', 'converted')->distinct()->pluck('from_zone')->all();
        }

        if (count($recorded) > 1) {
            throw new RuntimeException(
                'ADR 0029: the conversion ledger records more than one source zone ('.implode(', ', $recorded).
                '); a resumed run must use the zone the first attempt used. Refusing to continue.'
            );
        }

        $zone = $recorded[0] ?? (string) DB::selectOne('select @@global.time_zone as z')->z;

        $probe = DB::selectOne(
            "select convert_tz('2026-01-15 12:00:00', '+00:00', ?) as jan, convert_tz('2026-07-15 12:00:00', '+00:00', ?) as jul",
            [$zone, $zone]
        );

        if ($probe->jan === null || $probe->jul === null) {
            throw new RuntimeException(
                "ADR 0029: the database's global time zone is [{$zone}], which this server cannot convert from "
                .'(it has no time-zone tables). A fixed offset would be wrong for half the year, so nothing has '
                .'been written. Load the time-zone tables or have the architect amend ADR 0029.'
            );
        }

        return $zone;
    }

    /**
     * Retype a TIMESTAMP column to DATETIME without changing what it reads as
     * under $oldZone, and without a row update (so append-only triggers stay).
     */
    public function convergeColumnToDatetime(string $table, string $column, string $oldZone): void
    {
        $meta = DB::selectOne(
            'select DATA_TYPE as t, IS_NULLABLE as n from information_schema.COLUMNS
              where TABLE_SCHEMA = database() and TABLE_NAME = ? and COLUMN_NAME = ?',
            [$table, $column]
        );

        if ($meta === null || strtolower((string) $meta->t) !== 'timestamp') {
            return;
        }

        $previous = (string) DB::selectOne('select @@session.time_zone as z')->z;

        try {
            $this->setSessionZone($oldZone);

            $shape = strtoupper((string) $meta->n) === 'YES' ? 'NULL DEFAULT NULL' : 'NOT NULL DEFAULT CURRENT_TIMESTAMP';

            DB::statement("alter table {$this->q($table)} modify {$this->q($column)} DATETIME {$shape}");
        } finally {
            $this->setSessionZone($previous);
        }
    }

    /**
     * Convert every TIMESTAMP column, one transaction per table.
     *
     * @return array<string, int> table => rows changed
     */
    public function convertFrom(string $oldZone): array
    {
        $this->setSessionZone('+00:00');

        $columns = [];

        $found = DB::select(
            "select c.TABLE_NAME as t, c.COLUMN_NAME as c
               from information_schema.COLUMNS c
               join information_schema.TABLES t
                 on t.TABLE_SCHEMA = c.TABLE_SCHEMA and t.TABLE_NAME = c.TABLE_NAME
              where c.TABLE_SCHEMA = database()
                and t.TABLE_TYPE = 'BASE TABLE'
                and lower(c.DATA_TYPE) = 'timestamp'
              order by c.TABLE_NAME, c.ORDINAL_POSITION"
        );

        foreach ($found as $row) {
            $columns[$row->t][] = $row->c;
        }

        $done = $this->ledger()->where('table_name', '!=', '*')->pluck('table_name')->all();
        $systemZone = DB::selectOne('select @@system_time_zone as z')->z;
        $result = [];

        foreach ($columns as $table => $cols) {
            if (in_array($table, $done, true)) {
                continue;
            }

            DB::transaction(function () use ($table, $cols, $oldZone, $systemZone, &$result) {
                $before = $this->nullCounts($table, $cols);

                $set = implode(', ', array_map(fn ($c) => "{$this->q($c)} = convert_tz({$this->q($c)}, '+00:00', ?)", $cols));
                $affected = DB::update("update {$this->q($table)} set {$set}", array_fill(0, count($cols), $oldZone));

                // The conversion must never create or remove a NULL: a zero date
                // or an unmappable value comes back NULL and would vanish.
                if ($this->nullCounts($table, $cols) !== $before) {
                    throw new RuntimeException(
                        "ADR 0029: converting [{$table}] changed the number of NULLs in ".implode(', ', $cols).
                        ' (a zero date or a value CONVERT_TZ cannot map). The table was rolled back.'
                    );
                }

                $this->ledger()->insert([
                    'table_name' => $table,
                    'column_names' => implode(',', $cols),
                    'outcome' => 'converted',
                    'from_zone' => $oldZone,
                    'system_time_zone' => $systemZone,
                    'rows_affected' => $affected,
                    'converted_at' => now(),
                ]);

                $result[$table] = $affected;
            });
        }

        return $result;
    }

    public function down(): void
    {
        if (! $this->isMysqlFamily() || ! Schema::hasTable(self::LEDGER)) {
            return;
        }

        $this->setSessionZone('+00:00');

        foreach ($this->ledger()->where('outcome', 'converted')->orderBy('id')->get() as $entry) {
            $cols = explode(',', $entry->column_names);

            DB::transaction(function () use ($entry, $cols) {
                $before = $this->nullCounts($entry->table_name, $cols);

                $set = implode(', ', array_map(fn ($c) => "{$this->q($c)} = convert_tz({$this->q($c)}, ?, '+00:00')", $cols));
                DB::update("update {$this->q($entry->table_name)} set {$set}", array_fill(0, count($cols), $entry->from_zone));

                if ($this->nullCounts($entry->table_name, $cols) !== $before) {
                    throw new RuntimeException("ADR 0029 down(): reversing [{$entry->table_name}] changed the number of NULLs; rolled back.");
                }

                $this->ledger()->where('id', $entry->id)->delete();
            });
        }

        Schema::dropIfExists(self::LEDGER);

        // risk_audit_trail.changed_at stays DATETIME: zone-free, correct under any session.
    }

    /** @param  list<string>  $cols
     * @return list<int> */
    private function nullCounts(string $table, array $cols): array
    {
        $select = implode(', ', array_map(fn ($c, $i) => "sum({$this->q($c)} is null) as n{$i}", $cols, array_keys($cols)));
        $row = (array) DB::selectOne("select {$select} from {$this->q($table)}");

        return array_map(fn ($v) => (int) $v, array_values($row));
    }

    public function isUtcEquivalent(string $zone): bool
    {
        $r = DB::selectOne(
            "select convert_tz('2026-01-15 12:00:00', '+00:00', ?) as jan, convert_tz('2026-07-15 12:00:00', '+00:00', ?) as jul",
            [$zone, $zone]
        );

        return $r->jan === '2026-01-15 12:00:00' && $r->jul === '2026-07-15 12:00:00';
    }

    private function hasTenants(): bool
    {
        return Schema::hasTable('organizations') && DB::table('organizations')->exists();
    }

    private function recordSkip(string $outcome, string $zone): void
    {
        $this->ledger()->insert([
            'table_name' => '*',
            'column_names' => '',
            'outcome' => $outcome,
            'from_zone' => $zone,
            'system_time_zone' => DB::selectOne('select @@system_time_zone as z')->z,
            'rows_affected' => 0,
            'converted_at' => now(),
        ]);
    }

    private function setSessionZone(string $zone): void
    {
        DB::statement('set time_zone = '.DB::getPdo()->quote($zone));
    }

    private function ledger(): \Illuminate\Database\Query\Builder
    {
        return DB::table(self::LEDGER);
    }

    private function q(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    private function isMysqlFamily(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
