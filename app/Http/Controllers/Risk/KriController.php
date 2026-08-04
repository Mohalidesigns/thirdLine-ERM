<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\KeyRiskIndicator;
use App\Models\KriMeasurement;
use App\Models\Risk;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class KriController extends Controller
{
    /**
     * KRI monitoring dashboard with traffic lights.
     */
    public function dashboard()
    {
        $orgId = auth()->user()->organization_id ?? 1;

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
                    ? number_format((float) $kri->current_value, 2) . ($kri->unit ?? '')
                    : '-',
                'threshold_value' => $kri->red_threshold !== null
                    ? number_format((float) $kri->red_threshold, 2) . ($kri->unit ?? '')
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
            $redTrend[] = KriMeasurement::whereHas('kri', fn($q) => $q->where('organization_id', $orgId))
                ->whereYear('measurement_date', $month->year)
                ->whereMonth('measurement_date', $month->month)
                ->where('status', 'red')
                ->count();
            $amberTrend[] = KriMeasurement::whereHas('kri', fn($q) => $q->where('organization_id', $orgId))
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
        $orgId = auth()->user()->organization_id ?? 1;

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
        $orgId = auth()->user()->organization_id ?? 1;

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
        $orgId = auth()->user()->organization_id ?? 1;

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
        $orgId = auth()->user()->organization_id ?? 1;

        if ($kri->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this KRI.');
        }

        $kri->load(['risk', 'owner']);

        $measurements = KriMeasurement::where('kri_id', $kri->id)
            ->orderByDesc('measurement_date')
            ->paginate(20);

        // Build chart data from recent measurements (last 12 months)
        $chartMeasurements = KriMeasurement::where('kri_id', $kri->id)
            ->orderBy('measurement_date')
            ->limit(24)
            ->get();

        $historyData = [
            'labels' => $chartMeasurements->pluck('measurement_date')->map(fn ($d) => $d?->format('M Y') ?? '')->toArray(),
            'values' => $chartMeasurements->pluck('value')->map(fn ($v) => (float) $v)->toArray(),
            'green'  => (float) ($kri->green_threshold_max ?? 0),
            'amber'  => (float) ($kri->amber_threshold_max ?? 0),
            'red'    => (float) ($kri->red_threshold_min ?? 0),
        ];

        return view('risk.kri.show', compact('kri', 'measurements', 'historyData'));
    }

    /**
     * Show the form for editing the specified KRI.
     */
    public function edit(KeyRiskIndicator $kri)
    {
        $orgId = auth()->user()->organization_id ?? 1;

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
        $orgId = auth()->user()->organization_id ?? 1;

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
        $orgId = auth()->user()->organization_id ?? 1;

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
        $orgId = auth()->user()->organization_id ?? 1;

        if ($kri->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this KRI.');
        }

        $validated = $request->validate([
            'measurement_date' => 'required|date',
            'value' => 'required|numeric',
            'notes' => 'nullable|string|max:1000',
        ]);

        // Determine status based on thresholds
        $status = $this->determineStatus($kri, $validated['value']);

        $measurement = KriMeasurement::create([
            'kri_id' => $kri->id,
            'measurement_date' => $validated['measurement_date'],
            'value' => $validated['value'],
            'status' => $status,
            'notes' => $validated['notes'] ?? null,
            'entered_by' => auth()->id(),
        ]);

        // Update KRI current status and value
        $original = $kri->getAttributes();

        $kri->update([
            'current_value' => $validated['value'],
            'current_status' => $status,
            'last_measurement_at' => $validated['measurement_date'],
            'last_measurement_date' => $validated['measurement_date'],
        ]);

        // Audit trail
        \App\Services\AuditTrailService::recordChanges($kri, $original);

        $statusLabel = strtoupper($status);
        return back()->with('success', "Measurement recorded. Current status: {$statusLabel}.");
    }

    /**
     * Manage KRI thresholds.
     */
    public function thresholds(Request $request)
    {
        $orgId = auth()->user()->organization_id ?? 1;

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
        $orgId = auth()->user()->organization_id ?? 1;

        $query = KriMeasurement::whereHas('kri', function ($q) use ($orgId) {
                $q->where('organization_id', $orgId);
            })
            ->whereIn('status', ['red', 'amber'])
            ->with(['kri.risk.category', 'kri.owner']);

        if ($request->filled('level')) {
            $query->where('status', $request->level);
        }

        if ($request->filled('category')) {
            $query->whereHas('kri.risk.category', fn ($q) => $q->where('name', $request->category));
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('kri', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('kri_code', 'like', "%{$search}%");
            });
        }

        $breaches = $query->orderByDesc('measurement_date')->paginate(25)->withQueryString();

        // Enrich each measurement row with the display fields the table expects.
        $breaches->getCollection()->transform(function ($m) {
            $kri = $m->kri;
            $unit = $kri?->unit_of_measure ?? '';
            $higherWorse = in_array($kri?->threshold_direction, ['higher_worse', 'higher_is_worse'])
                || ($kri?->direction === 'higher_is_worse');

            $threshold = $m->status === 'red'
                ? ($higherWorse ? $kri?->red_threshold_min : $kri?->red_threshold_max)
                : ($higherWorse ? $kri?->amber_threshold_min : $kri?->amber_threshold_max);

            $m->setAttribute('kri_name', $kri?->name);
            $m->setAttribute('category', $kri?->risk?->category?->name);
            $m->setAttribute('current_value', number_format((float) $m->value, 2) . ($unit !== '' ? ' ' . $unit : ''));
            $m->setAttribute('threshold_value', $threshold !== null
                ? number_format((float) $threshold, 2) . ($unit !== '' ? ' ' . $unit : '')
                : null);
            $m->setAttribute('level', $m->status);
            $m->setAttribute('days_in_breach', $m->measurement_date
                ? (int) abs(now()->diffInDays($m->measurement_date))
                : 0);
            $m->setAttribute('owner', $kri?->owner?->name);
            $m->setAttribute('breach_date', $m->measurement_date);

            return $m;
        });

        // Summary KPIs — KRIs currently in breach (status maintained by recordMeasurement)
        $redBreaches = KeyRiskIndicator::where('organization_id', $orgId)
            ->where('current_status', 'red')->count();
        $amberBreaches = KeyRiskIndicator::where('organization_id', $orgId)
            ->where('current_status', 'amber')->count();

        // Average days each currently-breached KRI has been in breach: walk back
        // through its measurement history until the last non-breach reading.
        $breachedKris = KeyRiskIndicator::where('organization_id', $orgId)
            ->whereIn('current_status', ['red', 'amber'])
            ->with('measurements')
            ->get();

        $breachDays = [];
        foreach ($breachedKris as $kri) {
            $breachStart = null;
            foreach ($kri->measurements->sortByDesc('measurement_date')->values() as $measurement) {
                if (! in_array($measurement->status, ['red', 'amber'])) {
                    break;
                }
                $breachStart = $measurement->measurement_date;
            }
            $breachStart = $breachStart ?? $kri->last_measurement_date ?? $kri->updated_at;
            if ($breachStart) {
                $breachDays[] = (int) abs(now()->diffInDays($breachStart));
            }
        }
        $avgDaysInBreach = count($breachDays) > 0
            ? (int) round(array_sum($breachDays) / count($breachDays))
            : 0;

        // Distinct categories for the filter dropdown (via linked risks)
        $categories = KeyRiskIndicator::where('key_risk_indicators.organization_id', $orgId)
            ->join('risks', 'risks.id', '=', 'key_risk_indicators.risk_id')
            ->join('risk_categories', 'risk_categories.id', '=', 'risks.category_id')
            ->distinct()
            ->orderBy('risk_categories.name')
            ->pluck('risk_categories.name');

        return view('risk.kri.breaches', compact(
            'breaches', 'redBreaches', 'amberBreaches', 'avgDaysInBreach', 'categories'
        ));
    }

    /**
     * Update KRI thresholds in bulk.
     */
    public function updateThresholds(Request $request)
    {
        $orgId = auth()->user()->organization_id ?? 1;

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

    /**
     * Determine KRI status based on thresholds and direction.
     */
    private function determineStatus(KeyRiskIndicator $kri, float $value): string
    {
        if ($kri->direction === 'higher_is_worse') {
            if ($kri->red_threshold_min !== null && $value >= $kri->red_threshold_min) {
                return 'red';
            }
            if ($kri->amber_threshold_min !== null && $value >= $kri->amber_threshold_min) {
                return 'amber';
            }
            if ($kri->green_threshold_max !== null && $value <= $kri->green_threshold_max) {
                return 'green';
            }
            return 'yellow';
        } else {
            // lower_is_worse
            if ($kri->red_threshold_max !== null && $value <= $kri->red_threshold_max) {
                return 'red';
            }
            if ($kri->amber_threshold_max !== null && $value <= $kri->amber_threshold_max) {
                return 'amber';
            }
            if ($kri->green_threshold_min !== null && $value >= $kri->green_threshold_min) {
                return 'green';
            }
            return 'yellow';
        }
    }
}
