<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\Risk;
use App\Models\RiskCategory;
use App\Models\BusinessUnit;
use App\Models\User;
use App\Models\RiskAuditTrail;
use App\Models\Control;
use App\Models\RiskControlMapping;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RiskRegisterController extends Controller
{
    /**
     * Display the risk register listing with filters.
     */
    public function index(Request $request)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $query = Risk::where('organization_id', $orgId)
            ->with(['category', 'riskOwner', 'businessUnit']);

        // Apply filters
        if ($request->filled('category')) {
            $query->where('category_id', $request->category);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('rating')) {
            $query->where('inherent_rating', $request->rating);
        }

        if ($request->filled('business_unit')) {
            $query->where('business_unit_id', $request->business_unit);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('risk_code', 'like', "%{$search}%")
                  ->orWhere('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $sortBy = $request->get('sort', 'inherent_score');
        $sortDir = $request->get('direction', 'desc');
        $allowedSorts = ['risk_code', 'title', 'inherent_score', 'residual_score', 'status', 'created_at'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortDir === 'asc' ? 'asc' : 'desc');
        }

        $risks = $query->paginate(25)->withQueryString();

        // Filter options
        $categories = RiskCategory::where('organization_id', $orgId)->orderBy('name')->get();
        $businessUnits = BusinessUnit::where('organization_id', $orgId)->orderBy('name')->get();

        return view('risk.register.index', compact('risks', 'categories', 'businessUnits'));
    }

    /**
     * Show the form for creating a new risk.
     */
    public function create()
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $categories = RiskCategory::where('organization_id', $orgId)->orderBy('name')->get();
        $businessUnits = BusinessUnit::where('organization_id', $orgId)->orderBy('name')->get();
        $users = User::where('organization_id', $orgId)->orderBy('name')->get();
        $processes = \App\Models\BusinessProcess::where('organization_id', $orgId)->orderBy('name')->get();

        return view('risk.register.create', compact('categories', 'businessUnits', 'users', 'processes'));
    }

    /**
     * Store a newly created risk.
     */
    public function store(Request $request)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string|max:5000',
            'category_id' => 'required|exists:risk_categories,id',
            'business_unit_id' => 'required|exists:business_units,id',
            'process_id' => 'nullable|exists:business_processes,id',
            'risk_owner_id' => 'required|exists:users,id',
            'risk_steward_id' => 'nullable|exists:users,id',
            'identified_by' => 'nullable|exists:users,id',
            'date_identified' => 'nullable|date',
            'risk_source' => 'nullable|string|max:100',
            'risk_type' => 'nullable|in:strategic,operational,financial,compliance,technology,reputational',
            'inherent_likelihood' => 'required|integer|min:1|max:5',
            'impact_financial' => 'required|integer|min:1|max:5',
            'impact_operational' => 'required|integer|min:1|max:5',
            'impact_reputational' => 'required|integer|min:1|max:5',
            'impact_regulatory' => 'required|integer|min:1|max:5',
            'treatment_strategy' => 'nullable|in:mitigate,accept,transfer,avoid',
            'risk_velocity' => 'nullable|string|max:50',
            'review_frequency' => 'nullable|string|max:50',
            'financial_exposure' => 'nullable|numeric|min:0',
            'regulatory_tags' => 'nullable|array',
            'notes' => 'nullable|string|max:5000',
            'status' => 'nullable|in:active,dormant,closed,retired',
        ]);

        return DB::transaction(function () use ($validated, $orgId, $request) {
            // Auto-generate risk code using ReferenceCodeService
            $riskCode = \App\Services\ReferenceCodeService::generate('risks', 'risk_code', 'RK');

            // Use RiskScoringService for scoring calculation
            $scoringService = new \App\Services\RiskScoringService();
            $maxImpact = $scoringService->calculateMaxImpact(
                $validated['impact_financial'],
                $validated['impact_operational'],
                $validated['impact_reputational'],
                $validated['impact_regulatory']
            );
            $inherentScore = $scoringService->calculateScore($validated['inherent_likelihood'], $maxImpact);
            $inherentRating = $scoringService->calculateRating($inherentScore);

            $risk = Risk::create([
                'organization_id' => $orgId,
                'risk_code' => $riskCode,
                'title' => $validated['title'],
                'description' => $validated['description'],
                'category_id' => $validated['category_id'],
                'business_unit_id' => $validated['business_unit_id'],
                'process_id' => $validated['process_id'] ?? null,
                'risk_owner_id' => $validated['risk_owner_id'],
                'risk_steward_id' => $validated['risk_steward_id'] ?? null,
                'identified_by' => $validated['identified_by'] ?? null,
                'date_identified' => $validated['date_identified'] ?? null,
                'risk_source' => $validated['risk_source'] ?? 'Self-Identified',
                'risk_type' => $validated['risk_type'] ?? 'operational',
                'inherent_likelihood' => $validated['inherent_likelihood'],
                'inherent_impact' => $maxImpact,
                'inherent_impact_financial' => $validated['impact_financial'],
                'inherent_impact_operational' => $validated['impact_operational'],
                'inherent_impact_reputational' => $validated['impact_reputational'],
                'inherent_impact_regulatory' => $validated['impact_regulatory'],
                'inherent_score' => $inherentScore,
                'inherent_rating' => $inherentRating,
                'treatment_strategy' => $validated['treatment_strategy'] ?? null,
                'risk_velocity' => $validated['risk_velocity'] ?? null,
                'review_frequency' => $validated['review_frequency'] ?? null,
                'financial_exposure_ngn' => $validated['financial_exposure'] ?? null,
                'regulatory_mapping' => $validated['regulatory_tags'] ?? null,
                'appetite_notes' => $validated['notes'] ?? null,
                'status' => $validated['status'] ?? 'active',
                'created_by' => auth()->id(),
            ]);

            // Create audit trail entry
            RiskAuditTrail::create([
                'entity_type' => 'risk',
                'entity_id' => $risk->id,
                'organization_id' => $orgId,
                'action_type' => 'created',
                'field_changed' => 'all',
                'new_value' => json_encode($risk->toArray()),
                'changed_by' => auth()->id(),
                'changed_at' => now(),
            ]);

            return redirect()->route('risk.register.show', $risk)
                ->with('success', "Risk {$riskCode} has been created successfully.");
        });
    }

    /**
     * Display the specified risk with all related data.
     */
    public function show(Risk $register)
    {
        $risk = $register;
        $orgId = auth()->user()->organization_id ?? 1;

        // Ensure the risk belongs to the user's organization
        if ($risk->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this risk.');
        }

        $risk->load([
            'category',
            'riskOwner',
            'businessUnit',
            'assessments' => function ($q) {
                $q->orderByDesc('assessment_date')->limit(10);
            },
            'controlMappings',
            'treatmentPlans' => function ($q) {
                $q->orderByDesc('created_at');
            },
            'keyRiskIndicators' => function ($q) {
                $q->orderBy('kri_name');
            },
            'auditTrails' => function ($q) {
                $q->orderByDesc('changed_at')->limit(20);
            },
        ]);

        // Controls in the org that aren't already mapped to this risk — used
        // to populate the "Map Existing Control" dropdown on the Controls tab.
        $mappedControlIds = $risk->controlMappings->pluck('id')->all();
        $availableControls = Control::where('organization_id', $orgId)
            ->whereNotIn('id', $mappedControlIds)
            ->orderBy('control_code')
            ->get(['id', 'control_code', 'name']);

        return view('risk.register.show', compact('risk', 'availableControls'));
    }

    /**
     * Map an existing control to a risk (inline form on the Controls tab).
     */
    public function mapControl(Request $request, Risk $risk)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        if ($risk->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this risk.');
        }

        $validated = $request->validate([
            'control_id' => 'required|exists:controls,id',
            'is_key_control' => 'nullable|boolean',
            'control_weight' => 'nullable|numeric|min:0|max:100',
            'mapping_rationale' => 'nullable|string|max:1000',
        ]);

        $control = Control::where('id', $validated['control_id'])
            ->where('organization_id', $orgId)
            ->firstOrFail();

        // Idempotent: skip if mapping already exists.
        $exists = RiskControlMapping::where('risk_id', $risk->id)
            ->where('control_id', $control->id)
            ->exists();

        if ($exists) {
            return redirect()->route('risk.register.show', $risk)
                ->with('error', "{$control->control_code} is already mapped to this risk.");
        }

        RiskControlMapping::create([
            'risk_id' => $risk->id,
            'control_id' => $control->id,
            'is_key_control' => (bool) ($validated['is_key_control'] ?? false),
            'control_weight' => $validated['control_weight'] ?? null,
            'mapping_rationale' => $validated['mapping_rationale'] ?? null,
        ]);

        return redirect()->route('risk.register.show', $risk)
            ->with('success', "Control {$control->control_code} mapped successfully.");
    }

    /**
     * Show the form for editing the specified risk.
     */
    public function edit(Risk $register)
    {
        $risk = $register;
        $orgId = auth()->user()->organization_id ?? 1;

        if ($risk->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this risk.');
        }

        $categories = RiskCategory::where('organization_id', $orgId)->orderBy('name')->get();
        $businessUnits = BusinessUnit::where('organization_id', $orgId)->orderBy('name')->get();
        $users = User::where('organization_id', $orgId)->orderBy('name')->get();
        $processes = \App\Models\BusinessProcess::where('organization_id', $orgId)->orderBy('name')->get();

        return view('risk.register.edit', compact('risk', 'categories', 'businessUnits', 'users', 'processes'));
    }

    /**
     * Update the specified risk.
     */
    public function update(Request $request, Risk $register)
    {
        $risk = $register;
        $orgId = auth()->user()->organization_id ?? 1;

        if ($risk->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this risk.');
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string|max:5000',
            'category_id' => 'required|exists:risk_categories,id',
            'business_unit_id' => 'required|exists:business_units,id',
            'process_id' => 'nullable|exists:business_processes,id',
            'risk_owner_id' => 'required|exists:users,id',
            'risk_steward_id' => 'nullable|exists:users,id',
            'identified_by' => 'nullable|exists:users,id',
            'date_identified' => 'nullable|date',
            'risk_source' => 'nullable|string|max:100',
            'status' => 'required|in:active,dormant,closed,retired',
            'inherent_likelihood' => 'required|integer|min:1|max:5',
            'impact_financial' => 'required|integer|min:1|max:5',
            'impact_operational' => 'required|integer|min:1|max:5',
            'impact_reputational' => 'required|integer|min:1|max:5',
            'impact_regulatory' => 'required|integer|min:1|max:5',
            'treatment_strategy' => 'nullable|in:mitigate,accept,transfer,avoid',
            'risk_velocity' => 'nullable|string|max:50',
            'review_frequency' => 'nullable|string|max:50',
            'financial_exposure' => 'nullable|numeric|min:0',
            'regulatory_tags' => 'nullable|array',
            'appetite_category' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:5000',
        ]);

        return DB::transaction(function () use ($validated, $risk, $orgId) {
            $oldValues = $risk->toArray();

            // Derive inherent_impact as the max of all impact dimensions
            $inherentImpact = max(
                $validated['impact_financial'],
                $validated['impact_operational'],
                $validated['impact_reputational'],
                $validated['impact_regulatory']
            );

            // Calculate scores and ratings
            $inherentScore = $validated['inherent_likelihood'] * $inherentImpact;
            $inherentRating = $this->calculateRating($inherentScore);

            $risk->update([
                'title' => $validated['title'],
                'description' => $validated['description'],
                'category_id' => $validated['category_id'],
                'business_unit_id' => $validated['business_unit_id'],
                'process_id' => $validated['process_id'] ?? null,
                'risk_owner_id' => $validated['risk_owner_id'],
                'risk_steward_id' => $validated['risk_steward_id'] ?? null,
                'identified_by' => $validated['identified_by'] ?? null,
                'date_identified' => $validated['date_identified'] ?? null,
                'risk_source' => $validated['risk_source'] ?? null,
                'status' => $validated['status'],
                'inherent_likelihood' => $validated['inherent_likelihood'],
                'inherent_impact' => $inherentImpact,
                'inherent_impact_financial' => $validated['impact_financial'],
                'inherent_impact_operational' => $validated['impact_operational'],
                'inherent_impact_reputational' => $validated['impact_reputational'],
                'inherent_impact_regulatory' => $validated['impact_regulatory'],
                'inherent_score' => $inherentScore,
                'inherent_rating' => $inherentRating,
                'treatment_strategy' => $validated['treatment_strategy'] ?? null,
                'risk_velocity' => $validated['risk_velocity'] ?? null,
                'review_frequency' => $validated['review_frequency'] ?? null,
                'financial_exposure_ngn' => $validated['financial_exposure'] ?? null,
                'regulatory_mapping' => $validated['regulatory_tags'] ?? null,
                'appetite_notes' => $validated['notes'] ?? null,
            ]);

            // Create audit trail
            RiskAuditTrail::create([
                'entity_type' => 'risk',
                'entity_id' => $risk->id,
                'organization_id' => $orgId,
                'action_type' => 'updated',
                'field_changed' => 'multiple',
                'old_value' => json_encode($oldValues),
                'new_value' => json_encode($risk->fresh()->toArray()),
                'changed_by' => auth()->id(),
                'changed_at' => now(),
            ]);

            return redirect()->route('risk.register.show', $risk)
                ->with('success', "Risk {$risk->risk_code} has been updated successfully.");
        });
    }

    /**
     * Soft delete the specified risk.
     */
    public function destroy(Risk $register)
    {
        $risk = $register;
        $orgId = auth()->user()->organization_id ?? 1;

        if ($risk->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this risk.');
        }

        return DB::transaction(function () use ($risk, $orgId) {
            RiskAuditTrail::create([
                'entity_type' => 'risk',
                'entity_id' => $risk->id,
                'organization_id' => $orgId,
                'action_type' => 'deleted',
                'field_changed' => 'all',
                'old_value' => json_encode($risk->toArray()),
                'changed_by' => auth()->id(),
                'changed_at' => now(),
            ]);

            $risk->delete();

            return redirect()->route('risk.register.index')
                ->with('success', "Risk {$risk->risk_code} has been deleted.");
        });
    }

    /**
     * Calculate risk rating based on score (likelihood x impact).
     */
    private function calculateRating(int $score): string
    {
        if ($score >= 20) {
            return 'Critical';
        } elseif ($score >= 12) {
            return 'High';
        } elseif ($score >= 6) {
            return 'Medium';
        } else {
            return 'Low';
        }
    }
}
