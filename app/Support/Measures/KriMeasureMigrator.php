<?php

namespace App\Support\Measures;

use App\Models\KeyRiskIndicator;
use App\Models\Measure;
use App\Models\MeasureBreach;
use App\Models\MeasureThreshold;
use App\Models\MeasureValue;
use App\Models\ObjectType;
use App\Models\Period;
use App\Models\Unit;
use App\Services\MeasureService;
use App\Services\PeriodService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * WP-04 TASK 3 — moves KRIs onto the measure engine.
 *
 * A KRI is a measure with thresholds. It was modelled as its own table with its
 * own six threshold columns, its own measurement table and its own status
 * ladder, none of which the rest of the platform could reuse: a control
 * effectiveness percentage and a capital ratio need exactly the same machinery
 * and got none of it.
 *
 * key_risk_indicators is NOT dropped. It stays for one release as the facade
 * the existing screens read (rule 1: stop writing and backfill, then drop in
 * the release after), with KriController writing through the engine and the
 * legacy columns maintained as denormalised current values.
 *
 * Idempotent throughout, so it can run as a migration and again as a seeder
 * after the fact without duplicating a single value.
 */
class KriMeasureMigrator
{
    /** What a KRI's measurement_frequency means in period terms. */
    private const FREQUENCY_TO_PERIOD_TYPE = [
        'daily' => 'month',
        'weekly' => 'month',
        'monthly' => 'month',
        'quarterly' => 'quarter',
        'semi_annually' => 'half',
        'annually' => 'year',
        'yearly' => 'year',
    ];

    /**
     * Free-text unit_of_measure values seen in the live data, mapped onto the
     * unit registry. Anything unrecognised leaves unit_id NULL rather than
     * guessing — a wrong unit on a threshold is worse than no unit.
     */
    private const UNIT_ALIASES = [
        '%' => 'pct',
        'percent' => 'pct',
        'percentage' => 'pct',
        'pct' => 'pct',
        'bps' => 'bps',
        'basis points' => 'bps',
        '₦' => 'NGN',
        'ngn' => 'NGN',
        'naira' => 'NGN',
        'usd' => 'USD',
        '$' => 'USD',
        'count' => 'count',
        'number' => 'count',
        'no.' => 'count',
        'events' => 'events',
        'incidents' => 'incidents',
        'days' => 'days',
        'hours' => 'hours',
        'ratio' => 'ratio',
        'score' => 'score',
        'index' => 'index',
    ];

    public function __construct(
        private PeriodService $periods,
        private MeasureService $measures,
    ) {}

    /**
     * Migrate every KRI in every organisation.
     *
     * @return array{measures:int, thresholds:int, values:int, breaches:int}
     */
    public function migrateAll(): array
    {
        $totals = ['measures' => 0, 'thresholds' => 0, 'values' => 0, 'breaches' => 0];

        TenantContext::bypass(function () use (&$totals) {
            $organizationIds = KeyRiskIndicator::withoutGlobalScopes()
                ->distinct()
                ->orderBy('organization_id')
                ->pluck('organization_id')
                ->filter()
                ->all();

            foreach ($organizationIds as $organizationId) {
                $result = TenantContext::actingAs(
                    (int) $organizationId,
                    fn () => $this->migrateOrganization((int) $organizationId)
                );

                foreach ($result as $key => $count) {
                    $totals[$key] += $count;
                }
            }
        }, 'WP-04 KRI to measure engine migration');

        return $totals;
    }

    /**
     * @return array{measures:int, thresholds:int, values:int, breaches:int}
     */
    public function migrateOrganization(int $organizationId): array
    {
        $counts = ['measures' => 0, 'thresholds' => 0, 'values' => 0, 'breaches' => 0];

        $this->periods->ensureCalendar($organizationId);

        $objectTypeId = ObjectType::withoutGlobalScopes()
            ->where('code', 'KeyRiskIndicator')
            ->orderByRaw('CASE WHEN organization_id IS NULL THEN 1 ELSE 0 END')
            ->value('id');

        KeyRiskIndicator::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->orderBy('id')
            ->chunkById(200, function ($kris) use (&$counts, $organizationId, $objectTypeId) {
                foreach ($kris as $kri) {
                    $measure = $this->measureFor($kri, $organizationId, $objectTypeId);
                    $counts['measures']++;

                    if ($this->thresholdFor($kri, $measure) !== null) {
                        $counts['thresholds']++;
                    }

                    $counts['values'] += $this->measurementsFor($kri, $measure, $organizationId);
                }
            });

        $counts['breaches'] = $this->backfillBreachesFromNotifications($organizationId);

        return $counts;
    }

    /* ------------------------------------------------------------------ */
    /*  The measure */
    /* ------------------------------------------------------------------ */

    public function measureFor(KeyRiskIndicator $kri, int $organizationId, ?int $objectTypeId): Measure
    {
        $direction = $this->directionOf($kri);

        return Measure::withoutGlobalScopes()->updateOrCreate(
            ['organization_id' => $organizationId, 'code' => $kri->kri_code],
            [
                'name' => $kri->kri_name ?: $kri->name,
                'description' => $kri->description,
                'object_type_id' => $objectTypeId,
                'measure_kind' => 'kri',
                'unit_id' => $this->unitIdFor($kri),
                // An indicator reading is a level, not a total: the value for a
                // quarter is the quarter's reading, not the sum of its months.
                'aggregation' => 'last',
                'polarity' => $direction === 'higher_worse' ? 'lower_better' : 'higher_better',
                'decimal_places' => 2,
                'formula' => $kri->metric_formula ?: null,
                'is_derived' => false,
                'source' => $kri->is_automated ? 'api' : 'manual',
                'frequency' => $kri->measurement_frequency,
                'owner_id' => $kri->owner_id ?? $kri->kri_owner_id,
                'is_active' => (bool) ($kri->is_active ?? true),
            ]
        );
    }

    /**
     * The KRI's own graph object — the thing values are recorded against.
     */
    public function objectIdFor(KeyRiskIndicator $kri): ?int
    {
        return $this->measures->objectIdFor($kri);
    }

    /* ------------------------------------------------------------------ */
    /*  The threshold */
    /* ------------------------------------------------------------------ */

    /**
     * Build the effective-dated band set from the six legacy threshold columns.
     *
     * Skipped entirely when the KRI has no thresholds configured — a measure
     * with no bands simply has no RAG, which is honest, where inventing bands
     * would put readings into colours nobody set.
     */
    public function thresholdFor(KeyRiskIndicator $kri, Measure $measure): ?MeasureThreshold
    {
        $bands = $this->bandsFor($kri);

        if ($bands === []) {
            return null;
        }

        $objectId = $this->objectIdFor($kri);

        if ($objectId === null) {
            return null;
        }

        $existing = MeasureThreshold::withoutGlobalScopes()
            ->where('measure_id', $measure->id)
            ->where('object_id', $objectId)
            ->whereNull('effective_to')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return MeasureThreshold::withoutGlobalScopes()->create([
            'organization_id' => $measure->organization_id,
            'measure_id' => $measure->id,
            'object_id' => $objectId,
            'effective_from' => $this->firstBandEffectiveFrom($kri),
            'effective_to' => null,
            'bands' => $bands,
            'direction' => $this->directionOf($kri),
            'approved_by' => $kri->created_by,
            'approved_at' => $kri->created_at,
        ]);
    }

    /**
     * The date a KRI's FIRST band set takes effect.
     *
     * The earlier of the KRI row's creation and its earliest reading. A KRI
     * whose history was imported before the indicator record was created — the
     * normal shape for a migration, a data load, or a seeded environment — has
     * readings that PREDATE created_at. Dating the bands from created_at leaves
     * all of that history unbanded, which is not "we had no limit then": we did,
     * it is the limit on the row.
     */
    public function firstBandEffectiveFrom(KeyRiskIndicator $kri): string
    {
        $created = CarbonImmutable::parse($kri->created_at ?? now());

        $earliestReading = DB::table('kri_measurements')
            ->where('kri_id', $kri->id)
            ->min('measurement_date');

        if ($earliestReading === null) {
            return $created->toDateString();
        }

        return CarbonImmutable::parse($earliestReading)->min($created)->toDateString();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function bandsFor(KeyRiskIndicator $kri): array
    {
        $direction = $this->directionOf($kri);

        $green = ['code' => 'green', 'label' => 'Within appetite', 'color' => '#16A34A'];
        $amber = ['code' => 'amber', 'label' => 'Approaching limit', 'color' => '#F59E0B'];
        $red = ['code' => 'red', 'label' => 'Breach', 'color' => '#DC2626'];

        if ($direction === 'higher_worse') {
            $green += ['min' => null, 'max' => $this->num($kri->green_threshold_max)];
            $amber += ['min' => $this->num($kri->amber_threshold_min), 'max' => $this->num($kri->amber_threshold_max)];
            $red += ['min' => $this->num($kri->red_threshold_min), 'max' => null];
            $bands = [$green, $amber, $red];
        } else {
            $red += ['min' => null, 'max' => $this->num($kri->red_threshold_max)];
            $amber += ['min' => $this->num($kri->amber_threshold_min), 'max' => $this->num($kri->amber_threshold_max)];
            $green += ['min' => $this->num($kri->green_threshold_min), 'max' => null];
            $bands = [$red, $amber, $green];
        }

        // A band with neither bound configured claims everything, which would
        // make the whole set meaningless. Drop those, and drop the set if
        // nothing usable survives.
        $bands = array_values(array_filter(
            $bands,
            fn (array $band) => $band['min'] !== null || $band['max'] !== null
        ));

        return count($bands) > 1 ? $bands : [];
    }

    /* ------------------------------------------------------------------ */
    /*  The measurements */
    /* ------------------------------------------------------------------ */

    /**
     * Copy kri_measurements into measure_values, resolving each measurement
     * date to a period.
     *
     * Several readings can land in the same period — a KRI measured weekly
     * reporting monthly. The natural key permits one, so the LAST reading in
     * the period wins: an indicator is a level at a point in time, and the
     * point the period reports is its end. Processing in date order makes that
     * deterministic rather than dependent on insertion order.
     *
     * @return int values written
     */
    public function measurementsFor(KeyRiskIndicator $kri, Measure $measure, int $organizationId): int
    {
        $objectId = $this->objectIdFor($kri);

        if ($objectId === null) {
            return 0;
        }

        $periodType = self::FREQUENCY_TO_PERIOD_TYPE[strtolower((string) $kri->measurement_frequency)] ?? 'month';
        $written = 0;

        DB::table('kri_measurements')
            ->where('kri_id', $kri->id)
            ->orderBy('measurement_date')
            ->orderBy('id')
            ->chunk(500, function ($measurements) use ($measure, $objectId, $organizationId, $periodType, &$written) {
                foreach ($measurements as $measurement) {
                    if ($measurement->measurement_date === null) {
                        continue;
                    }

                    $period = $this->periods->resolve(
                        CarbonImmutable::parse($measurement->measurement_date),
                        $periodType,
                        $organizationId
                    );

                    MeasureValue::withoutGlobalScopes()->updateOrCreate(
                        [
                            'measure_id' => $measure->id,
                            'object_id' => $objectId,
                            'period_id' => $period->id,
                            'scenario' => 'actual',
                            'currency_key' => MeasureValue::NO_CURRENCY,
                        ],
                        [
                            'organization_id' => $organizationId,
                            'value' => $measurement->value,
                            'currency_code' => null,
                            'status' => $period->is_closed ? 'locked' : 'approved',
                            // The status the legacy ladder assigned. Preserved
                            // as recorded rather than recomputed, so a reading
                            // banded under thresholds that have since changed
                            // keeps the colour it was reported in.
                            'rag_band' => $measurement->status ?: null,
                            'entered_by' => $measurement->entered_by,
                            'entered_at' => $measurement->measurement_date,
                            'source' => 'migration',
                            'note' => $measurement->notes ?? null,
                        ]
                    );

                    $written++;
                }
            });

        return $written;
    }

    /* ------------------------------------------------------------------ */
    /*  Breach backfill */
    /* ------------------------------------------------------------------ */

    /**
     * Reconstruct a breach register from what survives of the old one.
     *
     * Before WP-04 a KRI breach existed ONLY as a notification: the nightly
     * command dispatched an event, SendNotification wrote a row to
     * notifications_log, and nothing else recorded that a limit had been
     * crossed. notifications_log is therefore the only historic source, and it
     * carries the KRI id in its action_url and the band in its body.
     *
     * That is genuinely partial — a breach nobody was notified about left no
     * trace at all, and the command that produced these notifications queried
     * columns that do not exist on key_risk_indicators, so it has been
     * throwing rather than detecting for as long as those columns have been
     * absent. What can be recovered is recovered; the rest starts from now.
     *
     * @return int breaches written
     */
    public function backfillBreachesFromNotifications(int $organizationId): int
    {
        if (! DB::getSchemaBuilder()->hasTable('notifications_log')) {
            return 0;
        }

        $written = 0;

        DB::table('notifications_log')
            ->where('organization_id', $organizationId)
            ->where('type', 'kri_breach')
            ->orderBy('created_at')
            ->chunk(500, function ($notifications) use ($organizationId, &$written) {
                foreach ($notifications as $notification) {
                    $kriId = $this->kriIdFromNotification($notification);
                    $band = $this->bandFromNotification($notification);

                    if ($kriId === null || $band === null) {
                        continue;
                    }

                    $kri = KeyRiskIndicator::withoutGlobalScopes()
                        ->where('organization_id', $organizationId)
                        ->find($kriId);

                    if ($kri === null) {
                        continue;
                    }

                    $measure = Measure::withoutGlobalScopes()
                        ->where('organization_id', $organizationId)
                        ->where('code', $kri->kri_code)
                        ->first();

                    $objectId = $this->objectIdFor($kri);

                    if ($measure === null || $objectId === null) {
                        continue;
                    }

                    $breachedAt = CarbonImmutable::parse($notification->created_at);
                    $period = $this->periods->resolve($breachedAt, 'month', $organizationId);

                    $value = MeasureValue::withoutGlobalScopes()
                        ->where('measure_id', $measure->id)
                        ->where('object_id', $objectId)
                        ->where('period_id', $period->id)
                        ->value('value');

                    $written += $this->writeHistoricBreach(
                        $organizationId, $measure->id, $objectId, $period->id, $band, $breachedAt, $value, $kri->risk_id
                    ) ? 1 : 0;
                }
            });

        return $written;
    }

    private function kriIdFromNotification(object $notification): ?int
    {
        if (preg_match('#/risk/kri/(\d+)#', (string) ($notification->action_url ?? ''), $matches)) {
            return (int) $matches[1];
        }

        $metadata = $notification->metadata ?? null;
        $metadata = is_string($metadata) ? json_decode($metadata, true) : $metadata;

        if (is_array($metadata) && isset($metadata['entity_id']) && ($metadata['entity_type'] ?? null) === 'key_risk_indicator') {
            return (int) $metadata['entity_id'];
        }

        return null;
    }

    private function bandFromNotification(object $notification): ?string
    {
        $body = strtolower((string) ($notification->body ?? ''));

        foreach (['red', 'amber'] as $band) {
            if (str_contains($body, "breached {$band} threshold")) {
                return $band;
            }
        }

        return null;
    }

    /* ------------------------------------------------------------------ */
    /*  Column readings */
    /* ------------------------------------------------------------------ */

    /**
     * The KRI's threshold direction, from whichever of the two columns that
     * hold it is populated.
     *
     * `threshold_direction` came from the original migration and holds
     * higher_worse / lower_worse; `direction` came from the controller
     * alignment pass and holds higher_is_worse / lower_is_worse. Both are live
     * in the data.
     */
    public function directionOf(KeyRiskIndicator $kri): string
    {
        $raw = strtolower((string) ($kri->threshold_direction ?: $kri->direction ?: 'higher_worse'));

        return str_starts_with($raw, 'lower') ? 'lower_worse' : 'higher_worse';
    }

    private function unitIdFor(KeyRiskIndicator $kri): ?int
    {
        $raw = trim((string) ($kri->measurement_unit ?: $kri->unit_of_measure ?: ''));

        if ($raw === '') {
            return null;
        }

        $direct = Unit::where('code', $raw)->value('id');

        if ($direct !== null) {
            return (int) $direct;
        }

        $alias = self::UNIT_ALIASES[strtolower($raw)] ?? null;

        return $alias === null ? null : Unit::where('code', $alias)->value('id');
    }

    private function num(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }

    /**
     * Write a historic breach directly rather than through MeasureService,
     * which would re-band the reading against TODAY's thresholds instead of
     * honouring the band the notification recorded at the time.
     */
    private function writeHistoricBreach(
        int $organizationId,
        int $measureId,
        int $objectId,
        int $periodId,
        string $band,
        CarbonImmutable $breachedAt,
        float|string|null $value,
        ?int $riskId
    ): bool {
        $breach = MeasureBreach::withoutGlobalScopes()->firstOrNew([
            'measure_id' => $measureId,
            'object_id' => $objectId,
            'period_id' => $periodId,
            'band_to' => $band,
        ]);

        if ($breach->exists) {
            return false;
        }

        $breach->fill([
            'organization_id' => $organizationId,
            'breached_at' => $breachedAt,
            'value' => $value ?? 0,
            'severity' => $band === 'red' ? 'high' : 'medium',
            // Historic and unattended: nobody could acknowledge a breach that
            // had no register to acknowledge it in. Marked resolved rather than
            // open so the backfill does not land as a queue of stale alerts,
            // with a note saying where it came from and what is missing.
            'status' => 'resolved',
            'resolved_at' => Period::withoutGlobalScopes()->whereKey($periodId)->value('end_date') ?? $breachedAt,
            'linked_risk_id' => $riskId,
            'note' => 'Reconstructed from the notification log during the WP-04 measure-engine migration. '
                .'The pre-WP-04 platform kept no breach register, so acknowledgement and resolution times '
                .'are not recoverable for this row.',
        ])->save();

        return true;
    }
}
