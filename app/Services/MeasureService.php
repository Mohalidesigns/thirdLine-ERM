<?php

namespace App\Services;

use App\Models\GraphObject;
use App\Models\Measure;
use App\Models\MeasureBreach;
use App\Models\MeasureThreshold;
use App\Models\MeasureValue;
use App\Models\Period;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Reads and writes the measure fact table.
 *
 * Every number the platform records for a period goes through record(): KRI
 * readings, approved assessment scores, control effectiveness, capital,
 * inflation. That is the point — one write path means one place where the RAG
 * band is resolved, one place where a closed period is refused, and one place
 * where a breach is registered.
 */
class MeasureService
{
    /** Band codes that constitute a breach worth registering. */
    public const BREACH_BANDS = ['red', 'amber'];

    /** How a band code maps onto a breach severity. */
    private const SEVERITY = ['red' => 'high', 'amber' => 'medium', 'yellow' => 'low'];

    public function __construct(
        private FormulaEvaluator $formulas,
        private PeriodService $periods,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Object resolution */
    /* ------------------------------------------------------------------ */

    /**
     * The graph object id for a typed record.
     *
     * A measure hangs off a node in the object graph rather than off a risk or
     * a KRI directly, which is what lets one query roll every measure at or
     * below a business unit. Records created before WP-03's backfill, or by
     * code paths that bypassed the sync, are mirrored on demand rather than
     * silently dropping their measurements.
     */
    public function objectIdFor(Model|GraphObject|int $subject): ?int
    {
        if (is_int($subject)) {
            return $subject;
        }

        if ($subject instanceof GraphObject) {
            return $subject->getKey();
        }

        $objectId = GraphObject::withoutGlobalScopes()
            ->where('source_model_type', $subject->getMorphClass())
            ->where('source_model_id', $subject->getKey())
            ->value('id');

        if ($objectId !== null) {
            return (int) $objectId;
        }

        if (method_exists($subject, 'syncObjectIdentity')) {
            return $subject->syncObjectIdentity('job');
        }

        return null;
    }

    /* ------------------------------------------------------------------ */
    /*  Measure definitions */
    /* ------------------------------------------------------------------ */

    public function measure(string $code, ?int $organizationId = null): ?Measure
    {
        return Measure::withoutGlobalScopes()
            ->where('organization_id', $organizationId ?? TenantContext::organizationId())
            ->where('code', $code)
            ->first();
    }

    /**
     * Define a measure, or update the definition of one that already exists.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function defineMeasure(string $code, array $attributes, ?int $organizationId = null): Measure
    {
        $organizationId = $organizationId ?? TenantContext::organizationId();

        return Measure::withoutGlobalScopes()->updateOrCreate(
            ['organization_id' => $organizationId, 'code' => $code],
            $attributes
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Writing values */
    /* ------------------------------------------------------------------ */

    /**
     * Record one value, resolving its RAG band and registering any breach.
     *
     * Idempotent on the natural key (measure, object, period, scenario,
     * currency): re-running an import or re-approving an assessment corrects
     * the value in place rather than stacking duplicates.
     *
     * @param  array{
     *     scenario?:string, currency_code?:?string, fx_rate_used?:?float, status?:string,
     *     entered_by?:?int, entered_at?:?string, source?:string, evidence_ref?:?string,
     *     note?:?string, detect_breach?:bool, force?:bool
     * }  $options
     */
    public function record(
        Measure|string $measure,
        Model|GraphObject|int $object,
        Period|int $period,
        float|string $value,
        array $options = []
    ): MeasureValue {
        $organizationId = $options['organization_id'] ?? TenantContext::organizationId();

        $measure = $measure instanceof Measure ? $measure : $this->requireMeasure($measure, $organizationId);
        $objectId = $this->objectIdFor($object);

        if ($objectId === null) {
            throw new RuntimeException(
                "Cannot record measure {$measure->code}: the subject has no identity in the object graph."
            );
        }

        $period = $period instanceof Period
            ? $period
            : (Period::withoutGlobalScopes()->find($period) ?? throw new RuntimeException("Unknown period {$period}."));

        // A closed period is closed. force is for the reopen path and for
        // migrations backfilling history into periods that were generated
        // already closed; it is never set by a controller.
        if ($period->is_closed && ! ($options['force'] ?? false)) {
            throw new RuntimeException(
                "Period {$period->name} is closed. Reopen it before recording {$measure->code}."
            );
        }

        $scenario = $options['scenario'] ?? 'actual';
        $currency = $options['currency_code'] ?? null;

        $band = $this->bandCodeFor($measure, $objectId, (float) $value, $period);

        $measureValue = DB::transaction(function () use ($measure, $objectId, $period, $value, $options, $scenario, $currency, $band, $organizationId) {
            $existing = MeasureValue::withoutGlobalScopes()
                ->where('measure_id', $measure->id)
                ->where('object_id', $objectId)
                ->where('period_id', $period->id)
                ->where('scenario', $scenario)
                ->where('currency_key', $currency ?: MeasureValue::NO_CURRENCY)
                ->first();

            $attributes = [
                'organization_id' => $organizationId,
                'measure_id' => $measure->id,
                'object_id' => $objectId,
                'period_id' => $period->id,
                'scenario' => $scenario,
                'value' => $value,
                'currency_code' => $currency,
                'fx_rate_used' => $options['fx_rate_used'] ?? null,
                'status' => $options['status'] ?? ($period->is_closed ? 'locked' : 'approved'),
                'rag_band' => $band,
                'entered_by' => $options['entered_by'] ?? auth()->id(),
                'entered_at' => $options['entered_at'] ?? now(),
                'source' => $options['source'] ?? 'manual',
                'evidence_ref' => $options['evidence_ref'] ?? null,
                'note' => $options['note'] ?? null,
            ];

            if ($existing === null) {
                return MeasureValue::withoutGlobalScopes()->create($attributes);
            }

            // forceFill + save rather than update(), so the locked-value guard
            // in the model still fires on the update path.
            $existing->fill($attributes);
            $existing->save();

            return $existing;
        });

        if ($options['detect_breach'] ?? true) {
            $this->reconcileBreach($measure, $objectId, $period, $measureValue);
        }

        return $measureValue;
    }

    public function requireMeasure(string $code, ?int $organizationId = null): Measure
    {
        return $this->measure($code, $organizationId)
            ?? throw new RuntimeException("Measure '{$code}' is not defined for this organisation.");
    }

    /* ------------------------------------------------------------------ */
    /*  Thresholds and bands */
    /* ------------------------------------------------------------------ */

    /**
     * The threshold row in force for a measure on a date, preferring one that
     * targets this specific object over the measure's default.
     */
    public function activeThreshold(Measure $measure, ?int $objectId, ?string $onDate = null): ?MeasureThreshold
    {
        $onDate = $onDate ?? now()->toDateString();

        $query = MeasureThreshold::withoutGlobalScopes()
            ->where('measure_id', $measure->id)
            ->effectiveOn($onDate);

        if ($objectId !== null) {
            $specific = (clone $query)->where('object_id', $objectId)->orderByDesc('effective_from')->first();

            if ($specific !== null) {
                return $specific;
            }
        }

        return $query->whereNull('object_id')->orderByDesc('effective_from')->first();
    }

    /**
     * Band definitions with every formula bound evaluated to a literal.
     *
     * A bound that cannot be evaluated — the capital figure for the period has
     * not been entered yet — is dropped to null (unbounded) rather than to
     * zero. An unbounded side widens the band; a zero would narrow it to
     * nothing and put every reading into breach.
     *
     * @return list<array<string, mixed>>
     */
    public function resolveBands(MeasureThreshold $threshold, array $context = []): array
    {
        $context = array_merge([
            'organization_id' => $threshold->organization_id,
            'object_id' => $threshold->object_id,
        ], $context);

        $resolved = [];

        foreach ($threshold->bands ?? [] as $band) {
            foreach (['min', 'max'] as $bound) {
                $formula = $band[$bound.'_formula'] ?? null;

                if ($formula === null || $formula === '') {
                    continue;
                }

                try {
                    $band[$bound] = $this->formulas->evaluate((string) $formula, $context);
                } catch (FormulaEvaluationException $exception) {
                    report($exception);
                    $band[$bound] = null;
                }
            }

            $resolved[] = $band;
        }

        return $resolved;
    }

    /**
     * The band code a value falls into, or null when no threshold applies.
     */
    public function bandCodeFor(Measure $measure, int $objectId, float $value, Period $period): ?string
    {
        $threshold = $this->activeThreshold($measure, $objectId, $period->end_date?->toDateString());

        if ($threshold === null) {
            return null;
        }

        $bands = $this->resolveBands($threshold, ['period_id' => $period->id, 'object_id' => $objectId]);
        $band = $threshold->bandFor($value, $bands);

        return $band['code'] ?? null;
    }

    /* ------------------------------------------------------------------ */
    /*  Breaches */
    /* ------------------------------------------------------------------ */

    /**
     * Open, keep or close the breach implied by a value.
     *
     * Registering a breach is an upsert on (measure, object, period, band):
     * the nightly check re-reads the same value and must not stack a new row
     * each night, and it must not reset an acknowledgement someone made
     * yesterday.
     *
     * When the reading comes back inside the good bands, breaches still open
     * for that period are resolved — which is what makes mean time to resolve
     * a real number rather than an estimate.
     */
    public function reconcileBreach(Measure $measure, int $objectId, Period $period, MeasureValue $value): ?MeasureBreach
    {
        $band = $value->rag_band;

        if ($band === null || ! in_array($band, self::BREACH_BANDS, true)) {
            $this->resolveOpenBreaches($measure, $objectId, $period, $value);

            return null;
        }

        $threshold = $this->activeThreshold($measure, $objectId, $period->end_date?->toDateString());
        $bands = $threshold ? $this->resolveBands($threshold, ['period_id' => $period->id, 'object_id' => $objectId]) : [];
        $definition = collect($bands)->firstWhere('code', $band) ?? [];

        // The bound that was crossed: the lower bound for a higher-is-worse
        // measure, the upper bound otherwise.
        $thresholdValue = $measure->higherIsWorse()
            ? ($definition['min'] ?? null)
            : ($definition['max'] ?? null);

        $breach = MeasureBreach::withoutGlobalScopes()->firstOrNew([
            'measure_id' => $measure->id,
            'object_id' => $objectId,
            'period_id' => $period->id,
            'band_to' => $band,
        ]);

        // The two transitions INTO breach, captured before anything below
        // mutates them: a band that has never breached in this period, and one
        // that had recovered and has gone back over. Everything else — a
        // reading that leaves an open breach open — is the same breach still
        // running, and must not be announced twice. See announceBreach().
        $isNewBreach = ! $breach->exists;
        $isReopening = $breach->exists && $breach->status === 'resolved';

        if (! $breach->exists) {
            $breach->fill([
                'organization_id' => $measure->organization_id,
                'breached_at' => $value->entered_at ?? now(),
                'band_from' => $this->previousBandFor($measure, $objectId, $period),
                'severity' => self::SEVERITY[$band] ?? 'medium',
                'status' => 'open',
                'linked_risk_id' => $this->linkedRiskIdFor($objectId),
            ]);
        }

        // The reading is refreshed on every check; the lifecycle columns are
        // not, so an acknowledgement survives tonight's re-run.
        $breach->value = $value->value;
        $breach->threshold_value = $thresholdValue;

        // Self-healing, and only ever from absent to present. A breach opened
        // before its indicator was mapped to a risk — or before this resolution
        // learned to read the risk_kri_mapping pivot — picks the link up on the
        // next check instead of needing a migration. An existing link is never
        // overwritten: that is somebody's deliberate association.
        if ($breach->linked_risk_id === null) {
            $breach->linked_risk_id = $this->linkedRiskIdFor($objectId);
        }

        if ($breach->status === 'resolved') {
            // It came back. Reopening rather than creating a second row keeps
            // one breach per band per period, and the resolved_at timestamp is
            // cleared so MTTR is not computed against a stale resolution.
            $breach->status = 'open';
            $breach->resolved_at = null;
            $breach->breached_at = $value->entered_at ?? now();
        }

        $breach->save();

        // A worse band supersedes a lesser one: going red closes the amber.
        $this->resolveLesserBreaches($measure, $objectId, $period, $band);

        if ($isNewBreach || $isReopening) {
            $this->announceBreach($objectId, $band);
        }

        return $breach;
    }

    /**
     * Raise the domain event for a breach that has just been opened.
     *
     * This lives here because reconcileBreach() is the ONE place both breach
     * paths meet — the nightly kri:check-breaches sweep and a risk officer
     * typing a red reading into the KRI screen, which reaches this method
     * through KriMeasureBridge::recordMeasurement() -> record(). The manual
     * path used to raise nothing at all: a breach entered by hand appended a
     * sentence to a flash message and told nobody, which is the opposite of
     * what a breach register is for. Putting the dispatch at the funnel point
     * rather than in each caller is also what keeps "announce once" honest —
     * this method is only reached on a transition INTO breach, because it is
     * the only code that can tell a new or reopened breach from one that is
     * merely still open.
     *
     * Not every measure is a KRI. Capital, inflation and control effectiveness
     * all breach through this same method, and KriBreachDetected has nowhere to
     * put them: it is typed on KeyRiskIndicator. Those breaches are registered
     * and simply not announced — no exception, and no fabricated indicator.
     *
     * Nothing in here may fail the measurement. A notification is a side
     * effect of recording a number; it is not the reason the number was
     * recorded, and losing the reading because a listener threw would be a far
     * worse outcome than a missed alert.
     */
    private function announceBreach(int $objectId, string $band): void
    {
        try {
            $object = GraphObject::withoutGlobalScopes()->find($objectId);

            if ($object === null || $object->source_model_type !== 'key_risk_indicator') {
                return;
            }

            $kri = \App\Models\KeyRiskIndicator::withoutGlobalScopes()
                ->whereKey($object->source_model_id)
                ->first();

            if ($kri === null) {
                return;
            }

            // The event is typed on KriMeasurement, so it needs a persisted
            // row. KriMeasureBridge mirrors the reading onto kri_measurements
            // BEFORE it reconciles the breach precisely so that this lookup
            // finds the reading that caused the breach rather than the one
            // before it. A KRI whose readings came in with no user attached
            // has no legal legacy row (entered_by is NOT NULL), so there is
            // nothing to name here; the breach is still registered.
            $measurement = \App\Models\KriMeasurement::where('kri_id', $kri->id)
                ->orderByDesc('measurement_date')
                ->orderByDesc('id')
                ->first();

            if ($measurement === null) {
                logger()->info('KRI breach registered without a legacy measurement to announce', [
                    'kri_id' => $kri->id,
                    'band' => $band,
                ]);

                return;
            }

            \App\Events\KriBreachDetected::dispatch($kri, $measurement, $band);
        } catch (\Throwable $e) {
            logger()->warning('Could not announce a KRI breach', [
                'object_id' => $objectId,
                'band' => $band,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolveOpenBreaches(Measure $measure, int $objectId, Period $period, MeasureValue $value): void
    {
        MeasureBreach::withoutGlobalScopes()
            ->where('measure_id', $measure->id)
            ->where('object_id', $objectId)
            ->where('period_id', $period->id)
            ->whereIn('status', ['open', 'acknowledged'])
            ->update([
                'status' => 'resolved',
                'resolved_at' => $value->entered_at ?? now(),
                'updated_at' => now(),
            ]);
    }

    private function resolveLesserBreaches(Measure $measure, int $objectId, Period $period, string $band): void
    {
        $order = MeasureThreshold::severityOrder();
        $rank = array_search($band, $order, true);

        if ($rank === false) {
            return;
        }

        $lesser = array_slice($order, $rank + 1);

        if ($lesser === []) {
            return;
        }

        MeasureBreach::withoutGlobalScopes()
            ->where('measure_id', $measure->id)
            ->where('object_id', $objectId)
            ->where('period_id', $period->id)
            ->whereIn('band_to', $lesser)
            ->whereIn('status', ['open', 'acknowledged'])
            ->update(['status' => 'resolved', 'resolved_at' => now(), 'updated_at' => now()]);
    }

    /**
     * The band this measure was in for the previous period, so a breach can
     * record what it came from.
     */
    private function previousBandFor(Measure $measure, int $objectId, Period $period): ?string
    {
        $previous = $this->periods->previous($period);

        if ($previous === null) {
            return null;
        }

        return MeasureValue::withoutGlobalScopes()
            ->where('measure_id', $measure->id)
            ->where('object_id', $objectId)
            ->where('period_id', $previous->id)
            ->where('scenario', 'actual')
            ->value('rag_band');
    }

    /**
     * The risk a breached object relates to, where the object graph knows one.
     *
     * A KRI's object hangs off the risk it monitors; linking the breach to that
     * risk is what puts an indicator breach on the risk's own screen.
     */
    private function linkedRiskIdFor(int $objectId): ?int
    {
        $object = GraphObject::withoutGlobalScopes()->find($objectId);

        if ($object === null) {
            return null;
        }

        if ($object->source_model_type === 'risk') {
            return (int) $object->source_model_id;
        }

        if ($object->source_model_type === 'key_risk_indicator') {
            // A KRI links to risks two ways and both are live in the data: the
            // direct risk_id column (5 of 15 rows in the reference dataset) and
            // the risk_kri_mapping pivot (12 rows). Reading only the column
            // left most breaches unlinked, which is the difference between an
            // indicator breach appearing on its risk's screen and not.
            $direct = \App\Models\KeyRiskIndicator::withoutGlobalScopes()
                ->whereKey($object->source_model_id)
                ->value('risk_id');

            if ($direct !== null) {
                return (int) $direct;
            }

            // Several mapped risks is legitimate; the breach column holds one,
            // so it takes the lowest id for stability rather than whichever the
            // database happens to return first.
            $mapped = DB::table('risk_kri_mapping')
                ->where('kri_id', $object->source_model_id)
                ->orderBy('risk_id')
                ->value('risk_id');

            return $mapped === null ? null : (int) $mapped;
        }

        return null;
    }

    /* ------------------------------------------------------------------ */
    /*  Reading values */
    /* ------------------------------------------------------------------ */

    /**
     * One value, or null.
     */
    public function valueOf(
        string|Measure $measure,
        Model|GraphObject|int $object,
        Period|int $period,
        string $scenario = 'actual',
        ?int $organizationId = null
    ): ?MeasureValue {
        $measure = $measure instanceof Measure ? $measure : $this->measure($measure, $organizationId);

        if ($measure === null) {
            return null;
        }

        $objectId = $this->objectIdFor($object);

        return $objectId === null ? null : MeasureValue::withoutGlobalScopes()
            ->where('measure_id', $measure->id)
            ->where('object_id', $objectId)
            ->where('period_id', $period instanceof Period ? $period->id : $period)
            ->where('scenario', $scenario)
            ->first();
    }

    /**
     * A measure across several objects and several periods, in one query.
     *
     * Returned as [objectId][periodId] => value so a trend chart or a heat map
     * can be assembled without a query per cell — the difference between a
     * 12-period view of 5,000 risks costing one index scan and costing 60,000
     * round trips.
     *
     * @param  list<int>  $objectIds
     * @param  list<int>  $periodIds
     * @return array<int, array<int, float>>
     */
    public function matrix(
        string|Measure $measure,
        array $objectIds,
        array $periodIds,
        string $scenario = 'actual',
        ?int $organizationId = null
    ): array {
        $measure = $measure instanceof Measure ? $measure : $this->measure($measure, $organizationId);

        if ($measure === null || $objectIds === [] || $periodIds === []) {
            return [];
        }

        $rows = MeasureValue::withoutGlobalScopes()
            ->where('measure_id', $measure->id)
            ->whereIn('object_id', $objectIds)
            ->whereIn('period_id', $periodIds)
            ->where('scenario', $scenario)
            ->get(['object_id', 'period_id', 'value']);

        $matrix = [];

        foreach ($rows as $row) {
            $matrix[(int) $row->object_id][(int) $row->period_id] = (float) $row->value;
        }

        return $matrix;
    }

    /**
     * A single object's values for a measure across periods, chronologically.
     *
     * @param  list<int>  $periodIds
     * @return array<int, float> periodId => value
     */
    public function series(
        string|Measure $measure,
        Model|GraphObject|int $object,
        array $periodIds,
        string $scenario = 'actual',
        ?int $organizationId = null
    ): array {
        $objectId = $this->objectIdFor($object);

        if ($objectId === null) {
            return [];
        }

        return $this->matrix($measure, [$objectId], $periodIds, $scenario, $organizationId)[$objectId] ?? [];
    }
}
