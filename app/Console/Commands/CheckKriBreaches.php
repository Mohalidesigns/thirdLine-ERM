<?php

namespace App\Console\Commands;

use App\Events\KriBreachDetected;
use App\Models\KeyRiskIndicator;
use App\Models\KriMeasurement;
use App\Models\MeasureBreach;
use App\Models\Organization;
use App\Models\Period;
use App\Services\KriMeasureBridge;
use App\Services\MeasureService;
use App\Services\PeriodService;
use App\Support\Periods\PeriodContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Re-checks every KRI's latest reading against its bands and maintains the
 * breach register.
 *
 * WHAT THIS COMMAND USED TO DO: nothing. It queried
 * key_risk_indicators.status, .red_threshold and .threshold_comparison —
 * three columns that do not exist on that table and never have. The first
 * query threw, so no breach has ever been detected by the nightly run; the
 * only breach signal the platform produced came from the controller path
 * updating current_status when somebody typed a reading in by hand.
 *
 * WHAT IT DOES NOW: reads through the measure engine, so a breach becomes a row
 * in measure_breaches with a lifecycle — open, acknowledged, resolved — which
 * is what makes "how many limits are we over" and "how long do we take to clear
 * a red" answerable at all. The KriBreachDetected event still fires on a NEWLY
 * opened breach, so the existing escalation and notification listeners keep
 * working; it does not fire again for a breach that is merely still open,
 * which is what turned the notification bell into noise.
 */
class CheckKriBreaches extends Command
{
    protected $signature = 'kri:check-breaches
                            {--organization= : Limit the run to one organization id}
                            {--period= : Period code to check, defaults to the current one per KRI}';

    protected $description = 'Re-band the latest KRI readings and maintain the breach register';

    public function handle(
        KriMeasureBridge $bridge,
        MeasureService $measures,
        PeriodService $periods
    ): int {
        $only = $this->option('organization');

        $organizationIds = TenantContext::bypass(
            fn () => Organization::query()
                ->when($only !== null, fn ($query) => $query->whereKey($only))
                ->orderBy('id')
                ->pluck('id')
                ->all(),
            'kri:check-breaches organization sweep'
        );

        $checked = 0;
        $opened = 0;
        $resolved = 0;
        $skipped = 0;
        $unbanded = 0;

        foreach ($organizationIds as $organizationId) {
            TenantContext::actingAs((int) $organizationId, function () use (
                $bridge, $measures, $periods, $organizationId, &$checked, &$opened, &$resolved, &$skipped, &$unbanded
            ) {
                foreach ($bridge->activeKris((int) $organizationId) as $kri) {
                    $measure = $bridge->measureFor($kri);

                    if ($measure === null) {
                        // A KRI created before the migration, or one whose code
                        // was changed without the bridge running. Bring it into
                        // the engine rather than skipping it forever.
                        $measure = $bridge->syncDefinition($kri);
                    }

                    $period = $this->periodFor($kri, $bridge, $periods, (int) $organizationId);

                    if ($period === null) {
                        $skipped++;

                        continue;
                    }

                    $value = $measures->valueOf($measure, $kri, $period);

                    if ($value === null) {
                        $skipped++;

                        continue;
                    }

                    $checked++;

                    // Re-band before reconciling: the thresholds may have been
                    // re-baselined since the reading was entered, and a breach
                    // register that still reflects a superseded limit is worse
                    // than no register.
                    //
                    // A NULL result means no threshold was in force on that
                    // date, NOT that the reading is unbanded. Writing the null
                    // through would erase the colour the reading was actually
                    // reported in — which is destroying history to record the
                    // absence of a rule. Leave the stored band alone and say so.
                    $band = $measures->bandCodeFor($measure, (int) $value->object_id, (float) $value->value, $period);

                    if ($band === null) {
                        // Distinguish the two ways a band can fail to resolve.
                        // A measure with no threshold rows at all has no limits
                        // configured, which is a legitimate state and would be
                        // pure noise to warn about every night. A measure that
                        // HAS band sets, none of which covers the reading's
                        // date, is a misconfigured effective window — and a
                        // limit that silently does not apply is the failure
                        // mode this whole register exists to make visible.
                        $hasBands = \App\Models\MeasureThreshold::withoutGlobalScopes()
                            ->where('measure_id', $measure->id)
                            ->exists();

                        if ($hasBands) {
                            $unbanded++;
                            $this->line("  {$kri->kri_code}: no band in force on {$period->name}; reading left as recorded");
                        }

                        continue;
                    }

                    if ($band !== $value->rag_band) {
                        $value->forceFill(['rag_band' => $band])->save();
                    }

                    $before = MeasureBreach::withoutGlobalScopes()
                        ->where('measure_id', $measure->id)
                        ->where('object_id', $value->object_id)
                        ->where('period_id', $period->id)
                        ->whereIn('status', ['open', 'acknowledged'])
                        ->count();

                    $breach = $measures->reconcileBreach($measure, (int) $value->object_id, $period, $value);

                    if ($breach !== null && $breach->wasRecentlyCreated) {
                        $opened++;
                        $this->line("  {$kri->kri_code} breached {$breach->band_to} at ".rtrim(rtrim((string) $value->value, '0'), '.'));
                        $this->dispatchLegacyEvent($kri, $breach->band_to);
                    }

                    if ($breach === null && $before > 0) {
                        $resolved++;
                        $this->line("  {$kri->kri_code} returned within limits");
                    }
                }
            });
        }

        $this->info("Checked {$checked} KRI reading(s): {$opened} new breach(es), {$resolved} resolved, {$skipped} without a reading for the period.");

        if ($unbanded > 0) {
            // Not a failure, but not silence either: an indicator with no
            // threshold in force on the reading's date cannot breach, and a
            // register that quietly reports zero breaches for it is indis-
            // tinguishable from one that reports it is within appetite.
            $this->warn("{$unbanded} reading(s) had no threshold in force on their date and were left unbanded. Check the effective dates on those measures' bands.");
        }

        return self::SUCCESS;
    }

    /**
     * The period to check a KRI in: the one named on the command line, or the
     * most recent period of the KRI's own frequency that has a reading.
     *
     * Using "the current period" alone would report nothing for a quarterly
     * indicator on the first day of a quarter, which is exactly when a breach
     * carried over from the last one still matters.
     */
    private function periodFor(
        KeyRiskIndicator $kri,
        KriMeasureBridge $bridge,
        PeriodService $periods,
        int $organizationId
    ): ?Period {
        $code = $this->option('period');

        if ($code !== null) {
            return Period::withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->where('code', $code)
                ->first();
        }

        $measure = $bridge->measureFor($kri);

        if ($measure === null) {
            return null;
        }

        $periodId = \App\Models\MeasureValue::withoutGlobalScopes()
            ->where('measure_id', $measure->id)
            ->where('scenario', 'actual')
            ->join('periods', 'periods.id', '=', 'measure_values.period_id')
            ->orderByDesc('periods.end_date')
            ->value('measure_values.period_id');

        if ($periodId !== null) {
            return Period::withoutGlobalScopes()->find($periodId);
        }

        return PeriodContext::current()
            ?? $periods->current($bridge->periodTypeFor($kri), $organizationId);
    }

    /**
     * Fire the pre-WP-04 event so EscalateRiskOnKriBreach and SendNotification
     * keep working.
     *
     * Only for a newly opened breach. The old command re-dispatched every night
     * for as long as a reading stayed over its limit, which is why breach
     * notifications were something people learned to ignore.
     */
    private function dispatchLegacyEvent(KeyRiskIndicator $kri, string $band): void
    {
        $measurement = KriMeasurement::where('kri_id', $kri->id)
            ->latest('measurement_date')
            ->first();

        if ($measurement === null) {
            return;
        }

        KriBreachDetected::dispatch($kri, $measurement, $band);
    }
}
