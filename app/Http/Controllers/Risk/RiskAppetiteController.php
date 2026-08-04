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
            // Prefer the recorded metric position; fall back to the average
            // residual score of active risks in the category.
            $current = $a->current_position !== null
                ? (float) $a->current_position
                : (float) \App\Models\Risk::where('organization_id', $orgId)
                    ->where('category_id', $a->risk_category_id)
                    ->where('status', 'active')
                    ->avg('residual_score');

            $targetMax = (float) ($a->target_max ?? 0);
            $maxTolerance = (float) ($a->max_tolerance ?? 0);
            if ($maxTolerance <= 0) {
                $status = 'within';
            } elseif ($current > $maxTolerance) {
                $status = 'breach';
            } elseif ($targetMax > 0 && $current > $targetMax) {
                $status = 'near_limit';
            } elseif ($current > ($maxTolerance * 0.85)) {
                $status = 'near_limit';
            } else {
                $status = 'within';
            }

            return (object) [
                'id' => $a->id,
                'appetite' => $a,
                'risk_category' => optional($a->category)->name ?? 'Uncategorised',
                'appetite_statement' => $a->appetite_statement,
                'metric_name' => $a->tolerance_metric ?? 'Avg. residual score',
                'lower_limit' => number_format((float) ($a->target_min ?? 0), 1),
                'upper_limit' => number_format($maxTolerance, 1),
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

        $approvalDate = optional($appetites->min('approved_date') ?? $appetites->min('effective_date'))->format('d M Y') ?? now()->subMonths(3)->format('d M Y');
        $nextReviewDate = optional($appetites->min('expiry_date'))->format('d M Y') ?? now()->addMonths(3)->format('d M Y');

        $appetiteChartData = [
            'labels' => $appetiteMetrics->pluck('risk_category')->toArray(),
            'appetite' => $appetites->pluck('target_max')->map(fn($v) => (float) $v)->toArray(),
            'current' => $appetiteMetrics->pluck('current_value')->map(fn($v) => (float) str_replace(',', '', $v))->toArray(),
            'limit' => $appetites->pluck('max_tolerance')->map(fn($v) => (float) $v)->toArray(),
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
            'appetite_level' => 'required|in:averse,minimal,low,cautious,moderate,open,high,hungry',
            'appetite_statement' => 'required|string|max:2000',
            'tolerance_metric' => 'required|string|max:255',
            'unit_of_measure' => 'nullable|string|max:100',
            'target_min' => 'required|numeric|min:0',
            'target_max' => 'required|numeric|gte:target_min',
            'max_tolerance' => 'required|numeric|gte:target_max',
            'current_position' => 'nullable|numeric|min:0',
            'effective_date' => 'required|date',
            'expiry_date' => 'nullable|date|after:effective_date',
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
            'appetite_level' => 'required|in:averse,minimal,low,cautious,moderate,open,high,hungry',
            'appetite_statement' => 'required|string|max:2000',
            'tolerance_metric' => 'required|string|max:255',
            'unit_of_measure' => 'nullable|string|max:100',
            'target_min' => 'required|numeric|min:0',
            'target_max' => 'required|numeric|gte:target_min',
            'max_tolerance' => 'required|numeric|gte:target_max',
            'current_position' => 'nullable|numeric|min:0',
            'effective_date' => 'required|date',
            'expiry_date' => 'nullable|date|after:effective_date',
        ]);

        $original = $appetite->getAttributes();

        $appetite->update($validated);

        // Audit trail
        \App\Services\AuditTrailService::recordChanges($appetite, $original);

        return redirect()->route('risk.appetite.index')
            ->with('success', 'Risk appetite statement has been updated.');
    }
}
