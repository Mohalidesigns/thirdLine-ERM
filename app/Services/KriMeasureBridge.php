<?php

namespace App\Services;

use App\Models\KeyRiskIndicator;
use App\Models\Measure;
use App\Models\MeasureBreach;
use App\Models\MeasureThreshold;
use App\Models\MeasureValue;
use App\Models\ObjectType;
use App\Models\Period;
use App\Support\Measures\KriMeasureMigrator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The seam between the KRI screens and the measure engine.
 *
 * WP-04 TASK 3 keeps key_risk_indicators alive for one release as the facade
 * the existing views read, while every reading and every threshold now lives in
 * the measure engine. This class is where both are kept in step:
 *
 *   - recordMeasurement() writes the measure_value (source of truth), then
 *     mirrors the reading onto key_risk_indicators.current_value /
 *     current_status and into kri_measurements so nothing that reads the old
 *     tables breaks mid-release;
 *   - syncDefinition() re-derives the measure and its bands whenever a KRI is
 *     created or edited.
 *
 * When key_risk_indicators is dropped in the release after next, this class
 * loses its mirroring half and nothing else changes.
 */
class KriMeasureBridge
{
    public function __construct(
        private MeasureService $measures,
        private PeriodService $periods,
        private KriMeasureMigrator $migrator,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Definition */
    /* ------------------------------------------------------------------ */

    /**
     * Create or refresh the measure and bands behind a KRI.
     */
    public function syncDefinition(KeyRiskIndicator $kri): Measure
    {
        $organizationId = (int) $kri->organization_id;

        $objectTypeId = ObjectType::withoutGlobalScopes()
            ->where('code', 'KeyRiskIndicator')
            ->orderByRaw('CASE WHEN organization_id IS NULL THEN 1 ELSE 0 END')
            ->value('id');

        $measure = $this->migrator->measureFor($kri, $organizationId, $objectTypeId);

        $this->syncThreshold($kri, $measure);

        return $measure;
    }

    /**
     * Bring the band set in line with the KRI's threshold columns.
     *
     * An edit that actually changes a limit writes a NEW effective-dated row
     * and closes the old one — the same rule re-baselining follows, and for the
     * same reason: a breach recorded last month has to keep reading against the
     * limit that was in force last month. An edit that leaves the numbers alone
     * writes nothing.
     */
    public function syncThreshold(KeyRiskIndicator $kri, Measure $measure): ?MeasureThreshold
    {
        $bands = $this->migrator->bandsFor($kri);
        $objectId = $this->measures->objectIdFor($kri);

        if ($objectId === null) {
            return null;
        }

        $current = MeasureThreshold::withoutGlobalScopes()
            ->where('measure_id', $measure->id)
            ->where('object_id', $objectId)
            ->whereNull('effective_to')
            ->orderByDesc('effective_from')
            ->first();

        if ($bands === []) {
            return $current;
        }

        $direction = $this->migrator->directionOf($kri);

        if ($current !== null
            && $this->bandsAreEquivalent($current->bands ?? [], $bands)
            && $current->direction === $direction) {
            return $current;
        }

        return DB::transaction(function () use ($current, $kri, $measure, $objectId, $bands, $direction) {
            // The FIRST band set has been in force since the indicator existed,
            // or since its earliest reading if that came first — dating it from
            // today would leave every reading taken before the definition was
            // synced unbanded. A LATER set is a change of limit and takes effect
            // from the day it is made.
            $today = $current === null
                ? CarbonImmutable::parse($this->migrator->firstBandEffectiveFrom($kri))->startOfDay()
                : CarbonImmutable::now();

            $replacement = MeasureThreshold::withoutGlobalScopes()->create([
                'organization_id' => $measure->organization_id,
                'measure_id' => $measure->id,
                'object_id' => $objectId,
                'effective_from' => $today->toDateString(),
                'effective_to' => null,
                'bands' => $bands,
                'direction' => $direction,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
                'supersedes_id' => $current?->id,
            ]);

            if ($current !== null) {
                // Same-day edits would otherwise leave a zero-length window
                // that effectiveOn() resolves ambiguously; closing the old row
                // the day before keeps exactly one band set live per date, with
                // the newer row winning on the day of the change.
                $current->forceFill([
                    'effective_to' => $today->subDay()->toDateString(),
                ])->save();
            }

            return $replacement;
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $left
     * @param  array<int, array<string, mixed>>  $right
     */
    private function bandsAreEquivalent(array $left, array $right): bool
    {
        $shape = fn (array $bands) => collect($bands)
            ->map(fn (array $band) => [
                $band['code'] ?? null,
                isset($band['min']) ? (float) $band['min'] : null,
                isset($band['max']) ? (float) $band['max'] : null,
                $band['min_formula'] ?? null,
                $band['max_formula'] ?? null,
            ])
            ->values()
            ->all();

        return $shape($left) === $shape($right);
    }

    /* ------------------------------------------------------------------ */
    /*  Readings */
    /* ------------------------------------------------------------------ */

    /**
     * Record a KRI reading through the measure engine.
     *
     * @param  array{notes?:?string, entered_by?:?int, source?:string, evidence_ref?:?string}  $options
     * @return array{value: MeasureValue, band: ?string, breach: ?MeasureBreach, period: Period}
     */
    public function recordMeasurement(
        KeyRiskIndicator $kri,
        string|CarbonImmutable $measurementDate,
        float $value,
        array $options = []
    ): array {
        $organizationId = (int) $kri->organization_id;
        $measure = $this->measureFor($kri) ?? $this->syncDefinition($kri);

        $date = $measurementDate instanceof CarbonImmutable
            ? $measurementDate
            : CarbonImmutable::parse($measurementDate);

        $period = $this->periods->resolve($date, $this->periodTypeFor($kri), $organizationId);

        $measureValue = $this->measures->record($measure, $kri, $period, $value, [
            'organization_id' => $organizationId,
            'scenario' => 'actual',
            'entered_by' => $options['entered_by'] ?? auth()->id(),
            'entered_at' => $date->toDateTimeString(),
            'source' => $options['source'] ?? 'manual',
            'evidence_ref' => $options['evidence_ref'] ?? null,
            'note' => $options['notes'] ?? null,
            // Reconciled below instead of inside record(), so the legacy
            // mirror lands first — see the comment on the mirror call.
            'detect_breach' => false,
        ]);

        // The mirror runs BEFORE the breach is reconciled, which is the one
        // ordering constraint in this method. reconcileBreach() raises
        // KriBreachDetected on a newly opened breach, and that event is typed
        // on KriMeasurement — a row that only exists because of this mirror.
        // Reconciling first would fire the breach for a KRI's first-ever
        // reading naming the reading before it, or naming nothing at all.
        $this->mirrorOntoLegacyTables($kri, $date, $value, $measureValue->rag_band, $options);

        $this->measures->reconcileBreach($measure, (int) $measureValue->object_id, $period, $measureValue);

        $breach = MeasureBreach::withoutGlobalScopes()
            ->where('measure_id', $measure->id)
            ->where('object_id', $measureValue->object_id)
            ->where('period_id', $period->id)
            ->whereIn('status', ['open', 'acknowledged'])
            ->orderByDesc('breached_at')
            ->first();

        return [
            'value' => $measureValue,
            'band' => $measureValue->rag_band,
            'breach' => $breach,
            'period' => $period,
        ];
    }

    /**
     * Keep key_risk_indicators and kri_measurements in step for one release.
     *
     * The measure engine is the source of truth from this release; these two
     * writes exist only so that a screen, an export or a report that has not
     * been moved across yet keeps working. Both go away with the facade.
     */
    private function mirrorOntoLegacyTables(
        KeyRiskIndicator $kri,
        CarbonImmutable $date,
        float $value,
        ?string $band,
        array $options
    ): void {
        $enteredBy = $options['entered_by'] ?? auth()->id();

        // kri_measurements.entered_by is NOT NULL with a foreign key to users,
        // so an automated reading has no legal row to write there. The measure
        // engine holds it either way; the legacy mirror is skipped rather than
        // attributed to a user who did not enter it.
        if ($enteredBy !== null) {
            $key = ['kri_id' => $kri->id, 'measurement_date' => $date->toDateString()];
            $attributes = [
                'value' => $value,
                'status' => $band ?? 'green',
                'entered_by' => $enteredBy,
                'notes' => $options['notes'] ?? null,
                'updated_at' => now(),
            ];

            if (DB::table('kri_measurements')->where($key)->exists()) {
                DB::table('kri_measurements')->where($key)->update($attributes);
            } else {
                DB::table('kri_measurements')->insert($key + $attributes + ['created_at' => now()]);
            }
        }

        // Only a reading at or after the last one may move the current value —
        // entering a correction for an old month must not make the dashboard
        // show a stale number as current.
        $lastMeasured = $kri->last_measurement_date ?? $kri->last_measurement_at;

        if ($lastMeasured !== null && CarbonImmutable::parse($lastMeasured)->gt($date)) {
            return;
        }

        $kri->forceFill([
            'current_value' => $value,
            'current_status' => $band ?? $kri->current_status,
            'last_measurement_at' => $date->toDateTimeString(),
            'last_measurement_date' => $date->toDateString(),
        ])->save();
    }

    /* ------------------------------------------------------------------ */
    /*  Reading back */
    /* ------------------------------------------------------------------ */

    public function measureFor(KeyRiskIndicator $kri): ?Measure
    {
        return Measure::withoutGlobalScopes()
            ->where('organization_id', $kri->organization_id)
            ->where('code', $kri->kri_code)
            ->first();
    }

    /**
     * A KRI's readings across periods, as [periodId => value].
     *
     * @param  list<int>  $periodIds
     * @return array<int, float>
     */
    public function series(KeyRiskIndicator $kri, array $periodIds): array
    {
        $measure = $this->measureFor($kri);

        return $measure === null ? [] : $this->measures->series($measure, $kri, $periodIds);
    }

    /**
     * The reading for a KRI in a period, from the engine.
     */
    public function valueIn(KeyRiskIndicator $kri, Period $period): ?MeasureValue
    {
        $measure = $this->measureFor($kri);

        return $measure === null ? null : $this->measures->valueOf($measure, $kri, $period);
    }

    public function periodTypeFor(KeyRiskIndicator $kri): string
    {
        return match (strtolower((string) $kri->measurement_frequency)) {
            'quarterly' => 'quarter',
            'semi_annually' => 'half',
            'annually', 'yearly' => 'year',
            default => 'month',
        };
    }

    /**
     * Every KRI in the current tenant that has a measure behind it.
     *
     * @return \Illuminate\Support\Collection<int, KeyRiskIndicator>
     */
    public function activeKris(?int $organizationId = null)
    {
        return KeyRiskIndicator::withoutGlobalScopes()
            ->where('organization_id', $organizationId ?? TenantContext::organizationId())
            ->where(fn ($query) => $query->where('is_active', true)->orWhereNull('is_active'))
            ->whereNull('deleted_at')
            ->orderBy('kri_code')
            ->get();
    }
}
