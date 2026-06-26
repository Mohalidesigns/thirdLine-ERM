<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\RiskAppetite;
use App\Models\RiskCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RiskAppetiteController extends Controller
{
    /**
     * Display the risk appetite framework view.
     */
    public function index()
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $appetites = RiskAppetite::where('organization_id', $orgId)
            ->with('category')
            ->orderBy('risk_category_id')
            ->get();

        $categories = RiskCategory::where('organization_id', $orgId)
            ->orderBy('name')
            ->get();

        $categoriesWithAppetite = $appetites->pluck('risk_category_id')->toArray();
        $categoriesWithoutAppetite = $categories->whereNotIn('id', $categoriesWithAppetite);

        $appetiteMetrics = $appetites->map(function ($a) use ($orgId) {
            $current = (float) \App\Models\Risk::where('organization_id', $orgId)
                ->where('category_id', $a->risk_category_id)
                ->where('status', 'active')
                ->avg('residual_score');

            $lower = (float) ($a->tolerance_lower ?? 0);
            $upper = (float) ($a->tolerance_upper ?? 0);
            if ($upper <= 0) {
                $status = 'within';
            } elseif ($current > $upper) {
                $status = 'breach';
            } elseif ($current > ($upper * 0.85)) {
                $status = 'near_limit';
            } else {
                $status = 'within';
            }

            return (object) [
                'risk_category' => optional($a->category)->name ?? 'Uncategorised',
                'appetite_statement' => $a->appetite_statement,
                'metric_name' => 'Avg. residual score',
                'lower_limit' => number_format($lower, 1),
                'upper_limit' => number_format($upper, 1),
                'current_value' => number_format($current, 1),
                'status' => $status,
                'trend' => $status === 'breach' ? 'up' : ($status === 'within' ? 'down' : 'flat'),
            ];
        });

        $totalMetrics = $appetiteMetrics->count();
        $withinTolerance = $appetiteMetrics->where('status', 'within')->count();
        $nearLimit = $appetiteMetrics->where('status', 'near_limit')->count();
        $appetiteBreaches = $appetiteMetrics->where('status', 'breach')->count();
        $overallStatus = $appetiteBreaches > 0 ? 'Breach'
            : ($nearLimit > 0 ? 'Near Limit' : 'Within Appetite');

        $approvalDate = optional($appetites->min('effective_date'))->format('d M Y') ?? now()->subMonths(3)->format('d M Y');
        $nextReviewDate = optional($appetites->min('review_date'))->format('d M Y') ?? now()->addMonths(3)->format('d M Y');

        $appetiteChartData = [
            'labels' => $appetiteMetrics->pluck('risk_category')->toArray(),
            'appetite' => $appetites->pluck('tolerance_lower')->map(fn($v) => (float) $v)->toArray(),
            'current' => $appetiteMetrics->pluck('current_value')->map(fn($v) => (float) str_replace(',', '', $v))->toArray(),
            'limit' => $appetites->pluck('tolerance_upper')->map(fn($v) => (float) $v)->toArray(),
        ];

        return view('risk.appetite.index', compact(
            'appetites', 'categories', 'categoriesWithoutAppetite',
            'appetiteMetrics', 'totalMetrics', 'withinTolerance', 'nearLimit', 'appetiteBreaches',
            'overallStatus', 'approvalDate', 'nextReviewDate', 'appetiteChartData'
        ));
    }

    /**
     * Store a new risk appetite statement.
     */
    public function store(Request $request)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $validated = $request->validate([
            'risk_category_id' => 'required|exists:risk_categories,id',
            'appetite_level' => 'required|in:averse,minimal,cautious,open,hungry',
            'appetite_statement' => 'required|string|max:2000',
            'tolerance_lower' => 'required|numeric|min:0',
            'tolerance_upper' => 'required|numeric|gte:tolerance_lower',
            'capacity' => 'nullable|numeric|min:0',
            'key_metrics' => 'nullable|string|max:2000',
            'escalation_triggers' => 'nullable|string|max:2000',
            'approved_by' => 'nullable|string|max:255',
            'effective_date' => 'required|date',
            'review_date' => 'nullable|date|after:effective_date',
        ]);

        // Check for existing appetite for same category
        $existing = RiskAppetite::where('organization_id', $orgId)
            ->where('risk_category_id', $validated['risk_category_id'])
            ->first();

        if ($existing) {
            return back()->with('error', 'A risk appetite statement already exists for this category. Please update it instead.')
                ->withInput();
        }

        $appetite = RiskAppetite::create(array_merge($validated, [
            'organization_id' => $orgId,
            'created_by' => auth()->id(),
        ]));

        // Audit trail
        \App\Services\AuditTrailService::record($appetite, 'create');

        return redirect()->route('risk.appetite.index')
            ->with('success', 'Risk appetite statement has been created.');
    }

    /**
     * Update an existing risk appetite statement.
     */
    public function update(Request $request, RiskAppetite $appetite)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        if ($appetite->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this risk appetite statement.');
        }

        $validated = $request->validate([
            'appetite_level' => 'required|in:averse,minimal,cautious,open,hungry',
            'appetite_statement' => 'required|string|max:2000',
            'tolerance_lower' => 'required|numeric|min:0',
            'tolerance_upper' => 'required|numeric|gte:tolerance_lower',
            'capacity' => 'nullable|numeric|min:0',
            'key_metrics' => 'nullable|string|max:2000',
            'escalation_triggers' => 'nullable|string|max:2000',
            'approved_by' => 'nullable|string|max:255',
            'effective_date' => 'required|date',
            'review_date' => 'nullable|date|after:effective_date',
        ]);

        $original = $appetite->getAttributes();

        $appetite->update(array_merge($validated, [
            'updated_by' => auth()->id(),
        ]));

        // Audit trail
        \App\Services\AuditTrailService::recordChanges($appetite, $original);

        return redirect()->route('risk.appetite.index')
            ->with('success', 'Risk appetite statement has been updated.');
    }
}
