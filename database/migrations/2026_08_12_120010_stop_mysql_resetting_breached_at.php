<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * measure_breaches.breached_at must not be reset by MySQL on every update.
 *
 * THE DEFECT. With `explicit_defaults_for_timestamp = OFF` — the default on
 * MariaDB and on plenty of MySQL installs — the first NOT NULL TIMESTAMP column
 * in a table is silently given
 *
 *     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
 *
 * `breached_at` was that column. So every later write to a breach row — the
 * nightly re-read refreshing the value, an acknowledgement, a root cause —
 * moved the moment the breach began to "now". Days-in-breach would always read
 * near zero and mean-time-to-resolve would measure the gap between the last
 * edit and the resolution instead of the length of the breach. The register
 * would look healthy precisely because it was being edited.
 *
 * THE FIX. DATETIME instead of TIMESTAMP. It carries no automatic
 * initialisation or update behaviour under any server setting, so the column
 * means only what the application puts in it. 120002 now creates it this way;
 * this migration converts a database that already has the TIMESTAMP form.
 *
 * SQLite has no such rule and stores both as text, so the whole class of bug is
 * invisible to the test suite. MeasureBreachTimestampTest asserts the column's
 * behaviour directly and skips on any driver that cannot express the problem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('measure_breaches', function (Blueprint $table) {
            $table->dateTime('breached_at')->change();
        });

        $this->repairResetTimestamps();
    }

    /**
     * Put back the breach start times the ON UPDATE clause already overwrote.
     *
     * The canonical value is the reading's own `entered_at` — the same
     * expression MeasureService::reconcileBreach uses when it opens a breach.
     * Restricted to breaches that have never been acknowledged or resolved: a
     * row with lifecycle history may have been legitimately re-opened, and
     * breached_at is then the reopen time, not the reading's.
     */
    private function repairResetTimestamps(): void
    {
        $candidates = DB::table('measure_breaches as b')
            ->join('measure_values as mv', function ($join) {
                $join->on('mv.measure_id', '=', 'b.measure_id')
                    ->on('mv.object_id', '=', 'b.object_id')
                    ->on('mv.period_id', '=', 'b.period_id');
            })
            ->where('mv.scenario', 'actual')
            ->whereNull('b.acknowledged_at')
            ->whereNull('b.resolved_at')
            ->whereNotNull('mv.entered_at')
            ->whereColumn('b.breached_at', '!=', 'mv.entered_at')
            ->select('b.id', 'mv.entered_at')
            ->get();

        foreach ($candidates as $row) {
            DB::table('measure_breaches')->where('id', $row->id)->update([
                'breached_at' => $row->entered_at,
            ]);
        }

        info('WP-04 repair: restored breach start times overwritten by ON UPDATE CURRENT_TIMESTAMP.', [
            'breaches' => $candidates->count(),
        ]);
    }

    public function down(): void
    {
        // Reverting would reinstate a column that silently rewrites its own
        // value, and would not recover the timestamps this migration restored.
    }
};
