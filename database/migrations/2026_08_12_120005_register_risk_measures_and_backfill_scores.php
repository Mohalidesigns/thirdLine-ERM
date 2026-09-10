<?php

use App\Models\Organization;
use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Services\RiskMeasureRecorder;
use App\Support\Measures\MeasureCatalog;
use Illuminate\Database\Migrations\Migration;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * WP-04 TASK 4 — register the risk measures and give the register a history.
 *
 * Every approved assessment already carries the date it was made and the scores
 * it approved; until now that was the only place the number survived, because
 * approval overwrote the columns on `risks`. Replaying those assessments into
 * measure_values turns the assessment table into an actual time series without
 * inventing a single figure — every value written here came from an assessment
 * somebody approved.
 *
 * Assessments are replayed in assessment_date order so that where two land in
 * the same period, the later one wins, which is the same rule the live path
 * uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantContext::bypass(function () {
            $recorder = app(RiskMeasureRecorder::class);
            $written = 0;
            $assessments = 0;

            Organization::query()->orderBy('id')->each(function (Organization $organization) use ($recorder, &$written, &$assessments) {
                TenantContext::actingAs($organization->id, function () use ($organization, $recorder, &$written, &$assessments) {
                    MeasureCatalog::install($organization->id);

                    RiskAssessment::withoutGlobalScopes()
                        ->where('organization_id', $organization->id)
                        ->whereIn('status', ['approved', 'completed'])
                        ->whereNotNull('assessment_date')
                        ->orderBy('assessment_date')
                        ->orderBy('id')
                        ->chunkById(200, function ($chunk) use ($recorder, &$written, &$assessments) {
                            foreach ($chunk as $assessment) {
                                $risk = Risk::withoutGlobalScopes()->find($assessment->risk_id);

                                if ($risk === null) {
                                    continue;
                                }

                                $assessments++;
                                $written += $recorder->recordAssessment($assessment, $risk, [
                                    'source' => 'migration',
                                    // Backfilled history may land in a period an
                                    // administrator has already closed.
                                    'force' => true,
                                ]);
                            }
                        });
                });
            });

            info('WP-04: risk scores period-stamped from historic assessments.', [
                'assessments' => $assessments,
                'values' => $written,
            ]);
        }, 'WP-04 risk score backfill');
    }

    public function down(): void
    {
        // The values written here live in measure_values, dropped with the
        // table by 120002's rollback. Deleting risk-kind measures selectively
        // would take out anything recorded through the live path since.
    }
};
