<?php

use App\Support\Periods\DateBounds;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0027 — repair the breaches KriMeasureMigrator wrote with a resolution
 * time at the START of the period's last day.
 *
 * THE DEFECT. `Period::end_date` is a date cast, which Carbon holds as 00:00 on
 * that day. The migrator wrote it as `resolved_at`, so every breach reconstructed
 * from a notification sent on a month's last day "resolved" before it began
 * (-15 hours for a 15:00 notification), and every other reconstructed breach
 * said "resolved at 00:00 on the last day" where a fresh install now says
 * 23:59:59 that day. The migrator is fixed; the migration that ran it is
 * idempotent and will not rewrite what it already wrote, so this does.
 *
 * WHICH ROWS. Exactly those the old migrator wrote and nothing has touched
 * since — see ADR 0027 §2. Five predicates are filtered in SQL (note equals the
 * migrator's note, status resolved, never acknowledged, has a resolution time,
 * updated_at = created_at) and the sixth in PHP: the raw resolved_at string is
 * exactly "<end_date> 00:00:00". A row failing the sixth — which includes every
 * row if the server's time zone has moved since the backfill — is skipped and
 * counted, never mis-repaired. Because the sixth predicate no longer holds once
 * a row is repaired, running this twice changes nothing.
 *
 * HOW. Query builder only: one join, equality, whereNull, whereColumn, and one
 * UPDATE per id. No raw JSON, no UPDATE ... WHERE id IN (SELECT ... FROM
 * measure_breaches) (MySQL error 1093), and no model is saved, so no observer
 * fires inside the migration window. Time comparison is done in PHP on the
 * strings the session returned. updated_at and note are left alone: a fresh
 * backfill has updated_at = created_at, and equivalence with one is the
 * acceptance criterion.
 */
return new class extends Migration
{
    /**
     * A COPY of KriMeasureMigrator::RECONSTRUCTED_NOTE, on purpose. A migration
     * is a snapshot: a later edit to application code must not change which rows
     * it matches. A test asserts the two are equal.
     */
    public const NOTE = 'Reconstructed from the notification log during the WP-04 measure-engine migration. '
        .'The pre-WP-04 platform kept no breach register, so acknowledgement and resolution times '
        .'are not recoverable for this row.';

    public function up(): void
    {
        if (! Schema::hasTable('measure_breaches') || ! Schema::hasTable('periods')) {
            return;
        }

        $candidates = 0;
        $repaired = 0;
        $skippedNotPeriodMidnight = 0;

        DB::table('measure_breaches as b')
            ->join('periods as p', 'p.id', '=', 'b.period_id')
            ->where('b.note', self::NOTE)
            ->where('b.status', 'resolved')
            ->whereNull('b.acknowledged_at')
            ->whereNotNull('b.resolved_at')
            ->whereColumn('b.updated_at', '=', 'b.created_at')
            ->whereNotNull('p.end_date')
            ->select('b.id', 'b.breached_at', 'b.resolved_at', 'p.end_date')
            ->chunkById(500, function ($rows) use (&$candidates, &$repaired, &$skippedNotPeriodMidnight) {
                foreach ($rows as $row) {
                    $candidates++;

                    $endDate = substr((string) $row->end_date, 0, 10);

                    // Predicate 6: the row holds exactly what the old migrator wrote.
                    if ((string) $row->resolved_at !== $endDate.' 00:00:00') {
                        $skippedNotPeriodMidnight++;

                        continue;
                    }

                    // The value the corrected migrator writes: the end of the
                    // period's last day, never earlier than the breach. Both are
                    // Y-m-d H:i:s, so string order is chronological order.
                    $endOfPeriod = DateBounds::endOfDay($endDate);
                    $new = $endOfPeriod < (string) $row->breached_at ? (string) $row->breached_at : $endOfPeriod;

                    $repaired += DB::table('measure_breaches')
                        ->where('id', $row->id)
                        ->where('resolved_at', $row->resolved_at)
                        ->update(['resolved_at' => $new]);
                }
            }, 'b.id', 'id');

        $skippedTouched = DB::table('measure_breaches')
            ->where('note', self::NOTE)
            ->where('status', 'resolved')
            ->whereNull('acknowledged_at')
            ->whereNotNull('resolved_at')
            ->whereColumn('updated_at', '!=', 'created_at')
            ->count();

        $negativeRemaining = DB::table('measure_breaches')
            ->where('note', self::NOTE)
            ->whereNotNull('resolved_at')
            ->get(['breached_at', 'resolved_at'])
            ->filter(fn ($row) => (string) $row->resolved_at < (string) $row->breached_at)
            ->count();

        info('ADR 0027 repair: backfilled KRI breaches resolved at the start of the period\'s last day.', [
            'candidates' => $candidates,
            'repaired' => $repaired,
            'skipped_touched' => $skippedTouched,
            'skipped_not_period_midnight' => $skippedNotPeriodMidnight,
            'negative_remaining' => $negativeRemaining,
        ]);
    }

    /**
     * Deliberately a no-op. The values this would restore are the defect.
     */
    public function down(): void
    {
        //
    }
};
