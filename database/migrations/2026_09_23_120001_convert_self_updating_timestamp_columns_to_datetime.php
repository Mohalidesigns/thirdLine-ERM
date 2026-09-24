<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0022 — a bare `timestamp()` column rewrites itself on every update.
 *
 * When `explicit_defaults_for_timestamp` is OFF (MariaDB 10.4's shipped
 * default, and MySQL before 8.0.2), the first `TIMESTAMP NOT NULL` column in a
 * table that names no explicit default silently gets
 * `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`. Laravel's bare
 * `$table->timestamp('x')` emits exactly `x TIMESTAMP NOT NULL`, so it gets
 * that shape. Any UPDATE that touches any OTHER column on the row then
 * overwrites `x` with the server's current, server-zone time — silently: no
 * query fails, and Eloquent's in-memory model still holds the old value after
 * `save()`, so `getChanges()` never sees it and no audit trail records it.
 *
 * A sweep of every migration on `origin/main` found 15 columns in that exact
 * shape, unchanged since first commit (see ADR 0022 for the per-column trace
 * of which are live-active today and which are merely latent). Two more
 * columns — `bcms_identity_sync_runs.started_at` and
 * `bcms_incident_notifications.awareness_at` — were found and fixed in place
 * in their own (uncommitted, at the time) migrations, so they carry the
 * correct `dateTime()` shape wherever their migration has already run as
 * committed. But a database that migrated BEFORE that in-place fix — the
 * shared developer database, and any `risk_test_*` copy built from an earlier
 * draft — still has them in the defective shape, because Laravel never
 * re-runs a migration by name. This migration converges every environment,
 * including those two, onto the same shape: `DATETIME NOT NULL`, no default.
 *
 * WHY DATETIME AND NOT A `TIMESTAMP` WITH AN EXPLICIT DEFAULT. `DATETIME` has
 * no implicit default of any kind, on any server setting, and no session-zone
 * conversion on read or write. The application writes UTC strings throughout
 * (`config/app.php` timezone `UTC`) and `config/database.php` sets no
 * connection `timezone`, so a `TIMESTAMP` column round-trips through the
 * server's `SYSTEM` zone — the exact mechanism that put `bcms_identity_sync_runs
 * .started_at` roughly an hour ahead of `finished_at` on this machine (WAT).
 * None of the 17 columns needs `TIMESTAMP` semantics (auto-conversion for
 * clients in different zones); every one of them is an application-computed
 * instant, always written and always read in the same process's UTC.
 *
 * WHY NO DEFAULT. Every production insert path for every one of the 17
 * columns already writes the column explicitly — that is how the "update
 * path" column in ADR 0022's table was traced. Dropping the implicit default
 * therefore changes nothing for a correct caller. It DOES mean that a caller
 * who omits the column now fails loudly, under `STRICT_TRANS_TABLES`, with
 * `1364 Field doesn't have a default value` — instead of silently taking a
 * server-local `CURRENT_TIMESTAMP` in the wrong time zone. That is the
 * intended trade: a missed write path should be a stack trace, not a
 * plausible-looking wrong value nobody notices until an examiner does.
 *
 * IDEMPOTENT AND DRIVER-GUARDED. For each of the 17 (table, column) pairs:
 *   - skip entirely on a non-mysql/mariadb connection (SQLite has no
 *     `TIMESTAMP` type and no `ON UPDATE`; `->change()` there would rebuild
 *     17 tables, several with foreign keys, to fix nothing);
 *   - skip if the table or the column does not exist. Two of the seventeen —
 *     `bcms_identity_sync_runs.started_at` and
 *     `bcms_incident_notifications.awareness_at` — do not exist anywhere on
 *     `origin/main` (their migrations are BCMS Phase 2C/10 work, not yet
 *     landed here). That is expected, and is exactly what lets this migration
 *     land on `main` alone, ahead of those phases, per ADR 0022's Sequencing
 *     section;
 *   - read `information_schema.COLUMNS.DATA_TYPE` and `.IS_NULLABLE` for the
 *     current schema. The trigger is `DATA_TYPE = 'timestamp'`, not the
 *     presence of `ON UPDATE` — on a server with
 *     `explicit_defaults_for_timestamp` ON (MySQL 8, or a production host
 *     that happens to set it that way) the same declaration carries no
 *     `ON UPDATE` today, but it must still converge to the same `DATETIME`
 *     shape everywhere, because the fact that matters is the type, not
 *     today's value of a server variable this application does not control.
 *     If the type is already `datetime` (this migration has already run, or
 *     the column was fixed in place as `dateTime()` from first commit), it is
 *     skipped: `MODIFY` on an already-`DATETIME` column would be a harmless
 *     no-op regardless, but reading the type first keeps this migration from
 *     issuing 17 needless `ALTER TABLE`s on every fresh `migrate:fresh`.
 *     `IS_NULLABLE` is read and preserved rather than hard-coded: all 17
 *     target columns are `NOT NULL` today, so behaviour does not change for
 *     any of them, but a future addition to the list is then safe to be
 *     nullable without silently gaining a `NOT NULL` it never asked for.
 *
 * `MODIFY ... DATETIME NOT NULL` (or `DATETIME NULL`, for whichever columns
 * are nullable — none of the 17 are) is valid, unqualified SQL on MariaDB
 * 10.4 and on MySQL 5.7/8, keeps the column's indexes (it is not a
 * drop-and-recreate), and precision is `(0)` on both sides of the
 * conversion, so nothing is truncated. No column here carries a comment
 * MODIFY would need to restate.
 *
 * VALUES ARE PRESERVED AS THE APPLICATION READS THEM. A `TIMESTAMP` to
 * `DATETIME` conversion reads through the session time zone and writes the
 * literal wall-clock value that produced. This migration runs on the
 * application's own connection, under the same `SYSTEM` zone the application
 * itself reads and writes in, so every value the application already sees is
 * unchanged by this migration — confirmed against a scratch table on this
 * machine's MariaDB 10.4.28 before this file was written. No row's business
 * data is rewritten here; only the column's declared type changes. Rows that
 * a PRIOR update had already corrupted (ADR 0022's rows 13, 16 and 17 on the
 * shared developer database) stay wrong — recovery is a separate, per-row,
 * per-environment decision (see the ADR's "Recovery source" column), not
 * something a schema migration should attempt.
 *
 * `down()` is a documented no-op: restoring `TIMESTAMP NOT NULL` with no
 * default would re-arm the exact silent rewrite this migration exists to
 * stop, and a migration should not do that quietly. This departs from this
 * repository's usual symmetric-`down()` convention deliberately; see ADR 0022
 * §3 and the sibling `2026_09_16_120001_widen_bcms_audit_log_event_column.php`
 * for the same reasoning applied to a different guard.
 *
 * See docs/adr/0022-a-bare-timestamp-column-rewrites-itself-on-every-update.md
 * for the full sweep, the per-column trace of live-vs-latent exposure, and the
 * alternatives this rejected.
 */
return new class extends Migration
{
    /**
     * The 17 columns ADR 0022 identifies. Order matches the ADR's table.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const COLUMNS = [
        ['workflow_actions', 'acted_at'],
        ['config_bundle_applications', 'applied_at'],
        ['tp_inherent_assessments', 'assessed_at'],
        ['tp_screening_checks', 'run_at'],
        ['tp_findings', 'identified_at'],
        ['tp_monitoring_signals', 'observed_at'],
        ['tp_concentration_analyses', 'run_at'],
        ['tp_portal_invitations', 'expires_at'],
        ['tp_assessment_delegations', 'delegated_at'],
        ['bcms_plan_activations', 'activated_at'],
        ['bcms_exercise_timeline', 'logged_at'],
        ['bcms_incident_log', 'logged_at'],
        ['bcms_maturity_assessments', 'assessed_at'],
        ['bcms_plan_attestations', 'attested_at'],
        ['llm_usage_events', 'created_at'],
        // Fixed in place already (committed as dateTime() from first commit,
        // or uncommitted-but-already-corrected), on any database whose
        // migration ran before the fix. Listed here so THOSE databases
        // converge too. Both are absent on origin/main today — a clean skip.
        ['bcms_identity_sync_runs', 'started_at'],
        ['bcms_incident_notifications', 'awareness_at'],
    ];

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            // SQLite (and anything else) has no TIMESTAMP type and no
            // ON UPDATE behaviour to fix. Nothing to do.
            return;
        }

        $database = DB::connection()->getDatabaseName();

        foreach (self::COLUMNS as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $info = DB::selectOne(
                'select DATA_TYPE as data_type, IS_NULLABLE as is_nullable from information_schema.COLUMNS '.
                'where TABLE_SCHEMA = ? and TABLE_NAME = ? and COLUMN_NAME = ?',
                [$database, $table, $column]
            );

            if ($info === null || $info->data_type !== 'timestamp') {
                // Already DATETIME (this migration already ran, or the
                // column was declared dateTime() from first commit), or some
                // other type entirely. Either way, not this migration's job.
                continue;
            }

            // Preserve the column's own nullability rather than hard-coding
            // NOT NULL. All 17 columns this migration targets today are NOT
            // NULL — hard-coding was safe for them — but reading IS_NULLABLE
            // keeps the column list safe to extend: a future (table, column)
            // added here that happens to be nullable would otherwise be
            // silently forced NOT NULL and fail on any existing NULL row.
            $nullability = strtoupper((string) $info->is_nullable) === 'YES' ? 'NULL' : 'NOT NULL';

            DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` DATETIME {$nullability}");
        }
    }

    public function down(): void
    {
        // Deliberately not implemented. Restoring TIMESTAMP NOT NULL with no
        // explicit default would put every one of the 17 columns back into
        // the exact implicit-DEFAULT-CURRENT_TIMESTAMP-ON-UPDATE-
        // CURRENT_TIMESTAMP shape this migration exists to remove, on any
        // server with explicit_defaults_for_timestamp OFF (MariaDB 10.4's
        // shipped default). A migration should not silently re-arm a defect.
        // See ADR 0022 §3.
    }
};
