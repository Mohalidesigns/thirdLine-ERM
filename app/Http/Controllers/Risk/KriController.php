<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Concerns\PersistsConfiguredAttributes;
use App\Http\Controllers\Controller;
use App\Models\KeyRiskIndicator;
use App\Models\KriMeasurement;
use App\Models\MeasureBreach;
use App\Models\Risk;
use App\Models\User;
use App\Services\KriMeasureBridge;
use App\Services\MeasureService;
use App\Services\PeriodService;
use App\Support\Periods\PeriodContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class KriController extends Controller
{
    // WP-05 TASK 2 — receives the fields a tenant added through the
    // builder. Without it, a configured field would render on the form,
    // accept what was typed, and discard it on submit.
    use PersistsConfiguredAttributes;

    public function __construct(
        private KriMeasureBridge $bridge,
        private MeasureService $measures,
        private PeriodService $periods,
    ) {}

    /**
     * KRI monitoring dashboard with traffic lights.
     */
    public function dashboard()
    {
        $orgId = TenantContext::organizationId();

        $kris = KeyRiskIndicator::where('organization_id', $orgId)
            ->with(['risk', 'latestMeasurement'])
            ->orderByRaw("FIELD(current_status, 'red', 'amber', 'yellow', 'green') ASC")
            ->get();

        $totalKris = $kris->count();
        $redCount = $kris->where('current_status', 'red')->count();
        $amberCount = $kris->where('current_status', 'amber')->count();
        $yellowCount = $kris->where('current_status', 'yellow')->count();
        $greenCount = $kris->where('current_status', 'green')->count();
        $activeBreaches = $redCount + $amberCount;

        $healthyCount = $greenCount + $yellowCount;
        $avgHealthScore = $totalKris > 0 ? (int) round(($healthyCount / $totalKris) * 100) : 0;

        $breachedKris = $kris->whereIn('current_status', ['red', 'amber'])->values();

        $recentBreaches = $breachedKris->take(10)->map(function ($kri) {
            $m = $kri->latestMeasurement;

            return (object) [
                'kri_id' => $kri->id,
                'kri_name' => $kri->name,
                'name' => $kri->name,
                'current_value' => $kri->current_value !== null
                    ? number_format((float) $kri->current_value, 2).($kri->unit ?? '')
                    : '-',
                'threshold_value' => $kri->red_threshold !== null
                    ? number_format((float) $kri->red_threshold, 2).($kri->unit ?? '')
                    : '-',
                'level' => $kri->current_status,
                'category' => $kri->category,
                'owner' => optional($kri->risk)->risk_owner_id,
                'breach_date' => optional($m)->measured_at ?? optional($m)->measurement_date,
            ];
        });

        $statusDistData = [
            'labels' => ['Green', 'Amber', 'Red'],
            'values' => [$greenCount + $yellowCount, $amberCount, $redCount],
        ];

        $trendLabels = [];
        $redTrend = [];
        $amberTrend = [];
        for ($i = 11; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $trendLabels[] = $month->format('M');
            $redTrend[] = KriMeasurement::whereHas('kri', fn ($q) => $q->where('organization_id', $orgId))
                ->whereYear('measurement_date', $month->year)
                ->whereMonth('measurement_date', $month->month)
                ->where('status', 'red')
                ->count();
            $amberTrend[] = KriMeasurement::whereHas('kri', fn ($q) => $q->where('organization_id', $orgId))
                ->whereYear('measurement_date', $month->year)
                ->whereMonth('measurement_date', $month->month)
                ->where('status', 'amber')
                ->count();
        }
        $breachTrendData = ['labels' => $trendLabels, 'red' => $redTrend, 'amber' => $amberTrend];

        return view('risk.kri.dashboard', compact(
            'kris', 'breachedKris', 'recentBreaches',
            'totalKris', 'redCount', 'amberCount', 'yellowCount', 'greenCount',
            'activeBreaches', 'avgHealthScore',
            'statusDistData', 'breachTrendData'
        ));
    }

    /**
     * Display the KRI library listing.
     */
    public function index(Request $request)
    {
        $orgId = TenantContext::organizationId();

        $query = KeyRiskIndicator::where('organization_id', $orgId)
            ->with(['risk.category', 'owner']);

        if ($request->filled('status')) {
            $query->where('current_status', $request->status);
        }

        if ($request->filled('risk_id')) {
            $query->where('risk_id', $request->risk_id);
        }

        if ($request->filled('category')) {
            $query->whereHas('risk.category', fn ($q) => $q->where('name', $request->category));
        }

        if ($request->filled('frequency')) {
            $query->where('measurement_frequency', $request->frequency);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('kri_code', 'like', "%{$search}%");
            });
        }

        $kris = $query->orderBy('kri_code')->paginate(25)->withQueryString();

        // KRIs have no category column — a KRI's category is that of its linked risk.
        $kris->getCollection()->each(function ($kri) {
            $kri->setAttribute('category', $kri->risk?->category?->name);
            $kri->setAttribute('frequency', $kri->measurement_frequency);
        });

        // KRIs whose latest measurement pushed them into red breach territory
        // (current_status is refreshed by recordMeasurement on every entry).
        $activeBreachCount = KeyRiskIndicator::where('organization_id', $orgId)
            ->where('current_status', 'red')
            ->count();

        // Distinct categories across the org's KRIs (via their linked risks)
        $categories = KeyRiskIndicator::where('key_risk_indicators.organization_id', $orgId)
            ->join('risks', 'risks.id', '=', 'key_risk_indicators.risk_id')
            ->join('risk_categories', 'risk_categories.id', '=', 'risks.category_id')
            ->distinct()
            ->orderBy('risk_categories.name')
            ->pluck('risk_categories.name');

        $risks = Risk::where('organization_id', $orgId)->orderBy('risk_code')->get();

        return view('risk.kri.index', compact('kris', 'risks', 'activeBreachCount', 'categories'));
    }

    /**
     * Show the form for creating a new KRI.
     */
    public function create()
    {
        $orgId = TenantContext::organizationId();

        $risks = Risk::where('organization_id', $orgId)
            ->where('status', 'active')
            ->orderBy('risk_code')
            ->get();
        $users = User::where('organization_id', $orgId)->orderBy('name')->get();

        return view('risk.kri.create', compact('risks', 'users'));
    }

    /**
     * Store a newly created KRI.
     */
    public function store(Request $request)
    {
        $orgId = TenantContext::organizationId();

        $validated = $request->validate([
            'risk_id' => 'required|exists:risks,id',
            'kri_name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'measurement_unit' => 'required|string|max:100',
            'measurement_frequency' => 'required|in:daily,weekly,monthly,quarterly',
            'data_source' => 'nullable|string|max:255',
            'kri_owner_id' => 'required|exists:users,id',
            'green_threshold' => 'nullable|numeric',
            'amber_threshold' => 'nullable|numeric',
            'red_threshold' => 'nullable|numeric',
            'direction' => 'required|in:higher_is_worse,lower_is_worse',
            'target_value' => 'nullable|numeric',
            'category' => 'nullable|string|max:100',
            'formula' => 'nullable|string|max:1000',
        ]);

        // Verify risk belongs to org
        Risk::where('id', $validated['risk_id'])
            ->where('organization_id', $orgId)
            ->firstOrFail();

        // Map single threshold values to min/max based on direction
        $direction = $validated['direction'];
        $green = $validated['green_threshold'] ?? null;
        $amber = $validated['amber_threshold'] ?? null;
        $red = $validated['red_threshold'] ?? null;
        unset($validated['green_threshold'], $validated['amber_threshold'], $validated['red_threshold']);

        if ($direction === 'higher_is_worse') {
            $validated['green_threshold_max'] = $green;
            $validated['amber_threshold_min'] = $green;
            $validated['amber_threshold_max'] = $red;
            $validated['red_threshold_min'] = $red;
        } else {
            $validated['green_threshold_min'] = $green;
            $validated['amber_threshold_min'] = $red;
            $validated['amber_threshold_max'] = $green;
            $validated['red_threshold_max'] = $red;
        }

        // Map formula to metric_formula column
        if (isset($validated['formula'])) {
            $validated['metric_formula'] = $validated['formula'];
            unset($validated['formula']);
        }

        // Category is not a DB column, remove before saving
        unset($validated['category']);

        // Auto-generate KRI code using ReferenceCodeService
        $kriCode = \App\Services\ReferenceCodeService::generate('key_risk_indicators', 'kri_code', 'KRI');

        $kri = KeyRiskIndicator::create(array_merge($validated, [
            'organization_id' => $orgId,
            'kri_code' => $kriCode,
            'current_status' => 'green',
            'is_active' => true,
            'created_by' => auth()->id(),
            // Populate original NOT NULL columns from alignment columns
            'name' => $validated['kri_name'],
            'unit_of_measure' => $validated['measurement_unit'],
            'owner_id' => $validated['kri_owner_id'],
            'threshold_direction' => $validated['direction'] === 'higher_is_worse' ? 'higher_worse' : 'lower_worse',
            'metric_formula' => $validated['metric_formula'] ?? '',
            'data_source' => $validated['data_source'] ?? '',
        ]));

        // The measure engine is the source of truth for the reading and the
        // bands from this release; key_risk_indicators is the facade for one
        // more (WP-04 TASK 3). Defining the measure here rather than lazily
        // means the KRI has bands before its first reading arrives.
        $this->bridge->syncDefinition($kri);

        // Fields the tenant added through the builder, if any.
        $this->saveConfiguredAttributes($request, $kri);

        // Audit trail
        \App\Services\AuditTrailService::record($kri, 'create');

        return redirect()->route('risk.kri.show', $kri)
            ->with('success', "KRI {$kriCode} has been created.");
    }

    /**
     * Display the specified KRI with measurement history.
     */
    public function show(KeyRiskIndicator $kri)
    {
        $orgId = TenantContext::organizationId();

        if ($kri->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this KRI.');
        }

        $kri->load(['risk', 'owner']);

        $measurements = KriMeasurement::where('kri_id', $kri->id)
            ->orderByDesc('measurement_date')
            ->paginate(20);

        // The chart reads the measure engine, which is period-indexed: twelve
        // periods ending at the one selected in the top bar, so moving the
        // selector moves the window rather than always showing "the last 24
        // rows in the table", which drifts with measurement frequency.
        $measure = $this->bridge->measureFor($kri);
        $anchor = PeriodContext::current()
            ?? $this->periods->current($this->bridge->periodTypeFor($kri), $orgId);
        $window = $this->periods->trailing($anchor, 12, $this->bridge->periodTypeFor($kri));
        $series = $this->bridge->series($kri, $window->pluck('id')->all());

        $threshold = $measure === null
            ? null
            : $this->measures->activeThreshold($measure, $this->measures->objectIdFor($kri), $anchor->end_date?->toDateString());
        $bands = $threshold === null ? [] : $this->measures->resolveBands($threshold);
        $bandBound = function (string $code, string $bound) use ($bands) {
            $band = collect($bands)->firstWhere('code', $code);

            return isset($band[$bound]) ? (float) $band[$bound] : null;
        };

        $historyData = [
            'labels' => $window->pluck('name')->values()->toArray(),
            'values' => $window->map(fn ($period) => $series[$period->id] ?? null)->values()->toArray(),
            // The band bounds actually in force for the anchor period, not the
            // legacy columns — those are now a mirror, not the source.
            'green' => $bandBound('green', 'max') ?? $bandBound('green', 'min'),
            'amber' => $bandBound('amber', 'max') ?? $bandBound('amber', 'min'),
            'red' => $bandBound('red', 'min') ?? $bandBound('red', 'max'),
            'bands' => $bands,
        ];

        $breaches = MeasureBreach::query()
            ->when($measure !== null, fn ($query) => $query->where('measure_id', $measure->id))
            ->when($measure === null, fn ($query) => $query->whereRaw('1 = 0'))
            ->with('acknowledgedBy', 'period')
            ->orderByDesc('breached_at')
            ->limit(20)
            ->get();

        return view('risk.kri.show', compact('kri', 'measurements', 'historyData', 'breaches', 'window'));
    }

    /**
     * Show the form for editing the specified KRI.
     */
    public function edit(KeyRiskIndicator $kri)
    {
        $orgId = TenantContext::organizationId();

        if ($kri->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this KRI.');
        }

        $risks = Risk::where('organization_id', $orgId)->orderBy('risk_code')->get();
        $users = User::where('organization_id', $orgId)->orderBy('name')->get();

        return view('risk.kri.edit', compact('kri', 'risks', 'users'));
    }

    /**
     * Update the specified KRI.
     */
    public function update(Request $request, KeyRiskIndicator $kri)
    {
        $orgId = TenantContext::organizationId();

        if ($kri->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this KRI.');
        }

        $validated = $request->validate([
            'kri_name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'measurement_unit' => 'required|string|max:100',
            'measurement_frequency' => 'required|in:daily,weekly,monthly,quarterly',
            'data_source' => 'nullable|string|max:255',
            'kri_owner_id' => 'required|exists:users,id',
            'green_threshold' => 'nullable|numeric',
            'amber_threshold' => 'nullable|numeric',
            'red_threshold' => 'nullable|numeric',
            'direction' => 'required|in:higher_is_worse,lower_is_worse',
            'target_value' => 'nullable|numeric',
            'is_active' => 'nullable|boolean',
            'formula' => 'nullable|string|max:1000',
        ]);

        // Map single threshold values to min/max based on direction
        $direction = $validated['direction'];
        $green = $validated['green_threshold'] ?? null;
        $amber = $validated['amber_threshold'] ?? null;
        $red = $validated['red_threshold'] ?? null;
        unset($validated['green_threshold'], $validated['amber_threshold'], $validated['red_threshold']);

        if ($direction === 'higher_is_worse') {
            $validated['green_threshold_max'] = $green;
            $validated['amber_threshold_min'] = $green;
            $validated['amber_threshold_max'] = $red;
            $validated['red_threshold_min'] = $red;
        } else {
            $validated['green_threshold_min'] = $green;
            $validated['amber_threshold_min'] = $red;
            $validated['amber_threshold_max'] = $green;
            $validated['red_threshold_max'] = $red;
        }

        if (isset($validated['formula'])) {
            $validated['metric_formula'] = $validated['formula'];
            unset($validated['formula']);
        }

        $original = $kri->getAttributes();

        $kri->update(array_merge($validated, [
            'is_active' => $validated['is_active'] ?? $kri->is_active,
            'updated_by' => auth()->id(),
            // Keep original NOT NULL columns in sync with alignment columns
            'name' => $validated['kri_name'],
            'unit_of_measure' => $validated['measurement_unit'],
            'owner_id' => $validated['kri_owner_id'],
            'threshold_direction' => $validated['direction'] === 'higher_is_worse' ? 'higher_worse' : 'lower_worse',
        ]));

        // A threshold edit writes a NEW effective-dated band set and closes the
        // old one, so a breach recorded last month still reads against the
        // limit that was in force last month.
        $this->bridge->syncDefinition($kri);

        $this->saveConfiguredAttributes($request, $kri);

        // Audit trail
        \App\Services\AuditTrailService::recordChanges($kri, $original);

        return redirect()->route('risk.kri.show', $kri)
            ->with('success', "KRI {$kri->kri_code} has been updated.");
    }

    /**
     * Delete the specified KRI.
     */
    public function destroy(KeyRiskIndicator $kri)
    {
        $orgId = TenantContext::organizationId();

        if ($kri->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this KRI.');
        }

        $code = $kri->kri_code;
        $kri->delete();

        return redirect()->route('risk.kri.index')
            ->with('success', "KRI {$code} has been deleted.");
    }

    /**
     * Record a new KRI measurement.
     */
    public function recordMeasurement(Request $request, KeyRiskIndicator $kri)
    {
        $orgId = TenantContext::organizationId();

        if ($kri->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this KRI.');
        }

        $validated = $request->validate([
            'measurement_date' => 'required|date',
            'value' => 'required|numeric',
            'notes' => 'nullable|string|max:1000',
        ]);

        $original = $kri->getAttributes();

        // Through the engine: the reading lands in measure_values against the
        // period its date falls in, the band is resolved from the thresholds
        // that were in force then, and a crossing becomes a row in the breach
        // register rather than a notification nobody can acknowledge.
        $result = $this->bridge->recordMeasurement(
            $kri,
            $validated['measurement_date'],
            (float) $validated['value'],
            ['notes' => $validated['notes'] ?? null, 'entered_by' => auth()->id()]
        );

        $kri->refresh();

        // Audit trail
        \App\Services\AuditTrailService::recordChanges($kri, $original);

        $band = $result['band'];
        $message = "Measurement recorded for {$result['period']->name}.";

        if ($band !== null) {
            $message .= ' Status: '.strtoupper($band).'.';
        }

        if ($result['breach'] !== null && $result['breach']->wasRecentlyCreated) {
            $message .= ' A breach has been opened for acknowledgement.';
        }

        return back()->with($band === 'red' ? 'warning' : 'success', $message);
    }

    /* ------------------------------------------------------------------ */
    /*  Breach lifecycle */
    /* ------------------------------------------------------------------ */

    /**
     * Acknowledge an open breach.
     *
     * Acknowledgement is what makes mean time to acknowledge a real number, and
     * it is the difference between a breach register and a list of alerts.
     */
    public function acknowledgeBreach(Request $request, MeasureBreach $breach)
    {
        abort_unless($breach->organization_id === TenantContext::organizationId(), 403);

        $validated = $request->validate([
            'note' => 'nullable|string|max:2000',
            'root_cause' => 'nullable|string|max:2000',
        ]);

        if ($breach->status !== 'open') {
            return back()->with('error', 'Only an open breach can be acknowledged.');
        }

        $breach->update([
            'status' => 'acknowledged',
            'acknowledged_by' => auth()->id(),
            'acknowledged_at' => now(),
            'note' => $validated['note'] ?? $breach->note,
            'root_cause' => $validated['root_cause'] ?? $breach->root_cause,
        ]);

        \App\Services\AuditTrailService::record($breach, 'breach_acknowledged', 'status', 'open', 'acknowledged');

        return back()->with('success', 'Breach acknowledged.');
    }

    /**
     * Close a breach, either because it has been dealt with or because it was
     * never real.
     */
    public function resolveBreach(Request $request, MeasureBreach $breach)
    {
        abort_unless($breach->organization_id === TenantContext::organizationId(), 403);

        $validated = $request->validate([
            'outcome' => 'required|in:resolved,false_positive',
            'root_cause' => 'nullable|string|max:2000',
            'note' => 'nullable|string|max:2000',
        ]);

        if (in_array($breach->status, ['resolved', 'false_positive'], true)) {
            return back()->with('error', 'This breach is already closed.');
        }

        $previous = $breach->status;

        $breach->update([
            'status' => $validated['outcome'],
            'resolved_at' => now(),
            'root_cause' => $validated['root_cause'] ?? $breach->root_cause,
            'note' => $validated['note'] ?? $breach->note,
        ]);

        \App\Services\AuditTrailService::record($breach, 'breach_closed', 'status', $previous, $validated['outcome']);

        return back()->with('success', 'Breach closed.');
    }

    /**
     * Manage KRI thresholds.
     */
    public function thresholds(Request $request)
    {
        $orgId = TenantContext::organizationId();

        $kris = KeyRiskIndicator::where('organization_id', $orgId)
            ->with('risk')
            ->orderBy('kri_code')
            ->paginate(25);

        return view('risk.kri.thresholds', compact('kris'));
    }

    /**
     * List all KRI breaches.
     */
    public function breaches(Request $request)
    {
        $orgId = TenantContext::organizationId();

        // The breach REGISTER, not a filtered list of readings. Before WP-04
        // this screen inferred breaches by re-reading kri_measurements every
        // time it loaded, which meant there was nothing to acknowledge, nothing
        // to assign a root cause to, and no way to compute how long a breach
        // had taken to clear.
        $query = MeasureBreach::query()
            ->where('measure_breaches.organization_id', $orgId)
            ->with(['measure.unit', 'measure.keyRiskIndicator.risk.category', 'measure.owner', 'period', 'acknowledgedBy']);

        // Open and acknowledged by default: a register whose default view is
        // "everything that ever happened" is a log, not a work list.
        $status = $request->input('status', 'active');

        match ($status) {
            'all' => null,
            'closed' => $query->whereIn('measure_breaches.status', ['resolved', 'false_positive']),
            default => $query->whereIn('measure_breaches.status', ['open', 'acknowledged']),
        };

        if ($request->filled('level')) {
            $query->where('band_to', $request->level);
        }

        if ($request->filled('category')) {
            $query->whereHas('measure.keyRiskIndicator.risk.category', fn ($q) => $q->where('name', $request->category));
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('measure', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%");
            });
        }

        $breaches = $query->orderByDesc('breached_at')->paginate(25)->withQueryString();

        // Display fields the existing table expects, derived from the register.
        $breaches->getCollection()->transform(function (MeasureBreach $breach) {
            $measure = $breach->measure;
            $unit = $measure?->unit?->symbol ?? '';
            $suffix = $unit !== '' ? ' '.$unit : '';

            $breach->setAttribute('kri_name', $measure?->name);
            $breach->setAttribute('kri_code', $measure?->code);
            // The table links back to the KRI screen, which is keyed on the
            // facade table rather than the measure.
            $breach->setAttribute('kri_id', $measure?->keyRiskIndicator?->id);
            $breach->setAttribute('category', $measure?->keyRiskIndicator?->risk?->category?->name);
            $breach->setAttribute('current_value', number_format((float) $breach->value, $measure?->decimal_places ?? 2).$suffix);
            $breach->setAttribute('threshold_value', $breach->threshold_value === null
                ? null
                : number_format((float) $breach->threshold_value, $measure?->decimal_places ?? 2).$suffix);
            $breach->setAttribute('level', $breach->band_to);
            $breach->setAttribute('days_in_breach', (int) abs(
                ($breach->resolved_at ?? now())->diffInDays($breach->breached_at)
            ));
            $breach->setAttribute('owner', $measure?->owner?->name);
            $breach->setAttribute('breach_date', $breach->breached_at);

            return $breach;
        });

        $open = MeasureBreach::query()->where('organization_id', $orgId)->open();

        $redBreaches = (clone $open)->where('band_to', 'red')->count();
        $amberBreaches = (clone $open)->where('band_to', 'amber')->count();

        $openBreaches = (clone $open)->get(['breached_at']);
        $avgDaysInBreach = $openBreaches->isEmpty()
            ? 0
            : (int) round($openBreaches->avg(fn (MeasureBreach $breach) => abs(now()->diffInDays($breach->breached_at))));

        // Mean time to resolve, computed from resolved rows rather than stored.
        $mttrHours = MeasureBreach::meanTimeToResolveHours(
            MeasureBreach::query()->where('organization_id', $orgId)
        );

        $unacknowledged = (clone $open)->where('measure_breaches.status', 'open')->count();

        $categories = KeyRiskIndicator::where('key_risk_indicators.organization_id', $orgId)
            ->join('risks', 'risks.id', '=', 'key_risk_indicators.risk_id')
            ->join('risk_categories', 'risk_categories.id', '=', 'risks.category_id')
            ->distinct()
            ->orderBy('risk_categories.name')
            ->pluck('risk_categories.name');

        return view('risk.kri.breaches', compact(
            'breaches', 'redBreaches', 'amberBreaches', 'avgDaysInBreach',
            'mttrHours', 'unacknowledged', 'categories', 'status'
        ));
    }

    /**
     * Update KRI thresholds in bulk.
     */
    public function updateThresholds(Request $request)
    {
        $orgId = TenantContext::organizationId();

        foreach ($request->input('thresholds', []) as $kriId => $thresholds) {
            KeyRiskIndicator::where('id', $kriId)
                ->where('organization_id', $orgId)
                ->update([
                    'green_threshold_min' => $thresholds['green_min'] ?? null,
                    'green_threshold_max' => $thresholds['green_max'] ?? null,
                    'amber_threshold_min' => $thresholds['amber_min'] ?? null,
                    'amber_threshold_max' => $thresholds['amber_max'] ?? null,
                    'red_threshold_min' => $thresholds['red_min'] ?? null,
                    'red_threshold_max' => $thresholds['red_max'] ?? null,
                ]);
        }

        return redirect()->route('risk.kri.thresholds')->with('success', 'Thresholds updated.');
    }
}
