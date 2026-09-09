<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `bcms_audit_logs.event` was too narrow for the events BCMS actually writes,
 * and the audit trail was losing rows in silence.
 *
 * The column was declared `string('event', 20)` with the comment
 * `created|updated|deleted|restored` — sized for the four events
 * `BcmsAuditable` writes from Eloquent's model hooks, by somebody who had not
 * yet seen the ones `recordAudit()` writes by hand. There are twenty-two of
 * those, and TWELVE do not fit:
 *
 *   contact.repaired_after_cascade  30    call_tree_test.aborted    22
 *   call_tree_test.unannounced      26    alert.dispatch_refused    22
 *   call_tree.hygiene_flagged       25    call_tree.superseded      20
 *   call_tree.deputy_assigned       25    call_tree.reparented      20
 *   call_tree_test.initiated        24    template.deactivated      20
 *   call_tree_test.completed        24    reminder_ladder_rebuilt   23
 *
 * On MariaDB, with Laravel's `strict => true` (STRICT_TRANS_TABLES), that
 * insert raises `SQLSTATE[22001] 1406 Data too long`. `writeBcmsAuditRow()`
 * catches Throwable on purpose — auditing must never fail the business write —
 * and logs the error. So the row simply did not appear, no exception reached
 * the caller, and nothing failed. SQLite enforces no VARCHAR length at all, so
 * the suite was green throughout.
 *
 * THE COMBINATION IS WHAT MADE IT INVISIBLE: a column too narrow, a deliberate
 * catch-all, and a test database that does not enforce widths. Any one of the
 * three alone would have surfaced it. This is a direct breach of Definition of
 * Done item 4 — "every state change writes an activity-log entry" — in the
 * module whose entire purpose is to be defensible at an examination.
 *
 * 60, matching `tp_audit_logs.event`, which is the same kind of column holding
 * the same kind of value and has never overflowed. Not 30: sizing a column to
 * its current longest value guarantees the next event name breaks it, and this
 * defect has already been paid for once.
 *
 * Widening is safe in both directions for existing rows — every value that fit
 * in 20 fits in 60 — so there is no data migration. The `down()` is honest
 * rather than symmetrical: see its own comment.
 *
 * A regression test (`BcmsAuditEventWidthTest`) now asserts that every event
 * name in the codebase fits the live column, so this cannot rot back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bcms_audit_logs', function (Blueprint $table) {
            $table->string('event', 60)->change();
        });
    }

    public function down(): void
    {
        // Narrowing back to 20 would TRUNCATE OR REJECT every row this fix made
        // writable — the twelve event names above are the reason the column
        // changed. Rolling back the schema without deleting those rows is not
        // possible on a strict server, so `down()` restores the declaration and
        // nothing else; if it fails because such rows exist, that failure is
        // correct and the operator should not be rolling this back.
        Schema::table('bcms_audit_logs', function (Blueprint $table) {
            $table->string('event', 20)->change();
        });
    }
};
