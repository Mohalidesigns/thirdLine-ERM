<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * WP-04 repair — backdate KRI band sets to cover the history they govern, and
 * restore any reading whose band was blanked.
 *
 * THE DEFECT. 120004 dated a KRI's first band set from the KRI row's
 * created_at. Where a KRI's readings were loaded BEFORE the indicator record
 * was created — a migration, a data load, a seeded environment — every one of
 * those readings fell outside the band set's effective window, so no threshold
 * resolved for them. `kri:check-breaches` then re-banded those readings and
 * wrote the resulting NULL through, erasing the RAG colour the reading had
 * actually been reported in.
 *
 * Both halves are fixed at the source (KriMeasureMigrator::firstBandEffectiveFrom
 * and CheckKriBreaches, which no longer writes a null band through). This
 * migration repairs the rows that were already written.
 *
 * The restore is possible only because key_risk_indicators and kri_measurements
 * were kept as the facade rather than dropped in the same release — the legacy
 * status column is untouched and is the source of truth for what each reading
 * was reported as. That is the two-release deprecation rule earning its keep.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->backdateFirstBandSets();
        $this->restoreBlankedBands();
    }

    /**
     * Move each ORIGINAL band set's effective_from back to the earliest reading
     * it governs.
     *
     * Only rows with no supersedes_id — an original. A superseding row is dated
     * from the day the limit actually changed, and moving it would rewrite the
     * history that effective dating exists to preserve.
     */
    private function backdateFirstBandSets(): void
    {
        $rows = DB::table('measure_thresholds as t')
            ->join('measures as m', 'm.id', '=', 't.measure_id')
            ->join('key_risk_indicators as k', function ($join) {
                $join->on('k.kri_code', '=', 'm.code')
                    ->on('k.organization_id', '=', 'm.organization_id');
            })
            ->where('m.measure_kind', 'kri')
            ->whereNull('t.supersedes_id')
            ->select('t.id', 't.effective_from', 'k.id as kri_id')
            ->get();

        $moved = 0;

        foreach ($rows as $row) {
            $earliest = DB::table('kri_measurements')
                ->where('kri_id', $row->kri_id)
                ->min('measurement_date');

            if ($earliest === null) {
                continue;
            }

            $earliest = substr((string) $earliest, 0, 10);

            if ($earliest >= substr((string) $row->effective_from, 0, 10)) {
                continue;
            }

            DB::table('measure_thresholds')->where('id', $row->id)->update([
                'effective_from' => $earliest,
                'updated_at' => now(),
            ]);

            $moved++;
        }

        info('WP-04 repair: backdated KRI band sets.', ['thresholds' => $moved]);
    }

    /**
     * Put back the RAG band on any KRI reading that has none, from the legacy
     * measurement it came from.
     *
     * Restored from what the reading was REPORTED as, not recomputed against
     * today's bands — a reading keeps the colour it was published in, which is
     * the same rule 120004 applied when it wrote these rows the first time.
     */
    private function restoreBlankedBands(): void
    {
        $restored = 0;

        DB::table('measure_values as mv')
            ->join('measures as m', 'm.id', '=', 'mv.measure_id')
            ->join('key_risk_indicators as k', function ($join) {
                $join->on('k.kri_code', '=', 'm.code')
                    ->on('k.organization_id', '=', 'm.organization_id');
            })
            ->where('m.measure_kind', 'kri')
            ->whereNull('mv.rag_band')
            ->where('mv.scenario', 'actual')
            ->select('mv.id', 'mv.entered_at', 'k.id as kri_id')
            ->orderBy('mv.id')
            ->chunk(500, function ($values) use (&$restored) {
                foreach ($values as $value) {
                    if ($value->entered_at === null) {
                        continue;
                    }

                    $status = DB::table('kri_measurements')
                        ->where('kri_id', $value->kri_id)
                        ->whereDate('measurement_date', substr((string) $value->entered_at, 0, 10))
                        ->value('status');

                    if ($status === null || $status === '') {
                        continue;
                    }

                    DB::table('measure_values')->where('id', $value->id)->update([
                        'rag_band' => $status,
                        'updated_at' => now(),
                    ]);

                    $restored++;
                }
            });

        info('WP-04 repair: restored blanked KRI bands.', ['values' => $restored]);
    }

    public function down(): void
    {
        // Nothing to reverse. Widening an effective window and restoring a band
        // that should never have been cleared are both corrections; re-breaking
        // them on rollback would be the only way to "undo" this, which is not
        // something a rollback should do.
    }
};
