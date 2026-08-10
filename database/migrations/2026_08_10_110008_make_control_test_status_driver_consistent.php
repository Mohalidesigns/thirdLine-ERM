<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WP-01 TASK 4 — control_tests.status behaved differently per driver.
 *
 * 2026_04_23_110000 added 'rejected' with an `ALTER TABLE ... MODIFY COLUMN
 * ENUM(...)` guarded on `DB::getDriverName() === 'mysql'`. So on MySQL the
 * column rejects an unknown status at the database, and on SQLite — which the
 * test suite runs on — it accepts anything. The rejection workflow was
 * therefore exercised in CI against a column that could not have enforced it,
 * and a typo'd status would pass every test and fail in production.
 *
 * Converting the column to a plain string and enforcing the values in the
 * application (App\Enums\ControlTestStatus, cast on the model) makes every
 * driver behave identically, and makes adding a status a code change rather
 * than an ALTER TABLE on a large table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('control_tests') || ! Schema::hasColumn('control_tests', 'status')) {
            return;
        }

        // MySQL stores enums as integers under the hood; going through a plain
        // string conversion keeps the labels rather than the ordinals.
        Schema::table('control_tests', function (Blueprint $table) {
            $table->string('status', 30)->default('scheduled')->change();
        });

        // Anything the enum could not have held is already impossible, but a
        // SQLite instance may have accepted a value the enum would have
        // refused. Normalise those to 'scheduled' rather than leaving a status
        // the application cannot interpret.
        $known = ['scheduled', 'in_progress', 'pending_review', 'completed', 'rejected', 'cancelled'];

        $unknown = DB::table('control_tests')->whereNotIn('status', $known)->count();

        if ($unknown > 0) {
            logger()->warning('Normalised unknown control test statuses to "scheduled"', ['rows' => $unknown]);
            DB::table('control_tests')->whereNotIn('status', $known)->update(['status' => 'scheduled']);
        }
    }

    public function down(): void
    {
        // Deliberately not restoring the enum: it is the inconsistency this
        // migration exists to remove, and a string column accepts every value
        // the enum did.
    }
};
