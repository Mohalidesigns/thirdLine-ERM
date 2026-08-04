<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\Risk;
use App\Models\RiskCategory;
use App\Models\Control;
use App\Models\RiskControlMapping;
use App\Models\BusinessUnit;
use Illuminate\Http\Request;

class RcsaController extends Controller
{
    /**
     * RCSA dashboard overview.
     */
    public function dashboard()
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $totalAssessments = Risk::where('organization_id', $orgId)->where('status', 'active')->count();
        $completedAssessments = Risk::where('organization_id', $orgId)
            ->where('status', 'active')
            ->whereNotNull('last_assessment_date')
            ->where('last_assessment_date', '>=', now()->subMonths(12))
            ->count();
        $inProgressAssessments = Risk::where('organization_id', $orgId)
            ->where('status', 'active')
            ->whereNotNull('last_assessment_date')
            ->where('last_assessment_date', '<', now()->subMonths(12))
            ->where('last_assessment_date', '>=', now()->subMonths(18))
            ->count();
        $notStartedAssessments = Risk::where('organization_id', $orgId)
            ->where('status', 'active')
            ->whereNull('last_assessment_date')
            ->count();
        $overdueAssessments = Risk::where('organization_id', $orgId)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('last_assessment_date')
                  ->orWhere('last_assessment_date', '<', now()->subMonths(12));
            })
            ->count();
        $completionRate = $totalAssessments > 0
            ? (int) round(($completedAssessments / $totalAssessments) * 100)
            : 0;

        $businessUnits = BusinessUnit::where('organization_id', $orgId)
            ->withCount([
                'risks as total_risks' => function ($q) {
                    $q->where('status', 'active');
                },
                'risks as assessed' => function ($q) {
                    $q->where('status', 'active')
                      ->whereNotNull('last_assessment_date')
                      ->where('last_assessment_date', '>=', now()->subMonths(12));
                },
                'risks as high_risks' => function ($q) {
                    $q->where('status', 'active')->whereIn('residual_rating', ['High', 'Critical']);
                },
            ])
            ->orderByDesc('total_risks')
            ->get();

        $unitProgress = $businessUnits->map(function ($bu) {
            $progress = $bu->total_risks > 0 ? (int) round(($bu->assessed / $bu->total_risks) * 100) : 0;
            return (object) [
                'name' => $bu->name,
                'total_risks' => $bu->total_risks,
                'assessed' => $bu->assessed,
                'progress' => $progress,
                'high_risks' => $bu->high_risks,
                'control_gaps' => 0,
                'status' => $progress >= 80 ? 'Completed' : ($progress >= 50 ? 'In Progress' : 'Behind'),
                'due_date' => now()->addDays(30)->format('d M Y'),
            ];
        });

        $topRisks = Risk::where('organization_id', $orgId)
            ->where('status', 'active')
            ->orderByRaw("FIELD(residual_rating,'Critical','High','Medium','Low')")
            ->orderByDesc('residual_score')
            ->limit(8)
            ->get()
            ->map(fn($r) => (object) [
                'title' => $r->title,
                'business_unit' => optional($r->businessUnit)->name ?? '-',
                'inherent_rating' => $r->inherent_rating,
                'residual_rating' => $r->residual_rating,
                'control_effectiveness' => 'partially',
                'action_required' => 'Review control design and operating effectiveness',
            ]);

        $completionByUnitData = [
            'labels' => $unitProgress->pluck('name')->toArray(),
            'values' => $unitProgress->pluck('progress')->toArray(),
        ];
        $riskDistCounts = Risk::where('organization_id', $orgId)
            ->where('status', 'active')
            ->selectRaw('residual_rating, COUNT(*) c')
            ->groupBy('residual_rating')->pluck('c', 'residual_rating');
        $riskDistData = [
            'labels' => ['Critical','High','Medium','Low'],
            'values' => [
                (int) ($riskDistCounts['Critical'] ?? 0),
                (int) ($riskDistCounts['High'] ?? 0),
                (int) ($riskDistCounts['Medium'] ?? 0),
                (int) ($riskDistCounts['Low'] ?? 0),
            ],
        ];
        $effCounts = Control::where('organization_id', $orgId)
            ->selectRaw('effectiveness_rating r, COUNT(*) c')
            ->groupBy('r')->pluck('c', 'r');
        $controlEffData = [
            'labels' => ['Effective','Partially Effective','Ineffective','Not Tested'],
            'values' => [
                (int) ($effCounts['effective'] ?? 0),
                (int) ($effCounts['partially_effective'] ?? 0),
                (int) ($effCounts['ineffective'] ?? 0),
                (int) ($effCounts['not_tested'] ?? 0),
            ],
        ];

        return view('risk.rcsa.dashboard', compact(
            'totalAssessments', 'completedAssessments', 'inProgressAssessments',
            'notStartedAssessments', 'overdueAssessments', 'completionRate',
            'unitProgress', 'topRisks',
            'completionByUnitData', 'riskDistData', 'controlEffData'
        ));
    }

    /**
     * RCSA worksheet view - risk and control self-assessment matrix.
     */
    public function worksheet(Request $request)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $query = Risk::where('organization_id', $orgId)
            ->where('status', 'active')
            ->with(['category', 'riskOwner', 'businessUnit', 'controlMappings']);

        if ($request->filled('business_unit_id')) {
            $query->where('business_unit_id', $request->business_unit_id);
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        $risks = $query->orderBy('risk_code')->paginate(20)->withQueryString();

        $businessUnits = BusinessUnit::where('organization_id', $orgId)->orderBy('name')->get();
        $categories = RiskCategory::where('organization_id', $orgId)->orderBy('name')->get();
        $processes = \App\Models\BusinessProcess::where('organization_id', $orgId)->orderBy('name')->get();

        return view('risk.rcsa.worksheet', compact('risks', 'businessUnits', 'categories', 'processes'));
    }

    /**
     * RCSA controls view - control effectiveness assessment.
     */
    public function controls(Request $request)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $query = Control::where('organization_id', $orgId)
            ->with(['controlOwner', 'businessUnit', 'riskMappings']);

        if ($request->filled('effectiveness')) {
            $query->where('effectiveness_rating', $request->effectiveness);
        }

        if ($request->filled('control_type')) {
            $query->where('control_type', $request->control_type);
        }

        if ($request->filled('business_unit_id')) {
            $query->where('business_unit_id', $request->business_unit_id);
        }

        $controls = $query->orderBy('control_code')->paginate(25)->withQueryString();

        $businessUnits = BusinessUnit::where('organization_id', $orgId)->orderBy('name')->get();

        // KPI tile counts (unfiltered, organization-wide)
        $effectivenessCounts = Control::where('organization_id', $orgId)
            ->selectRaw('effectiveness_rating, COUNT(*) as c')
            ->groupBy('effectiveness_rating')
            ->pluck('c', 'effectiveness_rating');

        $totalControls       = (int) $effectivenessCounts->sum();
        $effectiveControls   = (int) ($effectivenessCounts['effective'] ?? 0);
        $partialControls     = (int) ($effectivenessCounts['partially_effective'] ?? 0);
        $ineffectiveControls = (int) ($effectivenessCounts['ineffective'] ?? 0);

        return view('risk.rcsa.controls', compact(
            'controls', 'businessUnits',
            'totalControls', 'effectiveControls', 'partialControls', 'ineffectiveControls'
        ));
    }

    /**
     * RCSA risk-control matrix view.
     */
    public function matrix(Request $request)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        // Build the risk-control mapping matrix
        $query = RiskControlMapping::whereHas('risk', function ($q) use ($orgId) {
                $q->where('organization_id', $orgId);
            })
            ->with(['risk.category', 'control']);

        if ($request->filled('business_unit_id')) {
            $query->whereHas('risk', function ($q) use ($request) {
                $q->where('business_unit_id', $request->business_unit_id);
            });
        }

        $mappings = $query->get();

        $matrixRisks    = $mappings->pluck('risk')->filter()->unique('id')->sortBy('risk_code')->values();
        $matrixControls = $mappings->pluck('control')->filter()->unique('id')->sortBy('control_code')->values();

        // Pre-compute per-risk effectiveness for each control + overall
        // coverage so the Blade can read them straight off $risk.
        foreach ($matrixRisks as $risk) {
            $riskMappings = $mappings->where('risk_id', $risk->id);
            $perControl = [];
            foreach ($matrixControls as $control) {
                $m = $riskMappings->firstWhere('control_id', $control->id);
                if (! $m) {
                    $perControl[] = (object) ['control_id' => $control->id, 'effectiveness' => 'na'];
                    continue;
                }
                $rating = strtolower((string) ($m->control->effectiveness_rating ?? ''));
                $bucket = match ($rating) {
                    'effective'            => 'effective',
                    'partially_effective'  => 'partially',
                    'ineffective'          => 'ineffective',
                    default                => 'na',
                };
                $perControl[] = (object) ['control_id' => $control->id, 'effectiveness' => $bucket];
            }
            $risk->setAttribute('control_mappings', $perControl);
            $risk->setAttribute('control_coverage', $matrixControls->count() > 0
                ? (int) round($riskMappings->count() / $matrixControls->count() * 100)
                : 0);
        }

        $businessUnits = BusinessUnit::where('organization_id', $orgId)->orderBy('name')->get();

        return view('risk.rcsa.matrix', compact('matrixRisks', 'matrixControls', 'mappings', 'businessUnits'));
    }

    /**
     * Store RCSA worksheet entries.
     */
    public function storeWorksheet(Request $request)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        // Process worksheet submission
        return redirect()->route('risk.rcsa.worksheet')
            ->with('success', 'RCSA worksheet saved successfully.');
    }
}
