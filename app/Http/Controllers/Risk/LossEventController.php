<?php

namespace App\Http\Controllers\Risk;

use App\Grids\GridRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\LossEvents\StoreLossEventRcaRequest;
use App\Http\Requests\LossEvents\StoreLossEventRequest;
use App\Http\Requests\LossEvents\StoreNearMissRequest;
use App\Http\Requests\LossEvents\SubmitLossEventApprovalRequest;
use App\Http\Requests\LossEvents\UpdateLossEventRequest;
use App\Http\Requests\LossEvents\UpdateLossEventStatusRequest;
use App\Models\BusinessUnit;
use App\Models\LossEvent;
use App\Models\LossEventApproval;
use App\Models\LossEventAttachment;
use App\Models\LossEventRca;
use App\Models\NearMiss;
use App\Models\Risk;
use App\Models\User;
use App\Presenters\GridPresenter;
use App\Services\FileUploadService;
use App\Services\LossEvents\LossEventDashboardService;
use App\Services\LossEvents\LossEventService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class LossEventController extends Controller
{
    public function __construct(
        private readonly FileUploadService $uploads,
        private readonly LossEventService $lossEvents,
        private readonly LossEventDashboardService $figures,
    ) {}

    /**
     * Loss event dashboard with statistics.
     */
    public function dashboard()
    {
        Gate::authorize('viewAny', LossEvent::class);

        return Inertia::render('LossEvents/Dashboard', [
            'kpis' => $this->figures->kpis(),
            'monthlyTrend' => $this->figures->monthlyTrend(),
            'baselCategories' => $this->figures->baselCategories(),
            'recentEvents' => $this->figures->recentEvents(),
            'regulatoryAlerts' => $this->figures->regulatoryAlerts(),
            'canCreate' => Gate::allows('create', LossEvent::class),
        ]);
    }

    /**
     * Display the loss event register.
     *
     * WP-09: search, filters, sorting and export live inside the shared data
     * grid (App\Grids\Definitions\LossEventsGrid); the controller computes
     * only what the page header still needs.
     */
    public function index(Request $request, GridPresenter $presenter)
    {
        $total = LossEvent::where('organization_id', TenantContext::organizationId())->count();

        return Inertia::render('LossEvents/Index', [
            'total' => $total,
            'grid' => fn () => $presenter->present(GridRegistry::resolve('loss_events'), $request, $request->user()),
        ]);
    }

    /**
     * Show the form for creating a new loss event.
     */
    public function create(Request $request)
    {
        Gate::authorize('create', LossEvent::class);

        $orgId = TenantContext::organizationId();

        return Inertia::render('LossEvents/Create', $this->formOptions());
    }

    /**
     * Store a newly created loss event.
     */
    public function store(StoreLossEventRequest $request)
    {
        $orgId = TenantContext::organizationId();

        $validated = $request->validated();

        $lossEvent = $this->lossEvents->report($validated, auth()->id());

        // Raised by EvaluateRegulatoryThresholds during the create, and carried
        // back rather than recomputed: "CBN notification required within seven
        // days" is the reporter's cue to act, and losing it was the one
        // user-visible thing the inline evaluation was doing.
        if ($this->lossEvents->lastAlerts() !== []) {
            session()->flash('regulatory_alerts', $this->lossEvents->lastAlerts());
        }

        return redirect()->route('risk.loss-events.show', $lossEvent)
            ->with('success', "Loss event {$lossEvent->event_reference} has been reported.");
    }

    /**
     * Display the specified loss event with full detail.
     */
    public function show(LossEvent $lossEvent)
    {
        Gate::authorize('view', $lossEvent);

        $lossEvent->load([
            'risk',
            'businessUnit',
            'reporter',
            'rca',
            'failedControls.control',
            'approvals.actionedBy',
            'attachments',
        ]);

        return Inertia::render('LossEvents/Show', [
            'event' => $this->detail($lossEvent),
            'can' => [
                'update' => Gate::allows('update', $lossEvent),
                'delete' => Gate::allows('delete', $lossEvent),
                'recordRca' => Gate::allows('recordRca', $lossEvent),
                'approveRca' => Gate::allows('approveRca', $lossEvent),
                'approve' => Gate::allows('approve', $lossEvent),
            ],
            'options' => [
                'rcaCategories' => LossEventRca::CATEGORIES,
                'rcaMethodologies' => LossEventRca::METHODOLOGIES,
                'statuses' => LossEvent::STATUSES,
            ],
        ]);
    }

    /**
     * Show the form for editing a loss event.
     */
    public function edit(LossEvent $lossEvent)
    {
        Gate::authorize('update', $lossEvent);

        return Inertia::render('LossEvents/Edit', array_merge($this->formOptions(), [
            'event' => $this->editable($lossEvent),
        ]));
    }

    /**
     * Update the specified loss event.
     */
    public function update(UpdateLossEventRequest $request, LossEvent $lossEvent)
    {
        Gate::authorize('update', $lossEvent);

        $validated = $request->validated();

        $this->lossEvents->amend($lossEvent, $validated, auth()->id());

        return redirect()->route('risk.loss-events.show', $lossEvent)
            ->with('success', "Loss event {$lossEvent->event_reference} has been updated.");
    }

    /**
     * Delete the specified loss event.
     */
    public function destroy(LossEvent $lossEvent)
    {
        Gate::authorize('delete', $lossEvent);

        if (! in_array($lossEvent->status, ['reported', 'draft'])) {
            return back()->with('error', 'Only reported or draft loss events can be deleted.');
        }

        $reference = $lossEvent->event_reference;
        $lossEvent->delete();

        return redirect()->route('risk.loss-events.index')
            ->with('success', "Loss event {$reference} has been deleted.");
    }

    /**
     * Update loss event status (PATCH transition).
     */
    public function updateStatus(UpdateLossEventStatusRequest $request, LossEvent $lossEvent)
    {
        Gate::authorize('update', $lossEvent);

        $validated = $request->validated();

        // Validate status transitions
        $allowedTransitions = [
            'reported' => ['under_investigation', 'pending_approval'],
            'under_investigation' => ['pending_approval'],
            'pending_approval' => ['approved', 'under_investigation'],
            'approved' => ['closed', 'reopened'],
            'closed' => ['reopened'],
            'reopened' => ['under_investigation'],
        ];

        $currentStatus = $lossEvent->status;
        $newStatus = $validated['status'];

        if (! isset($allowedTransitions[$currentStatus]) || ! in_array($newStatus, $allowedTransitions[$currentStatus])) {
            return back()->with('error', "Cannot transition from '{$currentStatus}' to '{$newStatus}'.");
        }

        $original = $lossEvent->getAttributes();

        $lossEvent->update([
            // Canonical column, upper case. $lossEvent->status above is the
            // read-only lower-case accessor over this same value.
            'current_status' => strtoupper($newStatus),
            'status_changed_at' => now(),
            'status_changed_by' => auth()->id(),
        ]);

        // Audit trail
        \App\Services\AuditTrailService::recordChanges($lossEvent, $original);

        return back()->with('success', "Loss event status updated to '{$newStatus}'.");
    }

    /**
     * View/create/update Root Cause Analysis for a loss event.
     */
    public function rca(LossEvent $lossEvent)
    {
        Gate::authorize('recordRca', $lossEvent);

        // The RCA form and details live on the loss event detail page.
        return redirect()->route('risk.loss-events.show', ['loss_event' => $lossEvent, 'tab' => 'rca']);
    }

    /**
     * Store/update RCA for a loss event.
     */
    public function storeRca(StoreLossEventRcaRequest $request, LossEvent $lossEvent)
    {
        Gate::authorize('recordRca', $lossEvent);

        $validated = $request->validated();
        $orgId = TenantContext::organizationId();

        $rca = LossEventRca::updateOrCreate(
            ['loss_event_id' => $lossEvent->id],
            [
                'organization_id' => $orgId,
                'methodology' => $validated['methodology'],
                'root_cause_category' => $validated['root_cause_category'],
                // Canonical columns only — root_cause_description,
                // contributing_factors_text, status, performed_by and
                // analysis_date are deprecated duplicates.
                'root_cause_statement' => $validated['root_cause_description'],
                'contributory_factors' => isset($validated['contributing_factors'])
                    ? [$validated['contributing_factors']]
                    : null,
                'analysis_details' => $validated['analysis_details'] ?? null,
                'recommendations' => $validated['recommendations'] ?? null,
                'lessons_learned' => $validated['lessons_learned'] ?? null,
                'rca_status' => 'IN_PROGRESS',
                'completed_by' => auth()->id(),
                'completed_at' => now(),
            ]
        );

        return redirect()->route('risk.loss-events.show', $lossEvent)
            ->with('success', 'Root Cause Analysis has been saved.');
    }

    /**
     * Upload an attachment for a loss event.
     */
    public function uploadAttachment(Request $request, LossEvent $lossEvent)
    {
        Gate::authorize('recordRca', $lossEvent);

        // WP-00. Two defects are closed here, both of which this endpoint was
        // the last in the codebase to carry:
        //
        //  1. NO TYPE RESTRICTION. The rule was `required|file|max:20480` and
        //     nothing else — no `mimes:`, no `mimetypes:`. Every other upload
        //     path in the product validated against a 13-type allowlist; this
        //     one accepted an executable. The policy now comes from
        //     FileUploadService::PROFILE_LOSS_EVENT_ATTACHMENT, so it is
        //     declared in one place with the rest of the upload policy rather
        //     than as a rule string in a controller that can drift again.
        //
        //  2. CLIENT-SUPPLIED FILE TYPE. `file_type` was set from
        //     `$file->getClientMimeType()` — the multipart Content-Type header,
        //     i.e. a string the client chose — and that column is what the
        //     document repository later tells an auditor the file is. The
        //     service derives it from the bytes on disk instead.
        //
        // The 20 MB cap is unchanged; see the profile for why it is wider than
        // the other endpoints' 10 MB.
        $validated = $request->validate([
            'file' => $this->uploads->rules(FileUploadService::PROFILE_LOSS_EVENT_ATTACHMENT),
            'document_type' => 'nullable|string|max:50',
            'is_regulatory' => 'nullable|boolean',
        ]);

        $stored = $this->uploads->store(
            $validated['file'],
            "loss-events/{$lossEvent->id}/attachments",
            FileUploadService::PROFILE_LOSS_EVENT_ATTACHMENT,
        );

        LossEventAttachment::create([
            'loss_event_id' => $lossEvent->id,
            'file_name' => $stored['file_name'],
            'file_size_bytes' => $stored['file_size_bytes'],
            'file_type' => $stored['file_type'],
            'storage_path' => $stored['storage_path'],
            'document_type' => $validated['document_type'] ?? null,
            'is_regulatory' => (bool) ($validated['is_regulatory'] ?? false),
            'uploaded_by' => auth()->id(),
        ]);

        return redirect()->route('risk.loss-events.show', $lossEvent)
            ->with('success', 'File uploaded successfully.');
    }

    /**
     * Download a loss event attachment.
     */
    public function downloadAttachment(LossEvent $lossEvent, LossEventAttachment $attachment)
    {
        $orgId = TenantContext::organizationId();

        if ($lossEvent->organization_id !== $orgId || $attachment->loss_event_id !== $lossEvent->id) {
            abort(403, 'Unauthorized access.');
        }

        if (! Storage::disk('local')->exists($attachment->storage_path)) {
            abort(404, 'File not found.');
        }

        return Storage::disk('local')->download($attachment->storage_path, $attachment->file_name);
    }

    /**
     * Delete a loss event attachment.
     */
    public function deleteAttachment(LossEvent $lossEvent, LossEventAttachment $attachment)
    {
        $orgId = TenantContext::organizationId();

        if ($lossEvent->organization_id !== $orgId || $attachment->loss_event_id !== $lossEvent->id) {
            abort(403, 'Unauthorized access.');
        }

        if (Storage::disk('local')->exists($attachment->storage_path)) {
            Storage::disk('local')->delete($attachment->storage_path);
        }
        $attachment->delete();

        return back()->with('success', 'Attachment removed.');
    }

    /**
     * Approve RCA for a loss event.
     */
    public function approveRca(Request $request, LossEvent $lossEvent)
    {
        Gate::authorize('approveRca', $lossEvent);

        $rca = LossEventRca::where('loss_event_id', $lossEvent->id)->firstOrFail();

        $original = $rca->getAttributes();

        $rca->update([
            'rca_status' => 'APPROVED',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        // Audit trail
        \App\Services\AuditTrailService::recordChanges($rca, $original);

        return back()->with('success', 'Root Cause Analysis has been approved.');
    }

    /**
     * RCA index page (all RCAs).
     */
    public function rcaIndex()
    {
        Gate::authorize('viewAny', LossEvent::class);

        $orgId = TenantContext::organizationId();

        $rcaEvents = LossEvent::where('organization_id', $orgId)
            ->with('rca')
            ->orderByDesc('date_of_loss')
            ->paginate(25)
            ->withQueryString();

        $counts = $this->figures->rcaCounts();

        return Inertia::render('LossEvents/Rca', [
            'counts' => $counts,
            'categories' => $this->figures->rcaCategories(),
            'events' => [
                'data' => collect($rcaEvents->items())->map(fn (LossEvent $event) => [
                    'id' => $event->id,
                    'reference' => $event->event_reference,
                    'title' => $event->title,
                    'dateOfLoss' => $event->date_of_loss?->format('d M Y'),
                    'severity' => $event->event_severity,
                    'rcaStatus' => $event->rca?->rca_status,
                    'rootCause' => $event->rca?->root_cause_category,
                    'url' => route('risk.loss-events.show', $event),
                ])->all(),
                'links' => $rcaEvents->linkCollection()->toArray(),
                'meta' => ['from' => $rcaEvents->firstItem(), 'to' => $rcaEvents->lastItem(), 'total' => $rcaEvents->total()],
            ],
        ]);
    }

    /**
     * Reports for loss events.
     */
    public function reports()
    {
        Gate::authorize('viewAny', LossEvent::class);

        $orgId = TenantContext::organizationId();

        // The Blade page loaded EVERY loss event in the tenant to render a
        // list nothing on the screen used beyond counting it. The count is the
        // figure; the rows are not fetched.
        $recentReports = \App\Models\GeneratedReport::where('organization_id', $orgId)
            ->where('scope', 'loss_events')
            ->with('generatedBy')
            ->orderByDesc('created_at')
            ->limit(25)
            ->get();

        return Inertia::render('LossEvents/Reports', [
            'totalEvents' => LossEvent::where('organization_id', $orgId)->count(),
            'exports' => $this->regulatoryExports(),
            'recentReports' => $recentReports->map(fn ($report) => [
                'id' => $report->id,
                'title' => $report->title ?? $report->report_type,
                'status' => $report->status,
                'generatedBy' => $report->generatedBy?->name,
                'createdAt' => $report->created_at?->format('d M Y, H:i'),
            ])->all(),
        ]);
    }

    /**
     * List near misses. Search, filters, sorting and pagination all moved
     * into the shared data grid (WP-09) — see
     * App\Grids\Definitions\NearMissesGrid. The controller now only feeds
     * the KPI summary cards.
     */
    public function nearMisses(Request $request, GridPresenter $presenter)
    {
        $orgId = TenantContext::organizationId();

        $allNearMisses = NearMiss::where('organization_id', $orgId);
        $totalNearMisses = (clone $allNearMisses)->count();
        $openNearMisses = (clone $allNearMisses)->where('status', 'open')->count();
        $underReviewNearMisses = (clone $allNearMisses)->whereIn('status', ['investigating', 'under review'])->count();
        $potentialLossAvoided = ((clone $allNearMisses)->sum('potential_loss_kobo') ?? 0) / 100;

        return Inertia::render('LossEvents/NearMisses', [
            'totalNearMisses' => $totalNearMisses,
            'openNearMisses' => $openNearMisses,
            'underReviewNearMisses' => $underReviewNearMisses,
            'potentialLossAvoided' => '₦'.number_format($potentialLossAvoided, 2),
            'grid' => fn () => $presenter->present(GridRegistry::resolve('near_misses'), $request, $request->user()),
        ]);
    }

    /**
     * Show form to create a near miss.
     */
    public function createNearMiss()
    {
        Gate::authorize('create', NearMiss::class);

        $orgId = TenantContext::organizationId();

        $risks = Risk::where('organization_id', $orgId)->orderBy('risk_code')->get();
        $businessUnits = BusinessUnit::where('organization_id', $orgId)->orderBy('name')->get();
        $users = User::where('organization_id', $orgId)->orderBy('name')->get();
        $controls = \App\Models\Control::where('organization_id', $orgId)->orderBy('control_code')->get();

        return Inertia::render('LossEvents/CreateNearMiss', array_merge($this->formOptions(), [
            'controls' => $controls->map(fn ($control) => [
                'id' => $control->id,
                'code' => $control->control_code,
                'name' => $control->name,
            ])->all(),
            'severities' => NearMiss::SEVERITIES,
        ]));
    }

    /**
     * Store a near miss event.
     */
    public function storeNearMiss(StoreNearMissRequest $request)
    {
        $orgId = TenantContext::organizationId();

        $validated = $request->validated();

        // Auto-generate near miss reference: NM-YYYY-NNN
        $year = now()->year;
        $lastNm = NearMiss::where('organization_id', $orgId)
            ->where('reference', 'like', "NM-{$year}-%")
            ->orderByDesc('reference')
            ->first();

        $nextNumber = $lastNm ? ((int) substr($lastNm->reference, -3)) + 1 : 1;
        $reference = sprintf('NM-%d-%03d', $year, $nextNumber);

        $nearMiss = NearMiss::create([
            'organization_id' => $orgId,
            'reference' => $reference,
            'event_reference' => $reference,
            'title' => $validated['title'],
            'description' => $validated['description'],
            'date_occurred' => $validated['date_occurred'],
            'date_reported' => now()->toDateString(),
            'business_unit_id' => $validated['business_unit_id'],
            'risk_register_id' => $validated['risk_register_id'] ?? null,
            'severity' => $validated['severity'],
            'potential_loss_kobo' => isset($validated['potential_loss_amount'])
                ? (int) round($validated['potential_loss_amount'] * 100)
                : null,
            'control_gap_identified' => (bool) ($validated['control_gap_identified'] ?? false),
            'control_gap_description' => $validated['control_gap_description'] ?? null,
            'linked_control_id' => $validated['linked_control_id'] ?? null,
            'status' => 'open',
            'reported_by' => $validated['reported_by'],
        ]);

        return redirect()->route('risk.loss-events.near-misses')
            ->with('success', "Near miss {$reference} has been reported.");
    }

    /**
     * Manage approvals workflow.
     */
    public function approvals(Request $request)
    {
        Gate::authorize('viewAny', LossEvent::class);

        $orgId = TenantContext::organizationId();

        $query = LossEvent::where('organization_id', $orgId)
            ->where('current_status', 'PENDING_APPROVAL')
            ->with(['businessUnit', 'reporter']);

        if ($request->filled('severity')) {
            $query->where('event_severity', strtoupper($request->severity));
        }

        $pendingApprovals = $query->orderByDesc('date_of_loss')->paginate(25);

        return Inertia::render('LossEvents/Approvals', [
            'pending' => [
                'data' => collect($pendingApprovals->items())->map(fn (LossEvent $event) => [
                    'id' => $event->id,
                    'reference' => $event->event_reference,
                    'title' => $event->title,
                    'severity' => $event->event_severity,
                    'businessUnit' => $event->businessUnit?->name,
                    'reporter' => $event->reporter?->name,
                    'dateOfLoss' => $event->date_of_loss?->format('d M Y'),
                    'grossLoss' => round(((float) $event->gross_loss_amount_kobo) / 100, 2),
                    'canDecide' => Gate::allows('approve', $event),
                    'url' => route('risk.loss-events.show', $event),
                ])->all(),
                'links' => $pendingApprovals->linkCollection()->toArray(),
                'meta' => ['from' => $pendingApprovals->firstItem(), 'to' => $pendingApprovals->lastItem(), 'total' => $pendingApprovals->total()],
            ],
            'filters' => ['severity' => $request->input('severity')],
            'severities' => LossEvent::SEVERITIES,
        ]);
    }

    /**
     * Submit an approval decision for a loss event.
     */
    public function submitApproval(SubmitLossEventApprovalRequest $request, LossEvent $lossEvent, \App\Services\Workflow\ModuleApprovals $approvals)
    {
        $validated = $request->validated();

        $comments = $validated['decision'] === 'rejected'
            ? $validated['rejection_reason']
            : ($validated['comments'] ?? null);

        // WP-06. The stages that used to be a free-text approval_level on a
        // history row are now real nodes: level_1 → level_2 with a parallel
        // compliance review for a CBN-reportable event. The engine decides
        // which level this decision belongs to, and LossEventBinding keeps
        // writing loss_event_approvals so the CBN screens are unaffected.
        if ($validated['decision'] !== 'escalated'
            && $approvals->decide($lossEvent, $validated['decision'] === 'approved' ? 'approve' : 'reject', $request->user(), [
                'comments' => $comments,
            ])) {
            return back()->with('success', "Loss event {$lossEvent->event_reference}: "
                .ucfirst($validated['decision']).'.');
        }

        // Escalation, and the tenant that has not published the definition yet.
        return DB::transaction(function () use ($validated, $lossEvent, $comments, $approvals, $request) {
            if ($validated['decision'] === 'escalated') {
                $task = $approvals->taskFor($lossEvent, $request->user());

                if ($task !== null) {
                    app(\App\Services\Workflow\WorkflowEngine::class)
                        ->escalate($task, $comments, $request->user());
                }

                LossEventApproval::create([
                    'loss_event_id' => $lossEvent->id,
                    'stage' => $task?->node_code ?? ($validated['approval_level'] ?? 'level_1'),
                    'action' => 'escalated',
                    'decision' => 'escalated',
                    'comments' => $comments,
                    'actioned_by' => auth()->id(),
                    'actioned_at' => now(),
                ]);

                $lossEvent->update(['current_status' => 'ESCALATED']);
            } else {
                LossEventApproval::create([
                    'loss_event_id' => $lossEvent->id,
                    'stage' => $validated['approval_level'] ?? 'level_1',
                    'action' => $validated['decision'],
                    'decision' => $validated['decision'],
                    'comments' => $comments,
                    'actioned_by' => auth()->id(),
                    'actioned_at' => now(),
                ]);

                $approvals->decideDirectly(
                    $lossEvent,
                    $validated['decision'] === 'approved' ? 'approve' : 'reject',
                    $request->user(),
                    $comments,
                );
            }

            return back()->with('success', "Loss event {$lossEvent->event_reference}: "
                .ucfirst($validated['decision']).'.');
        });
    }

    /**
     * Convert a near-miss to a loss event
     */
    public function convertNearMiss(NearMiss $nearMiss)
    {
        Gate::authorize('convert', $nearMiss);

        $orgId = TenantContext::organizationId();

        // Create loss event from near-miss data
        $lossEvent = LossEvent::create([
            'organization_id' => $orgId,
            'event_reference' => \App\Services\ReferenceCodeService::generate('loss_events', 'event_reference', 'LE'),
            'title' => 'Converted: '.$nearMiss->title,
            'description' => $nearMiss->description."\n\n[Converted from Near-Miss: {$nearMiss->reference}]",
            'date_of_loss' => $nearMiss->date_occurred,
            'date_discovered' => $nearMiss->date_reported,
            'business_unit_id' => $nearMiss->business_unit_id,
            'gross_loss_amount_kobo' => $nearMiss->potential_loss_kobo ?? 0,
            'event_severity' => strtoupper($nearMiss->severity ?? 'MEDIUM'),
            'risk_register_id' => $nearMiss->risk_register_id,
            // These four are NOT NULL on loss_events and the near-miss record
            // carries no equivalent, so the converted event starts explicitly
            // unclassified — the edit screen this redirects to is where the
            // handler completes the Basel and CBN ORMS classification.
            'basel_l1_category' => 'UNCLASSIFIED',
            'basel_l2_category' => 'UNCLASSIFIED',
            'cbn_risk_category' => 'UNCLASSIFIED',
            'loss_category' => 'actual_loss',
            'current_status' => 'DRAFT',
            'created_by' => auth()->id(),
        ]);

        // Update near-miss with conversion reference
        $nearMiss->update([
            'status' => 'converted',
            'converted_loss_event_id' => $lossEvent->id,
            'converted_to_loss_event' => true,
        ]);

        // Audit trail
        \App\Services\AuditTrailService::record($lossEvent, 'create', null, null, null, "Converted from near-miss: {$nearMiss->reference}");

        \App\Events\NearMissConverted::dispatch($nearMiss, $lossEvent);

        return redirect()->route('risk.loss-events.edit', $lossEvent)
            ->with('success', 'Near-miss converted to loss event successfully. Please complete the remaining fields.');
    }

    /* ------------------------------------------------------------------ */
    /*  Presentation */
    /* ------------------------------------------------------------------ */

    /**
     * The lookups every loss-event form needs.
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
            'options' => [
                'baselEventTypes' => LossEvent::BASEL_EVENT_TYPES,
                'eventTypes' => LossEvent::EVENT_TYPES,
                'severities' => LossEvent::SEVERITIES,
            ],
        ];
    }

    /**
     * A loss event as the edit form's initial values — in the FORM's field
     * names, which are the 200038 ones, because that is what the write path
     * accepts. LossEventService maps them back onto the canonical columns.
     *
     * @return array<string, mixed>
     */
    private function editable(LossEvent $event): array
    {
        return [
            'id' => $event->id,
            'reference' => $event->event_reference,
            'event_title' => $event->title,
            'event_description' => $event->description,
            'date_of_loss' => $event->date_of_loss?->toDateString(),
            'date_discovered' => $event->date_discovered?->toDateString(),
            'business_unit_id' => $event->business_unit_id,
            'risk_id' => $event->risk_register_id,
            'basel_event_type' => strtolower((string) $event->basel_l1_category),
            'cbn_loss_category' => $event->cbn_risk_category,
            'event_type' => $event->loss_category,
            'severity' => strtolower((string) $event->event_severity),
            'gross_loss_amount' => $this->naira($event->gross_loss_amount_kobo),
            'recovery_amount' => $this->naira($event->other_recovery_kobo),
            'insurance_recovery' => $this->naira($event->insurance_recovery_kobo),
            'currency' => $event->currency ?? 'NGN',
            'root_cause_summary' => $event->initial_root_cause,
            'corrective_action_summary' => $event->corrective_action_summary,
            'is_regulatory_reportable' => (bool) $event->is_regulatory_reportable,
            'regulatory_body' => $event->regulatory_body,
            'reporting_deadline' => $event->reporting_deadline,
        ];
    }

    /**
     * The show page's event, with the tabs' contents.
     *
     * @return array<string, mixed>
     */
    private function detail(LossEvent $event): array
    {
        $gross = $this->naira($event->gross_loss_amount_kobo);
        $insurance = $this->naira($event->insurance_recovery_kobo);
        $other = $this->naira($event->other_recovery_kobo);

        return [
            'id' => $event->id,
            'reference' => $event->event_reference,
            'title' => $event->title,
            'description' => $event->description,
            'status' => $event->current_status,
            'severity' => $event->event_severity,
            'baselCategory' => $event->basel_l1_category,
            'cbnCategory' => $event->cbn_risk_category,
            'lossCategory' => $event->loss_category,
            'isNearMiss' => (bool) $event->is_near_miss,
            'dateOfLoss' => $event->date_of_loss?->format('d M Y'),
            'dateDiscovered' => $event->date_discovered?->format('d M Y'),
            'businessUnit' => $event->businessUnit?->name,
            'reporter' => $event->reporter?->name,
            'currency' => $event->currency ?? 'NGN',
            'grossLoss' => $gross,
            'insuranceRecovery' => $insurance,
            'otherRecovery' => $other,
            // Net is derived here rather than in three places on the page.
            'netLoss' => round($gross - $insurance - $other, 2),
            'isRegulatoryReportable' => (bool) $event->is_regulatory_reportable,
            'regulatoryBody' => $event->regulatory_body,
            'reportingDeadline' => $event->reporting_deadline,
            'cbnReportable' => (bool) $event->cbn_reportable,
            'nfiuReportable' => (bool) $event->nfiu_reportable,
            'initialRootCause' => $event->initial_root_cause,
            'correctiveActionSummary' => $event->corrective_action_summary,
            'risk' => $event->risk ? [
                'code' => $event->risk->risk_code,
                'title' => $event->risk->title,
                'url' => route('risk.register.show', $event->risk),
            ] : null,
            'rca' => $event->rca ? [
                'category' => $event->rca->root_cause_category,
                'description' => $event->rca->root_cause_statement,
                'contributingFactors' => $event->rca->contributing_factors,
                'methodology' => $event->rca->methodology,
                'analysisDetails' => $event->rca->analysis_details,
                'recommendations' => $event->rca->recommendations,
                'lessonsLearned' => $event->rca->lessons_learned,
                'status' => $event->rca->rca_status,
            ] : null,
            'failedControls' => $event->failedControls->map(fn ($link) => [
                'id' => $link->id,
                'code' => $link->control?->control_code,
                'name' => $link->control?->name,
            ])->all(),
            'approvals' => $event->approvals->map(fn ($approval) => [
                'id' => $approval->id,
                'stage' => $approval->stage,
                'decision' => $approval->decision,
                'comments' => $approval->comments,
                'actionedBy' => $approval->actionedBy?->name,
                'actionedAt' => $approval->actioned_at?->format('d M Y, H:i'),
            ])->all(),
            'attachments' => $event->attachments->map(fn ($attachment) => [
                'id' => $attachment->id,
                'name' => $attachment->original_name ?? $attachment->file_name,
                'documentType' => $attachment->document_type,
                'isRegulatory' => (bool) $attachment->is_regulatory,
                'size' => $attachment->file_size,
                'downloadUrl' => route('risk.loss-events.download-attachment', [$event, $attachment]),
            ])->all(),
        ];
    }

    /**
     * The six regulatory CSV exports this screen links to. They live in
     * ExportController and are untouched by this phase.
     *
     * @return list<array{label: string, description: string, url: string}>
     */
    private function regulatoryExports(): array
    {
        return collect([
            ['risk.export.loss-events.cbn-orms', 'CBN ORMS return', 'Operational risk events in the CBN return format.'],
            ['risk.export.loss-events.basel', 'Basel matrix', 'Gross loss by Basel level-1 category and business line.'],
            ['risk.export.loss-events.nfiu', 'NFIU filing', 'Suspicious transaction reports awaiting filing.'],
            ['risk.export.loss-events.management', 'Management report', 'The internal summary, by unit and severity.'],
            ['risk.export.loss-events.trends', 'Loss trends', 'Frequency and severity over time.'],
            ['risk.export.loss-events.full', 'Full register', 'Every reported event with its recoveries.'],
        ])
            ->filter(fn (array $export) => \Illuminate\Support\Facades\Route::has($export[0]))
            ->map(fn (array $export) => [
                'label' => $export[1],
                'description' => $export[2],
                'url' => route($export[0]),
            ])
            ->values()
            ->all();
    }

    /** Kobo to naira. */
    private function naira(float|int|string|null $kobo): float
    {
        return round(((float) $kobo) / 100, 2);
    }
}
