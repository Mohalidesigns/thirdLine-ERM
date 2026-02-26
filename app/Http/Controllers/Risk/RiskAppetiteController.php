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

        // Determine which categories have appetite statements and which don't
        $categoriesWithAppetite = $appetites->pluck('risk_category_id')->toArray();
        $categoriesWithoutAppetite = $categories->whereNotIn('id', $categoriesWithAppetite);

        return view('risk.appetite.index', compact('appetites', 'categories', 'categoriesWithoutAppetite'));
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
