<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\TreatmentPlan;
use App\Models\Risk;
use App\Models\User;
use App\Models\RiskAuditTrail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TreatmentPlanController extends Controller
{
    /**
     * Treatment plans dashboard with statistics.
     */
    public function dashboard()
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $stats = [
            'total' => TreatmentPlan::where('organization_id', $orgId)->count(),
            'in_progress' => TreatmentPlan::where('organization_id', $orgId)->where('status', 'in_progress')->count(),
            'completed' => TreatmentPlan::where('organization_id', $orgId)->where('status', 'completed')->count(),
            'overdue' => TreatmentPlan::where('organization_id', $orgId)->where('status', 'overdue')->count(),
            'not_started' => TreatmentPlan::where('organization_id', $orgId)->where('status', 'not_started')->count(),
            'cancelled' => TreatmentPlan::where('organization_id', $orgId)->where('status', 'cancelled')->count(),
        ];

        $overduePlans = TreatmentPlan::where('organization_id', $orgId)
            ->where('status', 'overdue')
            ->with(['risk', 'owner'])
            ->orderBy('target_completion_date')
            ->limit(10)
            ->get();

        $upcomingDeadlines = TreatmentPlan::where('organization_id', $orgId)
            ->where('status', 'in_progress')
            ->where('target_completion_date', '<=', now()->addDays(30))
            ->with(['risk', 'owner'])
            ->orderBy('target_completion_date')
            ->limit(10)
            ->get();

        return view('risk.treatments.dashboard', compact('stats', 'overduePlans', 'upcomingDeadlines'));
    }

    /**
     * Review pending treatments.
     */
    public function review(Request $request)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $plans = TreatmentPlan::where('organization_id', $orgId)
            ->whereIn('status', ['pending_review', 'in_progress'])
            ->with(['risk', 'owner'])
            ->orderBy('target_completion_date')
            ->paginate(25);

        return view('risk.treatments.review', compact('plans'));
    }

    /**
     * Display the treatment plan listing with filters.
     */
    public function index(Request $request)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $query = TreatmentPlan::where('organization_id', $orgId)
            ->with(['risk', 'owner']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('risk_id')) {
            $query->where('risk_id', $request->risk_id);
        }

        if ($request->filled('treatment_type')) {
            $query->where('treatment_type', $request->treatment_type);
        }

        if ($request->filled('owner_id')) {
            $query->where('treatment_owner_id', $request->owner_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('treatment_title', 'like', "%{$search}%")
                  ->orWhere('treatment_code', 'like', "%{$search}%");
            });
        }

        $plans = $query->orderByDesc('created_at')->paginate(25)->withQueryString();

        $risks = Risk::where('organization_id', $orgId)->orderBy('risk_code')->get();
        $users = User::where('organization_id', $orgId)->orderBy('name')->get();

        return view('risk.treatments.index', compact('plans', 'risks', 'users'));
    }

    /**
     * Show the form for creating a new treatment plan.
     */
    public function create(Request $request)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $risks = Risk::where('organization_id', $orgId)
            ->where('status', 'active')
            ->orderBy('risk_code')
            ->get();
        $users = User::where('organization_id', $orgId)->orderBy('name')->get();
        $selectedRiskId = $request->get('risk_id');

        return view('risk.treatments.create', compact('risks', 'users', 'selectedRiskId'));
    }

    /**
     * Store a newly created treatment plan.
     */
    public function store(Request $request)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $validated = $request->validate([
            'risk_id' => 'required|exists:risks,id',
            'treatment_title' => 'required|string|max:255',
            'treatment_description' => 'required|string|max:5000',
            'treatment_type' => 'required|in:mitigate,transfer,avoid,accept',
            'treatment_owner_id' => 'required|exists:users,id',
            'priority' => 'required|in:critical,high,medium,low',
            'target_completion_date' => 'required|date|after:today',
            'estimated_cost' => 'nullable|numeric|min:0',
            'expected_residual_likelihood' => 'nullable|integer|min:1|max:5',
            'expected_residual_impact' => 'nullable|integer|min:1|max:5',
            'milestones' => 'nullable|array',
            'milestones.*.title' => 'nullable|string|max:255',
            'milestones.*.due_date' => 'nullable|date',
            'milestones.*.responsible' => 'nullable|string|max:255',
            'success_criteria' => 'nullable|string|max:2000',
        ]);

        // Convert milestones array to JSON string for storage
        if (isset($validated['milestones'])) {
            $validated['milestones'] = json_encode(array_filter($validated['milestones'], fn($m) => !empty($m['title'])));
        }

        // Verify risk belongs to org
        $risk = Risk::where('id', $validated['risk_id'])
            ->where('organization_id', $orgId)
            ->firstOrFail();

        return DB::transaction(function () use ($validated, $orgId, $risk) {
            // Auto-generate treatment code using ReferenceCodeService
            $treatmentCode = \App\Services\ReferenceCodeService::generate('treatment_plans', 'treatment_code', 'TP');

            $plan = TreatmentPlan::create(array_merge($validated, [
                'organization_id' => $orgId,
                'treatment_code' => $treatmentCode,
                'status' => 'not_started',
                'progress_percentage' => 0,
                'created_by' => auth()->id(),
                // Populate original NOT NULL columns from alignment columns
                'strategy' => $validated['treatment_type'],
                'action_title' => $validated['treatment_title'],
                'action_description' => $validated['treatment_description'],
                'owner_id' => $validated['treatment_owner_id'],
                'target_date' => $validated['target_completion_date'],
            ]));

            // Audit trail
            \App\Services\AuditTrailService::record($plan, 'create');

            return redirect()->route('risk.treatments.show', $plan)
                ->with('success', "Treatment plan {$treatmentCode} has been created.");
        });
    }

    /**
     * Display the specified treatment plan.
     */
    public function show(TreatmentPlan $treatment)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        if ($treatment->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this treatment plan.');
        }

        $treatment->load(['risk.category', 'risk.riskOwner', 'owner']);

        $progressHistory = [
            'labels' => [],
            'values' => [],
        ];

        $costData = [
            'budget' => (float) ($treatment->estimated_cost ?? $treatment->cost_estimate_ngn ?? 0),
            'actual' => (float) ($treatment->actual_cost ?? $treatment->actual_cost_ngn ?? 0),
        ];

        return view('risk.treatments.show', [
            'plan' => $treatment,
            'progressHistory' => $progressHistory,
            'costData' => $costData,
        ]);
    }

    /**
     * Show the form for editing the specified treatment plan.
     */
    public function edit(TreatmentPlan $treatment)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        if ($treatment->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this treatment plan.');
        }

        $risks = Risk::where('organization_id', $orgId)->orderBy('risk_code')->get();
        $users = User::where('organization_id', $orgId)->orderBy('name')->get();

        return view('risk.treatments.edit', [
            'plan' => $treatment,
            'risks' => $risks,
            'users' => $users,
        ]);
    }

    /**
     * Update the specified treatment plan.
     */
    public function update(Request $request, TreatmentPlan $treatment)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        if ($treatment->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this treatment plan.');
        }

        $validated = $request->validate([
            'treatment_title' => 'required|string|max:255',
            'treatment_description' => 'required|string|max:5000',
            'treatment_type' => 'required|in:mitigate,transfer,avoid,accept',
            'treatment_owner_id' => 'required|exists:users,id',
            'priority' => 'required|in:critical,high,medium,low',
            'status' => 'required|in:not_started,in_progress,completed,overdue,cancelled,pending_review',
            'progress_percentage' => 'nullable|integer|min:0|max:100',
            'target_completion_date' => 'required|date',
            'actual_completion_date' => 'nullable|date',
            'estimated_cost' => 'nullable|numeric|min:0',
            'actual_cost' => 'nullable|numeric|min:0',
            'expected_residual_likelihood' => 'nullable|integer|min:1|max:5',
            'expected_residual_impact' => 'nullable|integer|min:1|max:5',
            'milestones' => 'nullable|array',
            'milestones.*.title' => 'nullable|string|max:255',
            'milestones.*.due_date' => 'nullable|date',
            'milestones.*.responsible' => 'nullable|string|max:255',
            'success_criteria' => 'nullable|string|max:2000',
            'implementation_notes' => 'nullable|string|max:5000',
        ]);

        // Convert milestones array to JSON string for storage
        if (isset($validated['milestones'])) {
            $validated['milestones'] = json_encode(array_filter($validated['milestones'], fn($m) => !empty($m['title'])));
        }

        // Auto-set completion date when status becomes completed
        if ($validated['status'] === 'completed' && empty($validated['actual_completion_date'])) {
            $validated['actual_completion_date'] = now()->toDateString();
            $validated['progress_percentage'] = 100;
        }

        $original = $treatment->getAttributes();

        $treatment->update(array_merge($validated, [
            'updated_by' => auth()->id(),
        ]));

        // Audit trail
        \App\Services\AuditTrailService::recordChanges($treatment, $original);

        return redirect()->route('risk.treatments.show', $treatment)
            ->with('success', "Treatment plan {$treatment->treatment_code} has been updated.");
    }

    /**
     * Delete the specified treatment plan.
     */
    public function destroy(TreatmentPlan $treatment)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        if ($treatment->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this treatment plan.');
        }

        $code = $treatment->treatment_code;
        $riskId = $treatment->risk_id;

        DB::transaction(function () use ($treatment, $orgId, $code, $riskId) {
            RiskAuditTrail::create([
                'risk_id' => $riskId,
                'organization_id' => $orgId,
                'action' => 'treatment_deleted',
                'description' => "Treatment plan {$code} deleted",
                'performed_by' => auth()->id(),
            ]);

            $treatment->delete();
        });

        return redirect()->route('risk.treatments.index')
            ->with('success', "Treatment plan {$code} has been deleted.");
    }

    /**
     * Approve a treatment plan.
     */
    public function approve(Request $request, TreatmentPlan $treatment)
    {
        $orgId = auth()->user()->organization_id ?? 1;
        if ($treatment->organization_id !== $orgId) {
            abort(403);
        }

        $treatment->update([
            'status' => 'approved',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        RiskAuditTrail::create([
            'risk_id' => $treatment->risk_id,
            'organization_id' => $orgId,
            'action' => 'treatment_approved',
            'description' => "Treatment plan {$treatment->treatment_code} approved",
            'performed_by' => auth()->id(),
        ]);

        return redirect()->route('risk.treatments.review')->with('success', 'Treatment plan approved.');
    }

    /**
     * Reject a treatment plan.
     */
    public function reject(Request $request, TreatmentPlan $treatment)
    {
        $orgId = auth()->user()->organization_id ?? 1;
        if ($treatment->organization_id !== $orgId) {
            abort(403);
        }

        $treatment->update([
            'status' => 'rejected',
            'rejection_reason' => $request->input('reason'),
        ]);

        RiskAuditTrail::create([
            'risk_id' => $treatment->risk_id,
            'organization_id' => $orgId,
            'action' => 'treatment_rejected',
            'description' => "Treatment plan {$treatment->treatment_code} rejected",
            'performed_by' => auth()->id(),
        ]);

        return redirect()->route('risk.treatments.review')->with('success', 'Treatment plan rejected.');
    }

    /**
     * Add a comment to a treatment plan.
     */
    public function comment(Request $request, TreatmentPlan $treatment)
    {
        $orgId = auth()->user()->organization_id ?? 1;
        if ($treatment->organization_id !== $orgId) {
            abort(403);
        }

        RiskAuditTrail::create([
            'risk_id' => $treatment->risk_id,
            'organization_id' => $orgId,
            'action' => 'treatment_comment',
            'description' => $request->input('comment', 'Comment added'),
            'performed_by' => auth()->id(),
        ]);

        return back()->with('success', 'Comment added.');
    }
}
