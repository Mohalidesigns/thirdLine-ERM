<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\TreatmentPlan;
use App\Models\Risk;
use App\Models\User;
use App\Models\RiskAuditTrail;
use App\Services\ApprovalService;
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

        $base = fn() => TreatmentPlan::where('organization_id', $orgId);

        $totalPlans     = $base()->count();
        $activePlans    = $base()->whereIn('status', ['in_progress', 'in-progress', 'open', 'not_started'])->count();
        $completedPlans = $base()->where('status', 'completed')->count();
        $overduePlans   = $base()->whereIn('status', ['in_progress', 'in-progress', 'open'])
            ->whereNotNull('target_date')->where('target_date', '<', now())->count();
        $totalBudget    = (float) $base()->sum('cost_estimate_ngn') + (float) $base()->sum('estimated_cost');
        $avgEffectiveness = (int) round((float) $base()->whereNotNull('progress_pct')->avg('progress_pct'));

        $activeTreatments = $base()
            ->whereIn('status', ['in_progress', 'in-progress', 'open', 'not_started'])
            ->with(['risk', 'owner'])
            ->orderBy('target_date')
            ->limit(10)
            ->get();

        $recentActivities = $base()
            ->with('owner')
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get()
            ->map(fn($p) => (object) [
                'description' => 'Treatment plan "' . ($p->title ?? 'Untitled') . '" was updated',
                'icon' => 'update',
                'user' => $p->owner,
                'created_at' => $p->updated_at,
            ]);

        $strategyCounts = $base()->selectRaw('LOWER(strategy) s, COUNT(*) c')->groupBy('s')->pluck('c', 's');
        $strategyChartData = [
            'labels' => ['Mitigate', 'Transfer', 'Accept', 'Avoid'],
            'values' => [
                (int) ($strategyCounts['mitigate'] ?? 0),
                (int) ($strategyCounts['transfer'] ?? 0),
                (int) ($strategyCounts['accept'] ?? 0),
                (int) ($strategyCounts['avoid'] ?? 0),
            ],
        ];

        $statusChartData = [
            'labels' => ['Not Started', 'In Progress', 'Completed', 'Overdue', 'On Hold'],
            'values' => [
                $base()->where('status', 'not_started')->count(),
                $base()->whereIn('status', ['in_progress','in-progress','open'])->count(),
                $completedPlans,
                $overduePlans,
                $base()->where('status', 'on_hold')->count(),
            ],
        ];

        $monthLabels = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        $createdByMonth = [];
        $completedByMonth = [];
        $year = now()->year;
        for ($m = 1; $m <= 12; $m++) {
            $createdByMonth[] = $base()->whereYear('created_at', $year)->whereMonth('created_at', $m)->count();
            $completedByMonth[] = $base()->where('status', 'completed')
                ->whereYear('updated_at', $year)->whereMonth('updated_at', $m)->count();
        }
        $completionTrendData = ['labels' => $monthLabels, 'created' => $createdByMonth, 'completed' => $completedByMonth];

        $budgetByStrategy = $base()
            ->selectRaw('LOWER(strategy) s, COALESCE(SUM(cost_estimate_ngn),0) + COALESCE(SUM(estimated_cost),0) as b, COALESCE(SUM(actual_cost_ngn),0) + COALESCE(SUM(actual_cost),0) as a')
            ->groupBy('s')->get()->keyBy('s');
        $budgetChartData = [
            'labels' => ['Mitigate', 'Transfer', 'Accept', 'Avoid'],
            'budget' => array_map(fn($k) => (float) (optional($budgetByStrategy->get($k))->b ?? 0), ['mitigate','transfer','accept','avoid']),
            'actual' => array_map(fn($k) => (float) (optional($budgetByStrategy->get($k))->a ?? 0), ['mitigate','transfer','accept','avoid']),
        ];

        $upcomingDeadlines = $base()
            ->whereIn('status', ['in_progress', 'in-progress', 'open'])
            ->whereNotNull('target_date')
            ->where('target_date', '<=', now()->addDays(30))
            ->with(['risk', 'owner'])
            ->orderBy('target_date')
            ->limit(10)
            ->get();

        return view('risk.treatments.dashboard', compact(
            'totalPlans', 'activePlans', 'completedPlans', 'overduePlans',
            'totalBudget', 'avgEffectiveness',
            'activeTreatments', 'recentActivities',
            'strategyChartData', 'statusChartData', 'completionTrendData', 'budgetChartData',
            'upcomingDeadlines'
        ));
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
                'organization_id' => $orgId,
                'entity_type' => 'TreatmentPlan',
                'entity_id' => $treatment->id,
                'action_type' => 'deleted',
                'changed_by' => auth()->id(),
                'changed_at' => now(),
                'change_reason' => "Treatment plan {$code} deleted",
                'ip_address' => request()->ip(),
            ]);

            $treatment->delete();
        });

        return redirect()->route('risk.treatments.index')
            ->with('success', "Treatment plan {$code} has been deleted.");
    }

    /**
     * Approve a treatment plan.
     */
    public function approve(Request $request, TreatmentPlan $treatment, ApprovalService $approvals)
    {
        abort_unless(auth()->user()->can('approve-treatment-plan', $treatment), 403,
            'Only users with risk-manager or CRO role can approve treatment plans.');

        if ($treatment->status !== 'pending_review') {
            return back()->with('error', 'Only plans pending review can be approved.');
        }

        $validated = $request->validate([
            'comments' => 'nullable|string|max:1000',
        ]);

        $treatment->update([
            'status' => 'approved',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        $pending = $approvals->latestPending($treatment) ?? $approvals->requestApproval($treatment, 'approve_treatment_plan');
        $approvals->approve($pending, auth()->id(), $validated['comments'] ?? null);

        return back()->with('success', 'Treatment plan approved.');
    }

    /**
     * Reject a treatment plan with a required reason.
     */
    public function reject(Request $request, TreatmentPlan $treatment, ApprovalService $approvals)
    {
        abort_unless(auth()->user()->can('approve-treatment-plan', $treatment), 403,
            'Only users with risk-manager or CRO role can reject treatment plans.');

        if ($treatment->status !== 'pending_review') {
            return back()->with('error', 'Only plans pending review can be rejected.');
        }

        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:2000',
        ]);

        $treatment->update([
            'status' => 'rejected',
            'rejection_reason' => $validated['rejection_reason'],
        ]);

        $pending = $approvals->latestPending($treatment) ?? $approvals->requestApproval($treatment, 'approve_treatment_plan');
        $approvals->reject($pending, auth()->id(), $validated['rejection_reason']);

        return back()->with('success', 'Treatment plan rejected. The plan owner has been notified.');
    }

    /**
     * Owner submits a draft plan for review.
     */
    public function submitForReview(TreatmentPlan $treatment, ApprovalService $approvals)
    {
        $orgId = auth()->user()->organization_id ?? 1;
        if ($treatment->organization_id !== $orgId) {
            abort(403);
        }

        if (! in_array($treatment->status, ['draft', 'rejected', 'not_started'])) {
            return back()->with('error', 'This plan is not in a state that can be submitted for review.');
        }

        $treatment->update(['status' => 'pending_review']);

        // No assigned reviewer on treatment_plans — reviewers are role-based,
        // so we create the ApprovalRequest without a direct notification target.
        // All users with the approve-treatment-plan gate will see it in the queue.
        $approvals->requestApproval(
            $treatment,
            'approve_treatment_plan',
            payload: ['treatment_code' => $treatment->treatment_code],
            reviewerId: null,
        );

        // Notify every user who has the approver role so someone picks it up.
        $approvers = User::role(['risk-manager', 'chief-risk-officer'])
            ->where('organization_id', $orgId)
            ->get();
        foreach ($approvers as $approver) {
            \App\Services\NotificationService::send(
                $orgId,
                $approver->id,
                'approval_request',
                "Treatment plan awaiting review: {$treatment->title}",
                "Treatment plan #{$treatment->id} ({$treatment->treatment_code}) has been submitted for review.",
                ['entity_type' => 'TreatmentPlan', 'entity_id' => $treatment->id]
            );
        }

        return back()->with('success', 'Plan submitted for review.');
    }

    /**
     * Owner resubmits a rejected plan after rework.
     */
    public function resubmit(TreatmentPlan $treatment)
    {
        abort_unless(auth()->user()->can('resubmit-treatment-plan', $treatment), 403,
            'Only the plan owner or creator can resubmit.');

        if ($treatment->status !== 'rejected') {
            return back()->with('error', 'Only rejected plans can be resubmitted.');
        }

        $treatment->update([
            'status' => 'draft',
            'rejection_reason' => null,
        ]);

        return back()->with('success', 'Plan returned to draft. Edit it and submit again for review.');
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
            'organization_id' => $orgId,
            'entity_type' => 'TreatmentPlan',
            'entity_id' => $treatment->id,
            'action_type' => 'commented',
            'changed_by' => auth()->id(),
            'changed_at' => now(),
            'change_reason' => $request->input('comment', 'Comment added'),
            'ip_address' => request()->ip(),
        ]);

        return back()->with('success', 'Comment added.');
    }
}
