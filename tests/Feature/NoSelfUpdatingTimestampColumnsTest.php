<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR 0022 — a bare `timestamp()` column rewrites itself on every update.
 *
 * Two engineers found and fixed the one column in front of them
 * (`bcms_identity_sync_runs.started_at` in Phase 2C, `bcms_incident_notifications
 * .awareness_at` in Phase 10) before a sweep found fifteen more, unchanged
 * since first commit, several of them actively rewriting live data on every
 * unrelated UPDATE to their row. This test is the sweep made permanent: it
 * scans the WHOLE schema, not a named list of tables, so the next bare
 * `timestamp()` fails CI instead of waiting for a third engineer to notice.
 *
 * THE MECHANISM. When `explicit_defaults_for_timestamp` is OFF (MariaDB
 * 10.4's shipped default, and MySQL before 8.0.2), the first `TIMESTAMP NOT
 * NULL` column in a table with no explicit default is implicitly given
 * `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`. Any UPDATE that
 * touches any OTHER column on the row then silently overwrites that column
 * with the server's current, server-zone time. No query fails, and Eloquent's
 * in-memory model still holds the old value after `save()`, so
 * `getChanges()` never sees the rewrite and no audit trail records it.
 *
 * WHY TWO RULES. Checking only for `ON UPDATE` is not enough: on a server
 * with `explicit_defaults_for_timestamp` ON (MySQL 8, or a production host
 * that happens to set it that way), the identical bare `timestamp()`
 * declaration carries NO `ON UPDATE` and a plain `NULL` default — it is
 * still the wrong shape, just wrong in a way this rule set's first clause
 * would miss. It is also not the ONLY implicit shape MySQL/MariaDB assign: a
 * bare `NOT NULL TIMESTAMP` that is not the first such column in the table
 * gets a zero-date default (`0000-00-00 00:00:00`) instead, which rule (b)
 * below catches by demanding an explicit `current_timestamp()` default on
 * every NOT NULL timestamp, not merely the absence of `ON UPDATE`.
 *
 * Together, (a) and (b) mean the only permitted `TIMESTAMP` shapes anywhere
 * in this schema are *nullable* (`DEFAULT NULL`, what `timestamps()`
 * produces) and *`useCurrent()` without on-update* (`DEFAULT
 * current_timestamp()`, no `ON UPDATE`) — whatever the server's
 * `explicit_defaults_for_timestamp` setting says today. An event time the
 * application computes and writes itself is `dateTime()`, which carries
 * neither shape and cannot be given either by the server.
 *
 * THE ALLOWLIST FOR (a) IS EMPTY. `useCurrentOnUpdate()` needs its own ADR
 * before a single row is added here — see DEVELOPMENT_STANDARD.md's new
 * entry.
 *
 * WHY `SHOW FULL COLUMNS`, NOT `information_schema.COLUMNS`. Temporary
 * tables — used below for the control, to avoid the implicit commit a real
 * `CREATE TABLE` would make under `RefreshDatabase` — are not reliably
 * listed in `information_schema` on MariaDB 10.4. `SHOW FULL COLUMNS FROM
 * <table>` works for both permanent and temporary tables, so the same
 * detector method serves the whole-schema scan and the control.
 */
class NoSelfUpdatingTimestampColumnsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function no_table_has_a_self_updating_or_implicitly_defaulted_timestamp_column(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped(
                'Only MySQL/MariaDB assign an implicit default or ON UPDATE clause to a bare TIMESTAMP column.'
            );
        }

        $violations = [];
        $timestampColumnsSeen = 0;

        foreach ($this->baseTables() as $table) {
            foreach ($this->scanTable($table) as $column) {
                // Rule (a) below applies to every column, of any type: a
                // DATETIME carrying an implicit ON UPDATE is exactly as bad as
                // a TIMESTAMP carrying one, and is the shape
                // useCurrentOnUpdate() produces. Only the timestampColumnsSeen
                // sanity count and rule (b) are timestamp-specific, and that
                // narrowing happens inside violationsFor() itself so the scan
                // and the control exercise the same method.
                if ($this->isTimestampType($column->Type)) {
                    $timestampColumnsSeen++;
                }

                foreach ($this->violationsFor($column) as $reason) {
                    $violations[] = "{$table}.{$column->Field}: {$reason}";
                }
            }
        }

        // The rule must not be able to pass by matching nothing. The schema
        // carries 525+ created_at/updated_at columns alone (ADR 0022), so a
        // scan that saw none of them found no tables, not a clean schema.
        $this->assertGreaterThan(
            100,
            $timestampColumnsSeen,
            'The scan saw an implausibly small number of TIMESTAMP columns — it likely scanned nothing.'
        );

        $this->assertSame(
            [],
            $violations,
            "Self-updating or implicitly-defaulted TIMESTAMP column(s) found:\n".implode("\n", $violations).
            "\n\nA bare timestamp() column is not permitted. Use ->nullable() or ->useCurrent() (no ON UPDATE), ".
            'or dateTime() for an event time the application writes itself. See ADR 0022.'
        );
    }

    #[Test]
    public function the_detector_matches_a_deliberately_bad_column(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped(
                'Only MySQL/MariaDB assign an implicit default or ON UPDATE clause to a bare TIMESTAMP column.'
            );
        }

        // A temporary table (not a real one) so this never touches
        // information_schema state RefreshDatabase has to reason about, and
        // carries no implicit commit.
        DB::statement(
            'CREATE TEMPORARY TABLE ts_adr0022_control ('.
            'id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, '.
            // Case (a): the classic implicit shape — first NOT NULL
            // TIMESTAMP in the table, no explicit default.
            'bad_on_update TIMESTAMP NOT NULL, '.
            // Case (b): NOT NULL TIMESTAMP with an explicit non-current
            // default (the zero-date shape a non-first bare column gets, or
            // what the identical declaration becomes under
            // explicit_defaults_for_timestamp = ON).
            // Not 1970-01-01 00:00:01 (the TIMESTAMP floor): a literal that
            // close to the epoch converts to before it once the session's
            // SYSTEM time zone is applied, and MariaDB rejects the DEFAULT.
            "bad_no_current_default TIMESTAMP NOT NULL DEFAULT '2000-01-01 00:00:00', ".
            // Two permitted shapes, to prove the detector does not simply
            // flag every TIMESTAMP column.
            'good_nullable TIMESTAMP NULL DEFAULT NULL, '.
            'good_use_current TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, '.
            // Case (a) again, but on a DATETIME rather than a TIMESTAMP —
            // the shape useCurrentOnUpdate() produces, and the one rule (a)
            // must still catch even though it is not a timestamp type and so
            // never reaches rule (b). This is the regression the early
            // `continue` in the scan loop let through.
            'bad_datetime_on_update DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'.
            ')'
        );

        try {
            $columns = collect($this->scanTable('ts_adr0022_control'))->keyBy('Field');

            $this->assertNotEmpty(
                $this->violationsFor($columns['bad_on_update']),
                'The control column with an implicit ON UPDATE must be flagged.'
            );
            $this->assertNotEmpty(
                $this->violationsFor($columns['bad_no_current_default']),
                'The control column with a non-current NOT NULL default must be flagged.'
            );
            $this->assertEmpty(
                $this->violationsFor($columns['good_nullable']),
                'A nullable TIMESTAMP must not be flagged.'
            );
            $this->assertEmpty(
                $this->violationsFor($columns['good_use_current']),
                'A NOT NULL TIMESTAMP with an explicit current_timestamp() default and no ON UPDATE must not be flagged.'
            );
            $this->assertNotEmpty(
                $this->violationsFor($columns['bad_datetime_on_update']),
                'A DATETIME column carrying an implicit ON UPDATE must be flagged too — rule (a) is not '.
                'restricted to TIMESTAMP columns.'
            );
        } finally {
            DB::statement('DROP TEMPORARY TABLE IF EXISTS ts_adr0022_control');
        }
    }

    /**
     * @return list<string>
     */
    private function baseTables(): array
    {
        return DB::table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_TYPE', 'BASE TABLE')
            ->pluck('TABLE_NAME')
            ->all();
    }

    /**
     * @return list<object{Field: string, Type: string, Null: string, Default: ?string, Extra: string}>
     */
    private function scanTable(string $table): array
    {
        return DB::select("SHOW FULL COLUMNS FROM `{$table}`");
    }

    private function isTimestampType(string $type): bool
    {
        return str_starts_with(strtolower($type), 'timestamp');
    }

    /**
     * @return list<string>
     */
    private function violationsFor(object $column): array
    {
        $reasons = [];

        // (a) No column, anywhere — of any type, not merely TIMESTAMP —
        // carries an implicit ON UPDATE. useCurrentOnUpdate() produces this
        // exact EXTRA on a DATETIME column too. The allowlist is empty; an
        // intended one needs its own ADR and its own named exception here —
        // not a widened rule.
        if (stripos((string) $column->Extra, 'on update') !== false) {
            $reasons[] = 'carries an implicit ON UPDATE CURRENT_TIMESTAMP clause';
        }

        // (b) A NOT NULL TIMESTAMP must have an explicit current_timestamp()
        // default. This catches the zero-date/NULL-default shape the same
        // bare declaration takes when it is not the table's first such
        // column, or when explicit_defaults_for_timestamp is ON. Restricted
        // to TIMESTAMP columns: a NOT NULL DATETIME with an
        // application-supplied value and no default (every dateTime() column
        // ADR 0022 converted 17 columns to) is exactly the correct shape, not
        // a violation.
        if ($this->isTimestampType($column->Type)
            && strtolower((string) $column->Null) === 'no'
            && stripos((string) $column->Default, 'current_timestamp') !== 0
        ) {
            $reasons[] = 'is NOT NULL without an explicit current_timestamp() default '.
                '(default was: '.(($column->Default === null) ? 'NULL' : var_export($column->Default, true)).')';
        }

        return $reasons;
    }
}
