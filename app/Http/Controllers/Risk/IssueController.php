<?php

namespace App\Http\Controllers\Risk;

use App\Grids\GridRegistry;
use App\Http\Controllers\Concerns\PersistsConfiguredAttributes;
use App\Http\Controllers\Controller;
use App\Http\Requests\Issues\CompleteRemediationActionRequest;
use App\Http\Requests\Issues\RejectIssueClosureRequest;
use App\Http\Requests\Issues\RequestIssueClosureRequest;
use App\Http\Requests\Issues\StoreIssueRequest;
use App\Http\Requests\Issues\StoreProgressUpdateRequest;
use App\Http\Requests\Issues\StoreRemediationActionRequest;
use App\Http\Requests\Issues\UpdateIssueRequest;
use App\Http\Requests\Issues\UpdateIssueStatusRequest;
use App\Http\Requests\Issues\UploadIssueAttachmentRequest;
use App\Models\BusinessUnit;
use App\Models\Issue;
use App\Models\IssueAttachment;
use App\Models\IssueProgressUpdate;
use App\Models\IssueRemediationAction;
use App\Models\Risk;
use App\Models\User;
use App\Presenters\FormSchemaPresenter;
use App\Presenters\GridPresenter;
use App\Services\Issues\IssueAgeingService;
use App\Services\Issues\IssueDashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use ThirdLine\Platform\Tenancy\TenantContext;

class IssueController extends Controller
{
    /** The object type the create and edit forms render from. */
    private const OBJECT_TYPE = 'Issue';

    public function __construct(
        private readonly IssueDashboardService $figures,
        private readonly IssueAgeingService $ageing,
        private readonly FormSchemaPresenter $schemas,
    ) {}

    // WP-05 TASK 2 — receives the fields a tenant added through the
    // builder. Without it, a configured field would render on the form,
    // accept what was typed, and discard it on submit.
    use PersistsConfiguredAttributes;

    /**
     * Issues dashboard with statistics.
     */
    public function dashboard()
    {
        Gate::authorize('viewAny', Issue::class);

        return Inertia::render('Issues/Dashboard', [
            'stats' => $this->figures->stats(),
            'ageing' => $this->figures->ageing(),
            'overdueIssues' => $this->figures->overdueIssues(),
            'canCreate' => Gate::allows('create', Issue::class),
        ]);
    }

    /**
     * Display issue listing with filters.
     */
    public function index(Request $request, GridPresenter $presenter)
    {
        // WP-09: filtering, search, sorting, pagination and export moved into
        // the shared data grid (App\Grids\Definitions\IssuesGrid), which also
        // resolves the old issueOwner-loaded/owner-rendered N+1 by loading
        // and reading the same relation.
        return Inertia::render('Issues/Index', [
            'grid' => fn () => $presenter->present(GridRegistry::resolve('issues'), $request, $request->user()),
        ]);
    }

    /**
     * Show the form for creating a new issue.
     */
    public function create()
    {
        Gate::authorize('create', Issue::class);

        $orgId = TenantContext::organizationId();

        $defaultCategories = [
            'Process Deficiency', 'Control Weakness', 'Policy Non-Compliance', 'System Issue',
            'Governance Gap', 'Documentation Gap', 'Regulatory Non-Compliance', 'Data Quality', 'Other',
        ];
        $categories = Issue::where('organization_id', $orgId)
            ->whereNotNull('issue_category')
            ->distinct()
            ->pluck('issue_category')
            ->merge($defaultCategories)
            ->unique()
            ->sort()
            ->values();

        return Inertia::render('Issues/Create', array_merge($this->formOptions(), [
            'categories' => $categories->values()->all(),
            // WP-05 TASK 2 — the issue form is rendered FROM the Issue object
            // type, exactly as the Blade page's <x-dynamic-form> was, so a
            // field a tenant added through the builder appears here and a
            // conditional one carries its rule to the client.
            'schema' => $this->schemas->form(
                self::OBJECT_TYPE,
                sections: ['Details', 'Classification', 'Ownership', 'Analysis'],
                defaults: ['risk_register_id' => request('risk_id')],
            ),
        ]));
    }

    /**
     * Store a newly created issue.
     */
    public function store(StoreIssueRequest $request)
    {
        $orgId = TenantContext::organizationId();

        $validated = $request->validated();

        return DB::transaction(function () use ($request, $validated, $orgId) {
            // Auto-generate issue reference using ReferenceCodeService
            $issueReference = \App\Services\ReferenceCodeService::generate('issues', 'issue_reference', 'ISS');

            $issueCategory = $validated['issue_category'] ?? $validated['category'] ?? null;
            unset($validated['category'], $validated['issue_category']);

            // Map the legacy form field risk_id onto the real column.
            $validated['risk_register_id'] = $validated['risk_register_id']
                ?? $validated['risk_id']
                ?? null;
            unset($validated['risk_id']);

            $issue = Issue::create(array_merge($validated, [
                'organization_id' => $orgId,
                'issue_reference' => $issueReference,
                'issue_category' => $issueCategory,
                'issue_status' => 'OPEN',
                'current_escalation_level' => 0,
                'created_by' => auth()->id(),
            ]));

            // Fields the tenant added through the builder, if any.
            $this->saveConfiguredAttributes($request, $issue);

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
        Gate::authorize('view', $issue);

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

        return Inertia::render('Issues/Show', [
            'issue' => $this->detail($issue),
            'can' => [
                'update' => Gate::allows('update', $issue),
                'delete' => Gate::allows('delete', $issue),
                'close' => Gate::allows('close', $issue),
                'escalate' => Gate::allows('escalate', $issue),
                'recordProgress' => Gate::allows('recordProgress', $issue),
            ],
            'options' => [
                'statuses' => Issue::STATUSES,
                'updateTypes' => ['progress', 'milestone', 'escalation', 'note'],
            ],
            // The remediation-action form assigns an owner.
            'users' => User::where('organization_id', TenantContext::organizationId())
                ->orderBy('name')
                ->get()
                ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
                ->all(),
        ]);
    }

    /**
     * Show the form for editing an issue.
     */
    public function edit(Issue $issue)
    {
        Gate::authorize('update', $issue);

        return Inertia::render('Issues/Edit', array_merge($this->formOptions(), [
            'issue' => $this->editable($issue),
            // `recommended_action` is omitted on edit, as it was: the
            // recommendation is what the finding said, not something the owner
            // revises while remediating it.
            'schema' => $this->schemas->form(self::OBJECT_TYPE, $issue, omit: ['recommended_action']),
        ]));
    }

    /**
     * Update the specified issue.
     */
    public function update(UpdateIssueRequest $request, Issue $issue)
    {
        Gate::authorize('update', $issue);

        $validated = $request->validated();

        // Handle checkbox defaults
        $validated['cbn_examination_finding'] = $request->has('cbn_examination_finding') ? 1 : 0;
        $validated['regulatory_reportable'] = $request->has('regulatory_reportable') ? 1 : 0;

        $original = $issue->getAttributes();

        $issue->update(array_merge($validated, [
            'updated_by' => auth()->id(),
        ]));

        $this->saveConfiguredAttributes($request, $issue);

        // Audit trail
        \App\Services\AuditTrailService::recordChanges($issue, $original);

        return redirect()->route('risk.issues.show', $issue)
            ->with('success', "Issue {$issue->issue_reference} has been updated.");
    }

    /**
     * Update issue status (transition).
     */
    public function updateStatus(UpdateIssueStatusRequest $request, Issue $issue)
    {
        Gate::authorize('update', $issue);

        $validated = $request->validated();

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

        if (! isset($allowedTransitions[$current]) || ! in_array($new, $allowedTransitions[$current])) {
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
            'update_type' => 'status_change',
            'content' => "Status changed from {$current} to {$new}. ".($validated['status_notes'] ?? ''),
            'created_by' => auth()->id(),
        ]);

        // Audit trail
        \App\Services\AuditTrailService::recordChanges($issue, $original);

        return back()->with('success', "Issue status updated to '{$new}'.");
    }

    /**
     * Add a remediation action to an issue.
     */
    public function addRemediationAction(StoreRemediationActionRequest $request, Issue $issue)
    {
        Gate::authorize('recordProgress', $issue);

        $validated = $request->validated();

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
    public function completeAction(CompleteRemediationActionRequest $request, Issue $issue, IssueRemediationAction $action)
    {
        Gate::authorize('recordProgress', $issue);

        // Not authorisation: the action has to belong to the issue in the URL,
        // or the two ids address different things.
        abort_unless($action->issue_id === $issue->id, 404);

        $validated = $request->validated();

        // `completed_at` and `completed_by` are NOT columns on
        // issue_remediation_actions and are not in its $fillable, so Eloquent
        // dropped them silently: an action was marked complete with no record
        // of WHEN. The real column is `actual_close_date`.
        //
        // Who completed it still is not recorded — there is no column for it.
        // `verified_by` is the only candidate and it means something else, so
        // it is left alone rather than filled with a claim nobody made.
        $action->update([
            'status' => 'completed',
            'actual_close_date' => now()->toDateString(),
            'completion_notes' => $validated['completion_notes'] ?? null,
        ]);

        // Check if all actions are complete
        $pendingActions = IssueRemediationAction::where('issue_id', $issue->id)
            ->where('status', '!=', 'completed')
            ->count();

        if ($pendingActions === 0) {
            IssueProgressUpdate::create([
                'issue_id' => $issue->id,
                'update_type' => 'milestone',
                'content' => 'All remediation actions have been completed.',
                'created_by' => auth()->id(),
            ]);
        }

        return back()->with('success', 'Remediation action marked as complete.');
    }

    /**
     * Add a progress update to an issue.
     */
    public function addProgressUpdate(StoreProgressUpdateRequest $request, Issue $issue)
    {
        Gate::authorize('recordProgress', $issue);

        $validated = $request->validated();

        IssueProgressUpdate::create([
            'issue_id' => $issue->id,
            'update_type' => $validated['update_type'],
            'content' => $validated['description'],
            'created_by' => auth()->id(),
        ]);

        // Update issue progress if provided
        if (! empty($validated['progress_pct'])) {
            $issue->update(['progress_percentage' => $validated['progress_pct']]);
        }

        return back()->with('success', 'Progress update has been added.');
    }

    /**
     * Request closure of an issue.
     */
    public function requestClosure(RequestIssueClosureRequest $request, Issue $issue, \App\Services\Workflow\ModuleApprovals $approvals)
    {
        Gate::authorize('update', $issue);

        if (! in_array($issue->issue_status, ['IN_PROGRESS', 'OVERDUE'])) {
            return back()->with('error', 'Only in-progress or overdue issues can be submitted for closure.');
        }

        $validated = $request->validated();

        $original = $issue->getAttributes();

        $issue->update([
            'issue_status' => 'PENDING_CLOSURE',
            'closure_justification' => $validated['closure_justification'],
            'evidence_of_resolution' => $validated['evidence_of_resolution'] ?? null,
            'closure_requested_at' => now(),
            'closure_requested_by' => auth()->id(),
        ]);

        // WP-06. Raises the approval task against the issue-manager role, and
        // the compliance sign-off step behind it for a regulatory issue. Where
        // the tenant has no published definition the status change above is the
        // whole of it, exactly as before.
        $approvals->submit('issue_closure_approval', $issue, [], $request->user());

        IssueProgressUpdate::create([
            'issue_id' => $issue->id,
            'update_type' => 'milestone',
            'content' => 'Closure requested: '.$validated['closure_justification'],
            'created_by' => auth()->id(),
        ]);

        // Audit trail
        \App\Services\AuditTrailService::recordChanges($issue, $original);

        return back()->with('success', 'Issue closure request has been submitted.');
    }

    /**
     * Approve closure of an issue.
     */
    public function approveClosure(Request $request, Issue $issue, \App\Services\Workflow\ModuleApprovals $approvals)
    {
        Gate::authorize('close', $issue);

        if ($issue->issue_status !== 'PENDING_CLOSURE') {
            return back()->with('error', 'Only issues pending closure can be approved.');
        }

        $original = $issue->getAttributes();
        $comments = $request->string('comments')->toString() ?: null;

        // WP-06. A regulatory issue picks up a compliance sign-off step it never
        // had: closing a CBN examination finding was previously one click by
        // whoever happened to open the screen.
        if (! $approvals->decide($issue, 'approve', $request->user(), ['comments' => $comments])) {
            $approvals->decideDirectly($issue, 'approve', $request->user(), $comments);
        }

        \App\Services\AuditTrailService::recordChanges($issue->refresh(), $original);

        return back()->with('success', $issue->issue_status === 'CLOSED'
            ? "Issue {$issue->issue_reference} has been closed."
            : "Recorded. Issue {$issue->issue_reference} has moved to the next approval step.");
    }

    /**
     * Reject closure of an issue.
     */
    public function rejectClosure(RejectIssueClosureRequest $request, Issue $issue, \App\Services\Workflow\ModuleApprovals $approvals)
    {
        Gate::authorize('close', $issue);

        if ($issue->issue_status !== 'PENDING_CLOSURE') {
            return back()->with('error', 'Only issues pending closure can be rejected.');
        }

        $validated = $request->validated();

        if (! $approvals->decide($issue, 'reject', $request->user(), ['comments' => $validated['rejection_reason']])) {
            $approvals->decideDirectly($issue, 'reject', $request->user(), $validated['rejection_reason']);
        }

        return back()->with('success', 'Issue closure has been rejected. Issue moved back to IN_PROGRESS.');
    }

    /**
     * Ageing analysis report.
     */
    public function ageingReport()
    {
        Gate::authorize('viewAny', Issue::class);

        $issues = $this->ageing->openIssues();

        return Inertia::render('Issues/Ageing', [
            'bands' => $this->ageing->bandSummary($issues),
            'matrix' => $this->ageing->matrix($issues),
            'trend' => $this->ageing->trend(),
            'oldest' => $this->ageing->oldest($issues),
            'total' => $issues->count(),
        ]);
    }

    /**
     * Closure list (issues pending closure).
     */
    public function closureList()
    {
        Gate::authorize('viewAny', Issue::class);

        $orgId = TenantContext::organizationId();

        $pendingClosures = Issue::where('organization_id', $orgId)
            ->where('issue_status', 'PENDING_CLOSURE')
            ->with(['issueOwner', 'businessUnit'])
            ->orderByDesc('closure_requested_at')
            ->paginate(25);

        $pendingClosureCount = Issue::where('organization_id', $orgId)
            ->where('issue_status', 'PENDING_CLOSURE')
            ->count();

        $closedThisMonth = Issue::where('organization_id', $orgId)
            ->where('issue_status', 'CLOSED')
            ->whereBetween('closed_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();

        $returnedCount = Issue::where('organization_id', $orgId)
            ->whereNotNull('closure_rejected_at')
            ->where('issue_status', '!=', 'CLOSED')
            ->count();

        // DATEDIFF() is MySQL-only; the service computes it portably, which
        // is what lets this screen be tested at all.
        $avgClosureTime = $this->figures->averageDaysToClose();

        return Inertia::render('Issues/Closure', [
            'stats' => [
                'pending' => $pendingClosureCount,
                'closedThisMonth' => $closedThisMonth,
                'returned' => $returnedCount,
                'avgClosureTime' => $avgClosureTime,
            ],
            'pending' => [
                'data' => collect($pendingClosures->items())->map(fn (Issue $issue) => [
                    'id' => $issue->id,
                    'reference' => $issue->issue_reference,
                    'title' => $issue->title,
                    'priority' => $issue->priority,
                    'owner' => $issue->issueOwner?->name,
                    'businessUnit' => $issue->businessUnit?->name,
                    'requestedAt' => $issue->closure_requested_at?->format('d M Y'),
                    'justification' => $issue->closure_justification,
                    'canDecide' => Gate::allows('close', $issue),
                    'url' => route('risk.issues.show', $issue),
                ])->all(),
                'links' => $pendingClosures->linkCollection()->toArray(),
                'meta' => ['from' => $pendingClosures->firstItem(), 'to' => $pendingClosures->lastItem(), 'total' => $pendingClosures->total()],
            ],
        ]);
    }

    /**
     * Upload an attachment to an issue.
     */
    public function uploadAttachment(UploadIssueAttachmentRequest $request, Issue $issue)
    {
        Gate::authorize('recordProgress', $issue);

        $validated = $request->validated();

        $file = $request->file('file');
        $path = $file->store("issue-attachments/{$issue->id}", 'local');

        IssueAttachment::create([
            'issue_id' => $issue->id,
            'file_name' => $file->getClientOriginalName(),
            'file_size_bytes' => $file->getSize(),
            'file_type' => $file->getClientOriginalExtension(),
            'storage_path' => $path,
            'document_type' => $validated['document_type'] ?? 'evidence',
            'is_regulatory' => (bool) ($validated['is_regulatory'] ?? false),
            'uploaded_by' => auth()->id(),
        ]);

        return back()->with('success', 'Attachment uploaded successfully.');
    }

    /**
     * Soft-delete an issue.
     */
    public function destroy(Issue $issue)
    {
        Gate::authorize('delete', $issue);

        \App\Services\AuditTrailService::record($issue, 'delete');

        $issue->delete();

        return redirect()->route('risk.issues.index')
            ->with('success', "Issue {$issue->issue_reference} has been deleted.");
    }

    public function downloadAttachment(Issue $issue, IssueAttachment $attachment)
    {
        Gate::authorize('view', $issue);

        abort_unless($attachment->issue_id === $issue->id, 404);

        $disk = Storage::disk('local');
        if (! $disk->exists($attachment->storage_path)) {
            abort(404, 'File not found.');
        }

        return $disk->download($attachment->storage_path, $attachment->file_name);
    }

    /* ------------------------------------------------------------------ */
    /*  Presentation */
    /* ------------------------------------------------------------------ */

    /**
     * The lookups every issue form needs.
     *
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        $orgId = TenantContext::organizationId();

        return [
            'risks' => Risk::where('organization_id', $orgId)->orderBy('risk_code')->get()
                ->map(fn (Risk $risk) => ['id' => $risk->id, 'code' => $risk->risk_code, 'title' => $risk->title])->all(),
            'businessUnits' => BusinessUnit::where('organization_id', $orgId)->orderBy('name')->get()
                ->map(fn (BusinessUnit $unit) => ['id' => $unit->id, 'name' => $unit->name])->all(),
            'users' => User::where('organization_id', $orgId)->orderBy('name')->get()
                ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])->all(),
            'sources' => Issue::SOURCES,
            'priorities' => Issue::PRIORITIES,
        ];
    }

    /**
     * An issue as the edit form's initial values.
     *
     * @return array<string, mixed>
     */
    private function editable(Issue $issue): array
    {
        return [
            'id' => $issue->id,
            'reference' => $issue->issue_reference,
            'title' => $issue->title,
            'description' => $issue->description,
            'issue_source' => $issue->issue_source,
            'issue_category' => $issue->issue_category,
            'priority' => $issue->priority,
            'business_unit_id' => $issue->business_unit_id,
            'responsible_owner_id' => $issue->responsible_owner_id,
            'risk_id' => $issue->risk_register_id,
            'department' => $issue->department,
            'remediation_due_date' => $issue->remediation_due_date?->toDateString(),
            'management_response_due' => $issue->management_response_due?->toDateString(),
            'root_cause' => $issue->root_cause,
            'impact_description' => $issue->impact_description,
            'recommended_action' => $issue->recommended_action,
            'management_response' => $issue->management_response,
            'action_plan' => $issue->action_plan,
        ];
    }

    /**
     * The show page's issue, with everything its tabs render.
     *
     * @return array<string, mixed>
     */
    private function detail(Issue $issue): array
    {
        return [
            'id' => $issue->id,
            'reference' => $issue->issue_reference,
            'title' => $issue->title,
            'description' => $issue->description,
            'status' => $issue->issue_status,
            'priority' => $issue->priority,
            'source' => $issue->issue_source,
            'category' => $issue->issue_category,
            'owner' => $issue->issueOwner?->name,
            'businessUnit' => $issue->businessUnit?->name,
            'department' => $issue->department,
            'dueDate' => $issue->remediation_due_date?->format('d M Y'),
            // The show page has always READ `is_overdue` and it never existed;
            // the accessor on the model computes it now. See the module notes.
            'isOverdue' => $issue->is_overdue,
            'daysOverdue' => $issue->is_overdue && $issue->remediation_due_date
                ? (int) $issue->remediation_due_date->startOfDay()->diffInDays(now()->startOfDay())
                : null,
            'managementResponseDue' => $issue->management_response_due?->format('d M Y'),
            'escalationLevel' => $issue->current_escalation_level,
            'progressPct' => (int) ($issue->progress_percentage ?? 0),
            'rootCause' => $issue->root_cause,
            'impactDescription' => $issue->impact_description,
            'recommendedAction' => $issue->recommended_action,
            'managementResponse' => $issue->management_response,
            'actionPlan' => $issue->action_plan,
            'interimControls' => $issue->interim_controls,
            'closureJustification' => $issue->closure_justification,
            'examinationRef' => $issue->examination_ref,
            'regulatoryReportable' => (bool) $issue->regulatory_reportable,
            'cbnReportable' => (bool) $issue->cbn_reportable,
            // The Blade page read `cbn_regulatory_deadline`, which is not a
            // column — the real one is `cbn_response_deadline`, so that banner
            // never rendered.
            'cbnResponseDeadline' => $issue->cbn_response_deadline?->format('d M Y'),
            'createdAt' => $issue->created_at?->format('d M Y'),
            'risk' => $issue->risk ? [
                'code' => $issue->risk->risk_code,
                'title' => $issue->risk->title,
                'url' => route('risk.register.show', $issue->risk),
            ] : null,
            'remediationActions' => $issue->remediationActions->map(fn ($action) => [
                'id' => $action->id,
                'number' => $action->action_number,
                'description' => $action->description,
                'owner' => $action->owner?->name,
                'targetDate' => $action->target_date?->format('d M Y'),
                'status' => $action->status,
                'completedAt' => $action->actual_close_date?->format('d M Y'),
                'completionNotes' => $action->completion_notes,
            ])->all(),
            'progressUpdates' => $issue->progressUpdates->map(fn ($update) => [
                'id' => $update->id,
                'type' => $update->update_type,
                'content' => $update->content,
                // Progress is a property of the ISSUE, not of an update —
                // addProgressUpdate() writes it to issues.progress_percentage.
                // issue_progress_updates has no such column.
                'author' => $update->createdBy?->name,
                'createdAt' => $update->created_at?->format('d M Y, H:i'),
            ])->all(),
            'escalationLogs' => $issue->escalationLogs->map(fn ($log) => [
                'id' => $log->id,
                // The table records the level REACHED, not a from/to pair.
                'level' => $log->escalation_level,
                'escalatedTo' => $log->escalated_to_role,
                'isAutomatic' => (bool) $log->is_auto,
                'reason' => $log->reason,
                'escalatedAt' => $log->escalated_at?->format('d M Y, H:i'),
            ])->all(),
            'attachments' => $issue->attachments->map(fn ($attachment) => [
                'id' => $attachment->id,
                'name' => $attachment->file_name,
                'documentType' => $attachment->document_type,
                'isRegulatory' => (bool) $attachment->is_regulatory,
                'size' => $attachment->file_size_bytes,
                'downloadUrl' => route('risk.issues.download-attachment', [$issue, $attachment]),
            ])->all(),
        ];
    }
}
