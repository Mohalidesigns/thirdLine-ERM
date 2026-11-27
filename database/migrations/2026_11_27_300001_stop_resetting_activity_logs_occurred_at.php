<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * activity_logs.occurred_at must be DATETIME, not TIMESTAMP (ADR 0022 follow-up).
 *
 * Not ADR 0028 work: it rides with that branch only because NoSelfUpdatingTimestampColumnsTest
 * failed on main and blocked a green run. It is a structural change to a table ADR 0028 does
 * not touch, named here so it does not arrive silently.
 *
 * THE DEFECT. 2026_11_15_100001 created the column as a bare NOT NULL `timestamp()`. With
 * `explicit_defaults_for_timestamp = OFF` — MariaDB 10.4's default, and the bank's estate —
 * the first such column in a table is silently given
 * `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`, so any write to a row would move
 * the moment the audited action happened to "now". MySQL 8 (production) defaults the setting
 * ON, so there the column never had the clause and this is a shape change only.
 *
 * WHY A FORWARD MIGRATION AND NOT AN EDIT TO 100001. ADR 0022 Decision 1: no committed
 * migration is edited — an edit reaches fresh databases only. This converts every
 * environment, fresh or not, to the same shape.
 *
 * NO REPAIR. Unlike measure_breaches (2026_08_12_120010), activity-log rows are append-only:
 * nothing updates them, so the ON UPDATE clause never fired and no stored value moved.
 *
 * IDEMPOTENT AND DRIVER-GUARDED, after 2026_09_23_120001: only MySQL/MariaDB have the
 * TIMESTAMP auto-update behaviour, and a column that is already DATETIME is left alone.
 *
 * DEPLOY NOTE. On MySQL 8 a TIMESTAMP→DATETIME change rebuilds the table, blocking writes to
 * activity_logs for its duration. The table is append-only and small today; run it in the
 * normal deploy window.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        if (! Schema::hasColumn('activity_logs', 'occurred_at')) {
            return;
        }

        if (strtolower(Schema::getColumnType('activity_logs', 'occurred_at')) === 'datetime') {
            return;
        }

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dateTime('occurred_at')->change();
        });
    }

    public function down(): void
    {
        // Reverting would reinstate a column that can silently rewrite its own value.
    }
};
