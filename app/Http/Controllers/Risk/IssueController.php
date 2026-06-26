<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\Issue;
use App\Models\IssueAttachment;
use App\Models\IssueRemediationAction;
use App\Models\IssueProgressUpdate;
use App\Models\IssueEscalationLog;
use App\Models\Risk;
use App\Models\BusinessUnit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class IssueController extends Controller
{
    /**
     * Issues dashboard with statistics.
     */
    public function dashboard()
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $stats = [
            'total' => Issue::where('organization_id', $orgId)->count(),
            'open' => Issue::where('organization_id', $orgId)->where('issue_status', 'OPEN')->count(),
            'in_progress' => Issue::where('organization_id', $orgId)->where('issue_status', 'IN_PROGRESS')->count(),
            'overdue' => Issue::where('organization_id', $orgId)->where('issue_status', 'OVERDUE')->count(),
            'pending_closure' => Issue::where('organization_id', $orgId)->where('issue_status', 'PENDING_CLOSURE')->count(),
            'closed' => Issue::where('organization_id', $orgId)->where('issue_status', 'CLOSED')->count(),
            'critical_priority' => Issue::where('organization_id', $orgId)->where('priority', 'critical')->whereNotIn('issue_status', ['CLOSED', 'CANCELLED'])->count(),
            'high_priority' => Issue::where('organization_id', $orgId)->where('priority', 'high')->whereNotIn('issue_status', ['CLOSED', 'CANCELLED'])->count(),
        ];

        // Ageing summary
        $ageingSummary = [
            '0_30' => Issue::where('organization_id', $orgId)
                ->whereNotIn('issue_status', ['CLOSED', 'CANCELLED'])
                ->where('created_at', '>=', now()->subDays(30))
                ->count(),
            '31_60' => Issue::where('organization_id', $orgId)
                ->whereNotIn('issue_status', ['CLOSED', 'CANCELLED'])
                ->whereBetween('created_at', [now()->subDays(60), now()->subDays(30)])
                ->count(),
            '61_90' => Issue::where('organization_id', $orgId)
                ->whereNotIn('issue_status', ['CLOSED', 'CANCELLED'])
                ->whereBetween('created_at', [now()->subDays(90), now()->subDays(60)])
                ->count(),
            '90_plus' => Issue::where('organization_id', $orgId)
                ->whereNotIn('issue_status', ['CLOSED', 'CANCELLED'])
                ->where('created_at', '<', now()->subDays(90))
                ->count(),
        ];

        $overdueIssuesList = Issue::where('organization_id', $orgId)
            ->where('issue_status', 'OVERDUE')
            ->with(['issueOwner', 'businessUnit'])
            ->orderBy('target_resolution_date')
            ->limit(10)
            ->get();

        // Variables expected by the blade template
        $openIssues = $stats['open'] + $stats['in_progress'];
        $overdueIssues = $stats['overdue'];
        $cbnFindings = Issue::where('organization_id', $orgId)
            ->where('issue_source', 'cbn_examination')
            ->whereNotIn('issue_status', ['CLOSED', 'CANCELLED'])
            ->count();
        $avgDaysToClose = (int) Issue::where('organization_id', $orgId)
            ->where('issue_status', 'CLOSED')
            ->whereNotNull('closed_at')
            ->avg(DB::raw('DATEDIFF(closed_at, created_at)')) ?? 0;

        // Chart data
        $priorityData = [
            'labels' => ['Critical', 'High', 'Medium', 'Low'],
            'values' => [
                $stats['critical_priority'],
                $stats['high_priority'],
                Issue::where('organization_id', $orgId)->where('priority', 'medium')->whereNotIn('issue_status', ['CLOSED', 'CANCELLED'])->count(),
                Issue::where('organization_id', $orgId)->where('priority', 'low')->whereNotIn('issue_status', ['CLOSED', 'CANCELLED'])->count(),
            ],
        ];

        $ageingData = [
            'labels' => ['0-30 days', '31-60 days', '61-90 days', '90+ days'],
            'values' => [$ageingSummary['0_30'], $ageingSummary['31_60'], $ageingSummary['61_90'], $ageingSummary['90_plus']],
        ];

        return view('risk.issues.dashboard', compact(
            'stats', 'ageingSummary', 'overdueIssuesList',
            'openIssues', 'overdueIssues', 'cbnFindings', 'avgDaysToClose',
            'priorityData', 'ageingData'
        ));
    }

    /**
     * Display issue listing with filters.
     */
    public function index(Request $request)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $query = Issue::where('organization_id', $orgId)
            ->with(['issueOwner', 'businessUnit']);

        if ($request->filled('status')) {
            $query->where('issue_status', $request->status);
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }

        if ($request->filled('source')) {
            $query->where('issue_source', $request->source);
        }

        if ($request->filled('overdue')) {
            if ($request->boolean('overdue')) {
                $query->where('issue_status', 'OVERDUE');
            }
        }

        if ($request->filled('escalation_level')) {
            $query->where('escalation_level', $request->escalation_level);
        }

        if ($request->filled('business_unit_id')) {
            $query->where('business_unit_id', $request->business_unit_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('issue_reference', 'like', "%{$search}%")
                  ->orWhere('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $issues = $query->orderByDesc('created_at')->paginate(25)->withQueryString();

        $businessUnits = BusinessUnit::where('organization_id', $orgId)->orderBy('name')->get();
        $users = User::where('organization_id', $orgId)->orderBy('name')->get();

        return view('risk.issues.index', compact('issues', 'businessUnits', 'users'));
    }

    /**
     * Show the form for creating a new issue.
     */
    public function create()
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $risks = Risk::where('organization_id', $orgId)->orderBy('risk_code')->get();
        $businessUnits = BusinessUnit::where('organization_id', $orgId)->orderBy('name')->get();
        $users = User::where('organization_id', $orgId)->orderBy('name')->get();

        return view('risk.issues.create', compact('risks', 'businessUnits', 'users'));
    }

    /**
     * Store a newly created issue.
     */
    public function store(Request $request)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string|max:5000',
            'issue_source' => 'required|in:audit,risk_assessment,incident,regulatory,self_identified,customer_complaint,other',
            'priority' => 'required|in:critical,high,medium,low',
            'business_unit_id' => 'required|exists:business_units,id',
            'responsible_owner_id' => 'required|exists:users,id',
            'risk_id' => 'nullable|exists:risks,id',
            'target_resolution_date' => 'required|date|after:today',
            'root_cause' => 'nullable|string|max:3000',
            'impact_description' => 'nullable|string|max:2000',
            'recommended_action' => 'nullable|string|max:3000',
            'source_reference' => 'nullable|string|max:255',
        ]);

        return DB::transaction(function () use ($validated, $orgId) {
            // Auto-generate issue reference using ReferenceCodeService
            $issueReference = \App\Services\ReferenceCodeService::generate('issues', 'issue_reference', 'ISS');

            $issue = Issue::create(array_merge($validated, [
                'organization_id' => $orgId,
                'issue_reference' => $issueReference,
                'issue_status' => 'OPEN',
                'escalation_level' => 0,
                'created_by' => auth()->id(),
            ]));

            // Audit trail
            \App\Services\AuditTrailService::record($issue, 'create');

            return redirect()->route('risk.issues.show', $issue)
                ->with('success', "Issue {$issueReference} has been created.");
        });
    }

    /**
     * Display the specified issue.
     */
    public function show(Issue $issue)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        if ($issue->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this issue.');
        }

        $issue->load([
            'issueOwner',
            'businessUnit',
            'risk',
            'remediationActions' => function ($q) {
                $q->orderBy('target_date');
            },
            'progressUpdates' => function ($q) {
                $q->orderByDesc('created_at');
            },
            'escalationLogs' => function ($q) {
                $q->orderByDesc('escalated_at');
            },
            'attachments',
        ]);

        return view('risk.issues.show', compact('issue'));
    }

    /**
     * Show the form for editing an issue.
     */
    public function edit(Issue $issue)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        if ($issue->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this issue.');
        }

        $risks = Risk::where('organization_id', $orgId)->orderBy('risk_code')->get();
        $businessUnits = BusinessUnit::where('organization_id', $orgId)->orderBy('name')->get();
        $users = User::where('organization_id', $orgId)->orderBy('name')->get();

        return view('risk.issues.edit', compact('issue', 'risks', 'businessUnits', 'users'));
    }

    /**
     * Update the specified issue.
     */
    public function update(Request $request, Issue $issue)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        if ($issue->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this issue.');
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string|max:5000',
            'issue_source' => 'nullable|string|max:255',
            'priority' => 'required|string|max:50',
            'business_unit_id' => 'required|exists:business_units,id',
            'responsible_owner_id' => 'required|exists:users,id',
            'risk_register_id' => 'nullable|exists:risks,id',
            'issue_category' => 'nullable|string|max:100',
            'target_resolution_date' => 'required|date',
            'management_response_due' => 'nullable|date',
            'root_cause' => 'nullable|string|max:3000',
            'impact_description' => 'nullable|string|max:2000',
            'action_plan' => 'nullable|string|max:5000',
            'management_response' => 'nullable|string|max:5000',
            'interim_controls' => 'nullable|string|max:3000',
            'cbn_examination_finding' => 'nullable|boolean',
            'examination_ref' => 'nullable|string|max:255',
            'cbn_response_deadline' => 'nullable|date',
            'ndpa_breach_type' => 'nullable|string|max:255',
            'regulatory_reportable' => 'nullable|boolean',
        ]);

        // Handle checkbox defaults
        $validated['cbn_examination_finding'] = $request->has('cbn_examination_finding') ? 1 : 0;
        $validated['regulatory_reportable'] = $request->has('regulatory_reportable') ? 1 : 0;

        $original = $issue->getAttributes();

        $issue->update(array_merge($validated, [
            'updated_by' => auth()->id(),
        ]));

        // Audit trail
        \App\Services\AuditTrailService::recordChanges($issue, $original);

        return redirect()->route('risk.issues.show', $issue)
            ->with('success', "Issue {$issue->issue_reference} has been updated.");
    }

    /**
     * Update issue status (transition).
     */
    public function updateStatus(Request $request, Issue $issue)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        if ($issue->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this issue.');
        }

        $validated = $request->validate([
            'issue_status' => 'required|in:OPEN,IN_PROGRESS,OVERDUE,PENDING_CLOSURE,CLOSED,CANCELLED,REOPENED',
            'status_notes' => 'nullable|string|max:1000',
        ]);

        $allowedTransitions = [
            'OPEN' => ['IN_PROGRESS', 'CANCELLED'],
            'IN_PROGRESS' => ['PENDING_CLOSURE', 'OVERDUE', 'CANCELLED'],
            'OVERDUE' => ['IN_PROGRESS', 'PENDING_CLOSURE', 'CANCELLED'],
            'PENDING_CLOSURE' => ['CLOSED', 'IN_PROGRESS', 'REOPENED'],
            'CLOSED' => ['REOPENED'],
            'CANCELLED' => ['REOPENED'],
            'REOPENED' => ['IN_PROGRESS'],
        ];

        $current = $issue->issue_status;
        $new = $validated['issue_status'];

        if (!isset($allowedTransitions[$current]) || !in_array($new, $allowedTransitions[$current])) {
            return back()->with('error', "Cannot transition issue from '{$current}' to '{$new}'.");
        }

        $original = $issue->getAttributes();

        $issue->update([
            'issue_status' => $new,
            'status_changed_at' => now(),
            'status_changed_by' => auth()->id(),
        ]);

        // Log the status change as a progress update
        IssueProgressUpdate::create([
            'issue_id' => $issue->id,
            'organization_id' => $orgId,
            'update_type' => 'status_change',
            'description' => "Status changed from {$current} to {$new}. " . ($validated['status_notes'] ?? ''),
            'updated_by' => auth()->id(),
        ]);

        // Audit trail
        \App\Services\AuditTrailService::recordChanges($issue, $original);

        return back()->with('success', "Issue status updated to '{$new}'.");
    }

    /**
     * Add a remediation action to an issue.
     */
    public function addRemediationAction(Request $request, Issue $issue)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        if ($issue->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this issue.');
        }

        $validated = $request->validate([
            'description' => 'required|string|max:3000',
            'owner_id' => 'required|exists:users,id',
            'target_date' => 'required|date|after:today',
            'priority' => 'required|in:critical,high,medium,low',
            'department' => 'nullable|string|max:255',
        ]);

        // Auto-generate action number
        $lastAction = IssueRemediationAction::where('issue_id', $issue->id)->orderByDesc('action_number')->first();
        $nextNumber = $lastAction ? ((int) $lastAction->action_number) + 1 : 1;

        $action = IssueRemediationAction::create(array_merge($validated, [
            'issue_id' => $issue->id,
            'action_number' => $nextNumber,
            'status' => 'pending',
        ]));

        // Audit trail
        \App\Services\AuditTrailService::record($action, 'create');

        return back()->with('success', 'Remediation action has been added.');
    }

    /**
     * Mark a remediation action as complete.
     */
    public function completeAction(Request $request, Issue $issue, IssueRemediationAction $action)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        if ($issue->organization_id !== $orgId || $action->issue_id !== $issue->id) {
            abort(403, 'Unauthorized access.');
        }

        $validated = $request->validate([
            'completion_notes' => 'nullable|string|max:2000',
        ]);

        $action->update([
            'status' => 'completed',
            'completed_at' => now(),
            'completed_by' => auth()->id(),
            'completion_notes' => $validated['completion_notes'] ?? null,
        ]);

        // Check if all actions are complete
        $pendingActions = IssueRemediationAction::where('issue_id', $issue->id)
            ->where('status', '!=', 'completed')
            ->count();

        if ($pendingActions === 0) {
            IssueProgressUpdate::create([
                'issue_id' => $issue->id,
                'organization_id' => $orgId,
                'update_type' => 'milestone',
                'description' => 'All remediation actions have been completed.',
                'updated_by' => auth()->id(),
            ]);
        }

        return back()->with('success', 'Remediation action marked as complete.');
    }

    /**
     * Add a progress update to an issue.
     */
    public function addProgressUpdate(Request $request, Issue $issue)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        if ($issue->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this issue.');
        }

        $validated = $request->validate([
            'description' => 'required|string|max:3000',
            'update_type' => 'required|in:progress,milestone,escalation,note',
            'progress_pct' => 'nullable|integer|min:0|max:100',
        ]);

        IssueProgressUpdate::create(array_merge($validated, [
            'issue_id' => $issue->id,
            'organization_id' => $orgId,
            'updated_by' => auth()->id(),
        ]));

        // Update issue progress if provided
        if (!empty($validated['progress_pct'])) {
            $issue->update(['progress_pct' => $validated['progress_pct']]);
        }

        return back()->with('success', 'Progress update has been added.');
    }

    /**
     * Request closure of an issue.
     */
    public function requestClosure(Request $request, Issue $issue)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        if ($issue->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this issue.');
        }

        if (!in_array($issue->issue_status, ['IN_PROGRESS', 'OVERDUE'])) {
            return back()->with('error', 'Only in-progress or overdue issues can be submitted for closure.');
        }

        $validated = $request->validate([
            'closure_justification' => 'required|string|max:3000',
            'evidence_of_resolution' => 'nullable|string|max:2000',
        ]);

        $original = $issue->getAttributes();

        $issue->update([
            'issue_status' => 'PENDING_CLOSURE',
            'closure_justification' => $validated['closure_justification'],
            'evidence_of_resolution' => $validated['evidence_of_resolution'] ?? null,
            'closure_requested_at' => now(),
            'closure_requested_by' => auth()->id(),
        ]);

        IssueProgressUpdate::create([
            'issue_id' => $issue->id,
            'organization_id' => $orgId,
            'update_type' => 'milestone',
            'description' => 'Closure requested: ' . $validated['closure_justification'],
            'updated_by' => auth()->id(),
        ]);

        // Audit trail
        \App\Services\AuditTrailService::recordChanges($issue, $original);

        return back()->with('success', 'Issue closure request has been submitted.');
    }

    /**
     * Approve closure of an issue.
     */
    public function approveClosure(Request $request, Issue $issue)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        if ($issue->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this issue.');
        }

        if ($issue->issue_status !== 'PENDING_CLOSURE') {
            return back()->with('error', 'Only issues pending closure can be approved.');
        }

        $original = $issue->getAttributes();

        $issue->update([
            'issue_status' => 'CLOSED',
            'actual_resolution_date' => now()->toDateString(),
            'closed_at' => now(),
            'closed_by' => auth()->id(),
        ]);

        IssueProgressUpdate::create([
            'issue_id' => $issue->id,
            'organization_id' => $orgId,
            'update_type' => 'milestone',
            'description' => 'Issue closure approved.',
            'updated_by' => auth()->id(),
        ]);

        // Audit trail
        \App\Services\AuditTrailService::recordChanges($issue, $original);

        return back()->with('success', "Issue {$issue->issue_reference} has been closed.");
    }

    /**
     * Reject closure of an issue.
     */
    public function rejectClosure(Request $request, Issue $issue)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        if ($issue->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this issue.');
        }

        if ($issue->issue_status !== 'PENDING_CLOSURE') {
            return back()->with('error', 'Only issues pending closure can be rejected.');
        }

        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:2000',
        ]);

        $issue->update([
            'issue_status' => 'IN_PROGRESS',
            'closure_rejection_reason' => $validated['rejection_reason'],
            'closure_rejected_at' => now(),
            'closure_rejected_by' => auth()->id(),
        ]);

        IssueProgressUpdate::create([
            'issue_id' => $issue->id,
            'organization_id' => $orgId,
            'update_type' => 'milestone',
            'description' => 'Closure rejected: ' . $validated['rejection_reason'],
            'updated_by' => auth()->id(),
        ]);

        return back()->with('success', "Issue closure has been rejected. Issue moved back to IN_PROGRESS.");
    }

    /**
     * Ageing analysis report.
     */
    public function ageingReport()
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $issues = Issue::where('organization_id', $orgId)
            ->whereNotIn('issue_status', ['CLOSED', 'CANCELLED'])
            ->with(['issueOwner', 'businessUnit'])
            ->orderBy('created_at')
            ->get()
            ->map(function ($issue) {
                $issue->age_days = (int) $issue->created_at->diffInDays(now());
                $issue->age_bucket = $this->getAgeBucket($issue->age_days);
                return $issue;
            });

        $bands = ['0-30', '31-60', '61-90', '90+'];
        $priorities = ['critical', 'high', 'medium', 'low'];
        $ageingMatrix = [];
        foreach ($priorities as $p) {
            $ageingMatrix[$p] = array_fill_keys($bands, 0);
        }
        foreach ($issues as $issue) {
            $p = strtolower($issue->issue_priority ?? $issue->priority ?? 'medium');
            $p = in_array($p, $priorities) ? $p : 'medium';
            if (isset($ageingMatrix[$p][$issue->age_bucket])) {
                $ageingMatrix[$p][$issue->age_bucket]++;
            }
        }

        $ageingByPriorityData = [
            'labels' => $bands,
            'datasets' => [
                ['label' => 'Critical', 'data' => array_values($ageingMatrix['critical']), 'backgroundColor' => '#dc2626'],
                ['label' => 'High',     'data' => array_values($ageingMatrix['high']),     'backgroundColor' => '#f97316'],
                ['label' => 'Medium',   'data' => array_values($ageingMatrix['medium']),   'backgroundColor' => '#eab308'],
                ['label' => 'Low',      'data' => array_values($ageingMatrix['low']),      'backgroundColor' => '#16a34a'],
            ],
        ];

        $trendLabels = [];
        $openedSeries = [];
        $closedSeries = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = now()->subMonths($i);
            $trendLabels[] = $m->format('M');
            $openedSeries[] = Issue::where('organization_id', $orgId)
                ->whereYear('created_at', $m->year)->whereMonth('created_at', $m->month)->count();
            $closedSeries[] = Issue::where('organization_id', $orgId)
                ->where('issue_status', 'CLOSED')
                ->whereYear('updated_at', $m->year)->whereMonth('updated_at', $m->month)->count();
        }
        $ageingTrendData = [
            'labels' => $trendLabels,
            'opened' => $openedSeries,
            'closed' => $closedSeries,
        ];

        $agedIssues = $issues->sortByDesc('age_days')->take(15)->values();
        $bucketSummary = $issues->groupBy('age_bucket')->map->count();

        return view('risk.issues.ageing', compact(
            'issues', 'bucketSummary', 'ageingMatrix',
            'ageingByPriorityData', 'ageingTrendData', 'agedIssues'
        ));
    }

    /**
     * Closure list (issues pending closure).
     */
    public function closureList()
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $issues = Issue::where('organization_id', $orgId)
            ->where('issue_status', 'PENDING_CLOSURE')
            ->with(['issueOwner', 'businessUnit'])
            ->orderByDesc('closure_requested_at')
            ->paginate(25);

        return view('risk.issues.closure', compact('issues'));
    }

    /**
     * Determine age bucket for ageing report.
     */
    private function getAgeBucket(int $days): string
    {
        if ($days <= 30) return '0-30 days';
        if ($days <= 60) return '31-60 days';
        if ($days <= 90) return '61-90 days';
        return '90+ days';
    }

    public function downloadAttachment(Issue $issue, IssueAttachment $attachment)
    {
        $orgId = auth()->user()->organization_id ?? 1;
        if ($issue->organization_id !== $orgId || $attachment->issue_id !== $issue->id) {
            abort(403, 'Unauthorized access.');
        }

        $disk = Storage::disk('local');
        if (! $disk->exists($attachment->storage_path)) {
            abort(404, 'File not found.');
        }

        return $disk->download($attachment->storage_path, $attachment->file_name);
    }
}
