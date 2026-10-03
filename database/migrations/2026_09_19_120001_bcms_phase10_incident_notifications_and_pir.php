<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BCMS Phase 10 — ADR 0020. Two changes, both to the shared `bcms_aars`
 * table, plus one new table.
 *
 * 1. `bcms_aars.occurrence_id` becomes NULLABLE. The FK and the UNIQUE index
 *    are left exactly as they are: MariaDB treats NULL as distinct in a
 *    unique index, so `unique(occurrence_id)` keeps meaning "one AAR per
 *    occurrence" while allowing any number of rows whose `occurrence_id` is
 *    null (post-incident reviews).
 *
 *    THE COLUMN IS ALTERED WITH A RAW `MODIFY` ON MYSQL/MARIADB, NOT
 *    `$table->foreignId(...)->nullable()->change()`. A Laravel `change()` on
 *    MariaDB 10.4 must restate every modifier or it silently drops one — the
 *    ADR names this as the riskiest line in the migration — and a `MODIFY`
 *    that touches only nullability leaves the existing foreign key and the
 *    existing unique index untouched, because neither is part of what is
 *    being redefined. This is deliberately NOT `dropForeign()` then
 *    `constrained()->unique()` again: recreating either object is exactly
 *    the operation the ADR says must not happen, because a migration that
 *    quietly failed to recreate the unique index would leave "one AAR per
 *    occurrence" enforced by nothing and nothing would say so. THE RAW
 *    `MODIFY` ITSELF STILL HAS TO RESTATE EVERY MODIFIER — it is not exempt
 *    from the rule it is working around, it is merely safe here: it is
 *    correct only because `occurrence_id` carries no `DEFAULT` and no column
 *    `COMMENT` to lose. A column with either would need both restated in the
 *    same `MODIFY`, on pain of the identical silent-drop defect the `change()`
 *    warning above describes. Do not treat a raw `MODIFY` as generally safer
 *    than `change()` — it is the same restate-everything rule, just spelled
 *    by hand instead of by Laravel.
 *
 *    ON SQLITE — not the test suite's own database on this branch (phpunit
 *    runs MariaDB per `phpunit.xml`), but `main`'s suite, and the database
 *    `ReferenceCodeConcurrencyTest`'s throwaway file always uses regardless of
 *    branch — the raw `MODIFY` is a syntax error: SQLite has no
 *    `ALTER TABLE ... MODIFY`. The column is changed there with
 *    `$table->unsignedBigInteger('occurrence_id')->nullable()->change()`,
 *    which Laravel 11+ carries out as a table rebuild (SQLite's only
 *    mechanism for altering a column at all). The rebuild is driven off the
 *    CURRENT schema, so it copies the column's existing FK and the existing
 *    `unique(occurrence_id)` index forward unchanged — there is no
 *    drop-and-recreate step to get wrong, unlike the MariaDB path where
 *    recreating either object was the thing to avoid for a different reason.
 *    This is the same fix `2026_09_08_120003`'s docblock describes for
 *    `tp_assessment_messages.assessment_id`: `change()` natively on SQLite,
 *    not a driver-specific raw statement that silently does nothing there.
 *    `Phase10SchemaTest::occurrence_id_is_nullable_and_keeps_its_foreign_key_and_unique_index`
 *    and its neighbouring duplicate-AAR test check the FK and the unique
 *    index on whichever database the suite runs against (MariaDB on this
 *    branch); `ReferenceCodeConcurrencyTest` proves the SQLite branch of this
 *    migration actually runs, by migrating a throwaway SQLite file as part of
 *    proving `generate()` is race-free. Neither asserts the SQLite PRAGMA
 *    output directly — that check (`PRAGMA index_list`, `PRAGMA
 *    foreign_key_list`, `PRAGMA table_info`) was done once by hand, on a
 *    throwaway file, on 2026-09-24, and is not repeated by any test.
 *
 *    Any other driver is refused with an exception rather than silently
 *    doing nothing — the precedent both
 *    `2026_09_08_120003_add_tprm_portal_collaboration_tables.php` (around
 *    its SQLite/`change()` note) and
 *    `2026_08_10_110008_make_control_test_status_driver_consistent.php`
 *    argue against: a migration that quietly no-ops on an untested driver
 *    ships a column whose nullability nothing enforced, and nothing says so.
 *
 * 2. `bcms_aars.incident_id` is added: nullable, unique, FK -> bcms_incidents,
 *    cascadeOnDelete. Exactly one of the two edges is enforced in
 *    `AarService`/`PirService` and in a `saving` guard on the `Aar` model
 *    (ADR 0020 §1) — never a CHECK constraint, because this repository has
 *    none anywhere and `bcms:verify-schema` compares columns, so a CHECK
 *    would be invisible to the freeze guard built to notice exactly this
 *    kind of change.
 *
 * 3. `bcms_incident_notifications` — one row per submission to a regulator
 *    (ADR 0020 §2). `bcms_incidents.reporting_due_at`, `.regulator_notified_at`
 *    and `.cbn_reference` are RETIRED IN PLACE: the columns stay (until a
 *    later cleanup migration drops them, per ADR 0019 §3's pattern), nothing
 *    writes them from this phase onward, and a guard test asserts no BCMS
 *    create()/update()/fill() names them.
 *
 *    Amendment 2 (2026-09-17) adds three columns to THIS creating migration,
 *    not a second one: at the date of the amendment this table is uncommitted
 *    and exists on no branch but `integration/bcms-remaining`, so widening the
 *    table it creates is still amending an undeployed migration, not editing
 *    a shipped one. `withdrawn_at`/`withdrawn_by`/`withdrawal_entry_id` let a
 *    reassessed-as-not-owed obligation close on its own row instead of
 *    staying open for ever — see `NotificationService::withdraw()`.
 *
 * 4. Amendment 4 (2026-09-23) adds ONE column to `bcms_plan_activations` — a
 *    Phase 0 table, altered under the same "still uncommitted" condition as
 *    Amendment 2's three columns. `kept_active_entry_id`, nullable, FK ->
 *    `bcms_incident_log`, nullOnDelete: when set, this activation was kept
 *    active at stand-down rather than deactivated, for the reason in that log
 *    entry. Every existing reader of this table defines "active" as
 *    `deactivated_at is null` and does not read this column, so no existing
 *    answer changes — see `IncidentService::standDown()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- 1 & 2: bcms_aars -------------------------------------------
        match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => DB::statement(
                'ALTER TABLE bcms_aars MODIFY occurrence_id BIGINT UNSIGNED NULL'
            ),
            'sqlite' => Schema::table('bcms_aars', function (Blueprint $table) {
                $table->unsignedBigInteger('occurrence_id')->nullable()->change();
            }),
            default => throw new \RuntimeException(
                'Unsupported database driver for bcms_aars.occurrence_id nullability change: '.DB::connection()->getDriverName()
            ),
        };

        Schema::table('bcms_aars', function (Blueprint $table) {
            $table->foreignId('incident_id')->nullable()->unique()
                ->after('occurrence_id')
                ->constrained('bcms_incidents')->cascadeOnDelete();
        });

        // --- 3: bcms_incident_notifications ------------------------------
        Schema::create('bcms_incident_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('incident_id')->constrained('bcms_incidents')->cascadeOnDelete();

            $table->string('regulator', 40); // cbn|ndpc|other
            $table->string('basis_clause_ref', 60);
            $table->string('kind', 20); // initial|intermediate|final|supplementary
            $table->unsignedSmallInteger('sequence')->default(1);

            // THE TRIGGER EVENT. Not created_at — an incident detected
            // yesterday and classified as a breach today owes its 72 hours
            // from yesterday, per ADR 0020 §2 point 1 and the clause map's
            // criterion-5 test.
            //
            // dateTime(), not timestamp(): this is the first NOT-NULL
            // TIMESTAMP column with no explicit DEFAULT in the table, and on
            // MariaDB (with explicit_defaults_for_timestamp off, the
            // production default) that column implicitly receives
            // `DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP()` —
            // so ANY later update() to the row (recording a submission,
            // editing the content_snapshot) silently overwrites the one
            // fact ADR 0020's 72-hour clock is computed from, with no
            // column named in the SET clause. DATETIME carries neither the
            // implicit-default magic nor TIMESTAMP's session-timezone
            // conversion; IncidentClock::utc() already normalises the value
            // the application writes, so no other behaviour depends on it.
            $table->dateTime('awareness_at');

            // Stored, and never recomputed on read (ADR 0020 §2 point 2): a
            // deadline is a fact fixed at the moment of awareness, and a
            // later correction to the configured window must not move a
            // historical deadline.
            $table->timestamp('due_at')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference', 120)->nullable();
            $table->json('content_snapshot')->nullable();

            // Amendment 2 — an obligation reassessed as not owed is withdrawn
            // on its own row, never re-derived from "no submission and no
            // later withdrawal row for the same regulator". Only an
            // UNSUBMITTED row may be withdrawn (NotificationService::withdraw()).
            // `nullOnDelete` on both FKs deliberately: deleting an incident
            // cascades to its log entries and its notifications as siblings,
            // and MariaDB does not promise an order, so a RESTRICT here could
            // fail that cascade depending on which sibling goes first.
            $table->timestamp('withdrawn_at')->nullable();
            $table->foreignId('withdrawn_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('withdrawal_entry_id')->nullable()
                ->constrained('bcms_incident_log')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Every index named explicitly and kept short: MariaDB's
            // identifier limit is 64 characters and Laravel's own
            // auto-generated name for the last index below
            // (`bcms_incident_notifications_organization_id_submitted_at_due_at_index`,
            // 71 characters) exceeds it — `migrate:fresh` fails outright and
            // every RefreshDatabase test goes red, not just this table's own.
            $table->unique(['incident_id', 'regulator', 'kind', 'sequence'], 'bcms_incident_notif_unique');
            $table->index(['organization_id', 'incident_id'], 'bcms_incident_notif_org_incident_idx');
            $table->index(['organization_id', 'submitted_at', 'due_at'], 'bcms_incident_notif_org_submitted_due_idx');
        });

        // --- 4: bcms_plan_activations (Amendment 4) ----------------------
        Schema::table('bcms_plan_activations', function (Blueprint $table) {
            $table->foreignId('kept_active_entry_id')->nullable()
                ->after('activation_reason')
                ->constrained('bcms_incident_log')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bcms_plan_activations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kept_active_entry_id');
        });

        Schema::dropIfExists('bcms_incident_notifications');

        Schema::table('bcms_aars', function (Blueprint $table) {
            // Not dropConstrainedForeignId(): incident_id carries both a FK
            // and a unique index, and on SQLite 3.35+ dropping the column
            // while the unique index still references it fails outright
            // ("error in index ... after drop column: no such column:
            // incident_id") — on 3.35+ Laravel issues a native `ALTER TABLE
            // ... DROP COLUMN` rather than a rebuild, and SQLite refuses it
            // while an index still names the column. The FK is dropped first because MariaDB refuses
            // to drop an index a FK still needs; only then can the index
            // itself go, and only then the column.
            $table->dropForeign(['incident_id']);
            $table->dropUnique(['incident_id']);
            $table->dropColumn('incident_id');
        });

        // occurrence_id is left nullable on rollback — restoring NOT NULL
        // against rows this migration may have allowed to be null (a PIR
        // created while this migration was applied) would fail the rollback
        // outright, and the down() path is a development convenience, not a
        // production rollback plan for a live PIR table.
    }
};
