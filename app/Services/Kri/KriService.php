<?php

namespace App\Services\Kri;

use App\Models\KeyRiskIndicator;
use App\Models\MeasureBreach;
use App\Models\MeasureValue;
use App\Services\AuditTrailService;
use App\Services\KriMeasureBridge;
use App\Services\MeasureService;
use App\Services\PeriodService;
use App\Services\ReferenceCodeService;
use App\Support\Periods\PeriodContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * KRI monitoring (migration Phase 4.1).
 *
 * Lifted out of KriController, which computed the dashboard's figures inline
 * and fanned the form's two threshold numbers out across six columns in two
 * places. Pinned by tests/Feature/Characterisation/KriDashboardFiguresTest.
 *
 * READS GO THROUGH THE MEASURE ENGINE, NOT `kri_measurements`. Since WP-04 the
 * readings live in `measure_values` and the bands in `measure_thresholds`;
 * `key_risk_indicators` is a facade over them and its threshold columns are a
 * mirror. The Blade controller still read the legacy `KriMeasurement` table in
 * two places — the dashboard's twelve-month trend and the show page's history —
 * so those two figures were computed from a table the engine no longer writes
 * as its source of truth. Phase 4's acceptance criteria forbid new readers of
 * it; there are none here.
 */
class KriService
{
    public function __construct(
        private readonly KriMeasureBridge $bridge,
        private readonly MeasureService $measures,
        private readonly PeriodService $periods,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Dashboard */
    /* ------------------------------------------------------------------ */

    /**
     * Every KRI in the tenant, worst status first.
     *
     * `FIELD()` is MySQL-only, so this ordering threw on any other driver and
     * the whole dashboard could not be tested; it is a portable CASE now.
     *
     * @return Collection<int, KeyRiskIndicator>
     */
    public function trafficLights(): Collection
    {
        return KeyRiskIndicator::where('organization_id', $this->orgId())
            ->with('risk')
            ->orderByRaw("CASE current_status WHEN 'red' THEN 0 WHEN 'amber' THEN 1 WHEN 'yellow' THEN 2 WHEN 'green' THEN 3 ELSE 4 END")
            ->orderBy('kri_code')
            ->get();
    }

    /**
     * The six KPI tiles.
     *
     * `yellow` is a stored status with no tile of its own: it counts as healthy
     * in the health score and lands in the Green slice of the distribution.
     * Carried across exactly.
     *
     * @param  Collection<int, KeyRiskIndicator>  $kris
     * @return array<string, int>
     */
    public function kpis(Collection $kris): array
    {
        $count = fn (string $status) => $kris->where('current_status', $status)->count();

        $total = $kris->count();
        $green = $count('green');
        $yellow = $count('yellow');
        $amber = $count('amber');
        $red = $count('red');

        return [
            'total' => $total,
            'green' => $green,
            'yellow' => $yellow,
            'amber' => $amber,
            'red' => $red,
            'activeBreaches' => $red + $amber,
            'avgHealthScore' => $total > 0 ? (int) round((($green + $yellow) / $total) * 100) : 0,
        ];
    }

    /**
     * Three slices for four stored statuses — yellow folds into Green.
     *
     * @param  Collection<int, KeyRiskIndicator>  $kris
     * @return list<array{label: string, value: int}>
     */
    public function statusDistribution(Collection $kris): array
    {
        $count = fn (string $status) => $kris->where('current_status', $status)->count();

        return [
            ['label' => 'Green', 'value' => $count('green') + $count('yellow')],
            ['label' => 'Amber', 'value' => $count('amber')],
            ['label' => 'Red', 'value' => $count('red')],
        ];
    }

    /**
     * The KRIs currently in amber or red, worst first, capped at ten.
     *
     * @param  Collection<int, KeyRiskIndicator>  $kris
     * @return list<array<string, mixed>>
     */
    public function breaching(Collection $kris, int $limit = 10): array
    {
        return $kris->whereIn('current_status', ['red', 'amber'])
            ->take($limit)
            ->map(fn (KeyRiskIndicator $kri) => [
                'id' => $kri->id,
                'code' => $kri->kri_code,
                'name' => $kri->name,
                'status' => $kri->current_status,
                'currentValue' => $this->withUnit($kri, $kri->current_value),
                // The limit the indicator is read against. Null, not a dash,
                // when none is set — the page decides how to say "none".
                'thresholdValue' => $this->withUnit($kri, $kri->red_threshold),
                'riskCode' => $kri->risk?->risk_code,
                'url' => route('risk.kri.show', $kri),
            ])
            ->values()
            ->all();
    }

    /**
     * Breaches opened per month over the trailing twelve months, split by the
     * band they went into.
     *
     * THIS IS A DIFFERENT QUESTION FROM THE ONE THE BLADE CHART ANSWERED, and
     * deliberately so. That chart counted rows in `kri_measurements` whose
     * status was red or amber, so a KRI sitting in breach for six months
     * contributed a count in each of the six — it plotted months-in-breach, not
     * breaches, under a heading that said "Breach Trend". This counts the
     * breach register, which is one row per crossing and is what the
     * acknowledge / resolve workflow acts on. It is also the only version
     * available without reading `kri_measurements`, which Phase 4 forbids.
     *
     * @return list<array{month: string, red: int, amber: int}>
     */
    public function breachTrend(): array
    {
        $since = now()->copy()->startOfMonth()->subMonths(11);

        // The query builder rather than the model: a grouped count returns
        // aliases, not MeasureBreach rows, and hydrating them as models would
        // be claiming they are.
        $rows = DB::table('measure_breaches')
            ->where('organization_id', $this->orgId())
            ->where('breached_at', '>=', $since)
            ->selectRaw($this->monthKey('breached_at').' as month_key, band_to, COUNT(*) as total')
            ->groupBy('month_key', 'band_to')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[$row->month_key][$row->band_to] = (int) $row->total;
        }

        $months = [];

        for ($offset = 11; $offset >= 0; $offset--) {
            $month = now()->copy()->startOfMonth()->subMonths($offset);
            $key = $month->format('Y-m');

            $months[] = [
                'month' => $month->format('M'),
                'red' => $counts[$key]['red'] ?? 0,
                'amber' => $counts[$key]['amber'] ?? 0,
            ];
        }

        return $months;
    }

    /* ------------------------------------------------------------------ */
    /*  Show */
    /* ------------------------------------------------------------------ */

    /**
     * The twelve-period chart: the readings in force, and the bands they are
     * read against.
     *
     * Period-indexed rather than "the last 24 rows in the table", so moving the
     * period selector moves the window rather than the window drifting with
     * measurement frequency.
     *
     * @return array<string, mixed>
     */
    public function history(KeyRiskIndicator $kri): array
    {
        $measure = $this->bridge->measureFor($kri);
        $periodType = $this->bridge->periodTypeFor($kri);

        $anchor = PeriodContext::current() ?? $this->periods->current($periodType, $this->orgId());
        $window = $this->periods->trailing($anchor, 12, $periodType);
        $series = $this->bridge->series($kri, $window->pluck('id')->all());

        $threshold = $measure === null
            ? null
            : $this->measures->activeThreshold(
                $measure,
                $this->measures->objectIdFor($kri),
                $anchor->end_date?->toDateString(),
            );

        $bands = $threshold === null ? [] : $this->measures->resolveBands($threshold);
        $edge = function (string $code, string $bound) use ($bands) {
            $band = collect($bands)->firstWhere('code', $code);

            return isset($band[$bound]) ? (float) $band[$bound] : null;
        };

        return [
            'points' => $window->map(fn ($period) => [
                'period' => $period->name,
                'value' => $series[$period->id] ?? null,
            ])->values()->all(),
            // The bounds actually in force for the anchor period, not the
            // legacy mirror columns.
            'green' => $edge('green', 'max') ?? $edge('green', 'min'),
            'amber' => $edge('amber', 'max') ?? $edge('amber', 'min'),
            'red' => $edge('red', 'min') ?? $edge('red', 'max'),
            'bands' => $bands,
        ];
    }

    /**
     * The reading history as a table, newest first — from `measure_values`,
     * which is where a reading has lived since WP-04.
     *
     * @return \Illuminate\Pagination\LengthAwarePaginator<int, MeasureValue>
     */
    public function readings(KeyRiskIndicator $kri, int $perPage = 20): \Illuminate\Pagination\LengthAwarePaginator
    {
        $measure = $this->bridge->measureFor($kri);
        $objectId = $this->measures->objectIdFor($kri);

        $query = MeasureValue::query()->with(['period', 'enteredBy']);

        if ($measure === null || $objectId === null) {
            $query->whereRaw('1 = 0');
        } else {
            $query->where('measure_id', $measure->id)->where('object_id', $objectId);
        }

        return $query->orderByDesc('id')->paginate($perPage);
    }

    /**
     * Breaches raised against this KRI, newest first.
     *
     * @return Collection<int, MeasureBreach>
     */
    public function breachesFor(KeyRiskIndicator $kri, int $limit = 20): Collection
    {
        $measure = $this->bridge->measureFor($kri);

        return MeasureBreach::query()
            ->when($measure !== null, fn (Builder $q) => $q->where('measure_id', $measure->id))
            ->when($measure === null, fn (Builder $q) => $q->whereRaw('1 = 0'))
            ->with(['acknowledgedBy', 'period'])
            ->orderByDesc('breached_at')
            ->limit($limit)
            ->get();
    }

    /* ------------------------------------------------------------------ */
    /*  Writes */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $validated
     */
    public function create(array $validated, ?int $actorId): KeyRiskIndicator
    {
        $kri = KeyRiskIndicator::create(array_merge(
            $this->columnsFor($validated),
            [
                'organization_id' => $this->orgId(),
                'kri_code' => ReferenceCodeService::generate('key_risk_indicators', 'kri_code', 'KRI'),
                'current_status' => 'green',
                'is_active' => true,
                'created_by' => $actorId,
                'risk_id' => $validated['risk_id'],
            ],
        ));

        // The measure engine is the source of truth for the reading and the
        // bands; defining the measure here rather than lazily means the KRI has
        // bands before its first reading arrives.
        $this->bridge->syncDefinition($kri);

        AuditTrailService::record($kri, 'create');

        return $kri;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function update(KeyRiskIndicator $kri, array $validated, ?int $actorId): KeyRiskIndicator
    {
        $original = $kri->getAttributes();

        $kri->update(array_merge($this->columnsFor($validated), [
            'is_active' => $validated['is_active'] ?? $kri->is_active,
            'updated_by' => $actorId,
        ]));

        // A threshold edit writes a NEW effective-dated band set and closes the
        // old one, so a breach recorded last month still reads against the
        // limit that was in force last month.
        $this->bridge->syncDefinition($kri);

        AuditTrailService::recordChanges($kri, $original);

        return $kri;
    }

    /**
     * Bulk band edit from the thresholds screen.
     *
     * TWO THINGS WERE WRONG WITH THE METHOD THIS REPLACES. It read
     * `$request->input('thresholds')` while the form posted `kris[...]`, so the
     * loop never ran and every edit was discarded behind a success message. And
     * even with a matching payload it wrote the mirror columns WITHOUT calling
     * syncDefinition(), so the measure engine — which decides what is a breach —
     * would have kept the old bands regardless.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return int how many indicators actually changed
     */
    public function updateThresholds(array $rows): int
    {
        $changed = 0;

        $kris = KeyRiskIndicator::where('organization_id', $this->orgId())
            ->whereIn('id', array_column($rows, 'id'))
            ->get()
            ->keyBy('id');

        foreach ($rows as $row) {
            $kri = $kris->get((int) $row['id']);

            if ($kri === null) {
                continue;
            }

            // The direction is the INDICATOR's, read per row: the same pair of
            // numbers means opposite columns on a lower-is-worse KRI.
            $columns = $this->bandColumns(
                $kri->threshold_direction === 'lower_worse' ? 'lower_is_worse' : 'higher_is_worse',
                $row['green_threshold'] ?? null,
                $row['red_threshold'] ?? null,
            );

            $kri->fill($columns);

            if ($kri->isDirty()) {
                $kri->save();
                $changed++;
            }

            // Unconditionally, even when the mirror columns did not move: the
            // engine's band set is what a breach is judged against, and a KRI
            // whose definition was never synced has none.
            $this->bridge->syncDefinition($kri);
        }

        return $changed;
    }

    /**
     * Enter a reading.
     *
     * Through the engine: the reading lands in measure_values against the
     * period its date falls in, the band is resolved from the thresholds that
     * were in force then, and a crossing becomes a row in the breach register
     * rather than a notification nobody can acknowledge.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function recordMeasurement(KeyRiskIndicator $kri, array $validated, ?int $actorId): array
    {
        $original = $kri->getAttributes();

        $result = $this->bridge->recordMeasurement(
            $kri,
            $validated['measurement_date'],
            (float) $validated['value'],
            ['notes' => $validated['notes'] ?? null, 'entered_by' => $actorId],
        );

        $kri->refresh();

        AuditTrailService::recordChanges($kri, $original);

        return $result;
    }

    /* ------------------------------------------------------------------ */
    /*  Internals */
    /* ------------------------------------------------------------------ */

    private function orgId(): int
    {
        return TenantContext::organizationId();
    }

    /**
     * The form's fields as table columns.
     *
     * `key_risk_indicators` carries two names for several things — the original
     * NOT NULL columns and the 200038 alignment columns — and both are written,
     * as they were, so nothing that reads either goes dark.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function columnsFor(array $validated): array
    {
        $columns = [
            'kri_name' => $validated['kri_name'],
            'description' => $validated['description'] ?? null,
            'measurement_unit' => $validated['measurement_unit'],
            'measurement_frequency' => $validated['measurement_frequency'],
            'data_source' => $validated['data_source'] ?? '',
            'kri_owner_id' => $validated['kri_owner_id'],
            'target_value' => $validated['target_value'] ?? null,
            'metric_formula' => $validated['formula'] ?? '',
            'direction' => $validated['direction'],

            // The original NOT NULL columns, kept in step.
            'name' => $validated['kri_name'],
            'unit_of_measure' => $validated['measurement_unit'],
            'owner_id' => $validated['kri_owner_id'],
            'threshold_direction' => $validated['direction'] === 'higher_is_worse' ? 'higher_worse' : 'lower_worse',
        ];

        return array_merge($columns, $this->bandColumns(
            $validated['direction'],
            $validated['green_threshold'] ?? null,
            $validated['red_threshold'] ?? null,
        ));
    }

    /**
     * The two boundaries fanned out across the six band columns.
     *
     * A band set needs TWO numbers. On a higher-is-worse indicator green runs
     * up to the green boundary, red starts at the red boundary, and amber is
     * everything between — it has no boundary of its own. The old form asked
     * for three and the third was read into a variable and never used.
     *
     * @return array<string, float|null>
     */
    private function bandColumns(string $direction, float|int|string|null $green, float|int|string|null $red): array
    {
        $green = $green === null || $green === '' ? null : (float) $green;
        $red = $red === null || $red === '' ? null : (float) $red;

        if ($direction === 'higher_is_worse') {
            return [
                'green_threshold_min' => null,
                'green_threshold_max' => $green,
                'amber_threshold_min' => $green,
                'amber_threshold_max' => $red,
                'red_threshold_min' => $red,
                'red_threshold_max' => null,
            ];
        }

        return [
            'green_threshold_min' => $green,
            'green_threshold_max' => null,
            'amber_threshold_min' => $red,
            'amber_threshold_max' => $green,
            'red_threshold_min' => null,
            'red_threshold_max' => $red,
        ];
    }

    /** A value with the indicator's unit appended, or null when there is none. */
    private function withUnit(KeyRiskIndicator $kri, float|int|string|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return number_format((float) $value, 2).($kri->unit_of_measure ?? '');
    }

    /**
     * `YYYY-MM` for a timestamp column. MySQL and SQLite disagree on how to
     * format a date and this application runs on both.
     */
    private function monthKey(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m', {$column})",
            'pgsql' => "to_char({$column}, 'YYYY-MM')",
            'sqlsrv' => "FORMAT({$column}, 'yyyy-MM')",
            default => "DATE_FORMAT({$column}, '%Y-%m')",
        };
    }
}
