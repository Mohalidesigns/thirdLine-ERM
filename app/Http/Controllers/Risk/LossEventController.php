<?php

namespace App\Http\Controllers\Risk;

use App\Grids\GridRegistry;
use App\Http\Controllers\Controller;
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
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class LossEventController extends Controller
{
    public function __construct(
        private readonly FileUploadService $uploads,
    ) {}

    /**
     * Canonical current_status values that count as "still open".
     *
     * current_status is upper case (see docs/schema/canonical-columns.md); the
     * lower-case `status` accessor exists only for the views.
     */
    private const OPEN_STATUSES = ['REPORTED', 'UNDER_INVESTIGATION'];

    /**
     * Loss event dashboard with statistics.
     */
    public function dashboard()
    {
        $orgId = TenantContext::organizationId();
        $currentYear = now()->year;

        $baseQuery = fn () => LossEvent::where('organization_id', $orgId)->whereYear('date_of_loss', $currentYear);

        // Money lives in kobo; the dashboard renders naira.
        $totalEvents = $baseQuery()->count();
        $totalGrossLoss = (float) $baseQuery()->sum('gross_loss_amount_kobo') / 100;
        $recoveredAmount = (float) $baseQuery()
            ->sum(DB::raw('insurance_recovery_kobo + other_recovery_kobo')) / 100;
        $netLossYtd = (float) $baseQuery()
            ->sum(DB::raw('gross_loss_amount_kobo - insurance_recovery_kobo - other_recovery_kobo')) / 100;

        $pendingCbnNotifications = LossEvent::where('organization_id', $orgId)
            ->where('is_regulatory_reportable', true)
            ->whereIn('current_status', self::OPEN_STATUSES)
            ->count();
        $pendingNfiuFilings = LossEvent::where('organization_id', $orgId)
            ->where('nfiu_reportable', true)
            ->where(function ($q) {
                $q->whereNull('nfiu_report_filed')->orWhere('nfiu_report_filed', false);
            })
            ->count();
        $openInvestigations = LossEvent::where('organization_id', $orgId)
            ->whereIn('current_status', self::OPEN_STATUSES)
            ->count();
        $nearMisses = NearMiss::where('organization_id', $orgId)
            ->whereYear('date_occurred', $currentYear)
            ->count();

        // Monthly loss trend, 12 months, zero-filled
        $raw = LossEvent::where('organization_id', $orgId)
            ->whereYear('date_of_loss', $currentYear)
            ->selectRaw('MONTH(date_of_loss) as m, COUNT(*) as c, SUM(gross_loss_amount_kobo) / 100 as s')
            ->groupByRaw('MONTH(date_of_loss)')
            ->get()
            ->keyBy('m');
        $monthLabels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $counts = [];
        $losses = [];
        for ($m = 1; $m <= 12; $m++) {
            $row = $raw->get($m);
            $counts[] = $row ? (int) $row->c : 0;
            $losses[] = $row ? (float) $row->s : 0;
        }
        $monthlyTrendData = ['labels' => $monthLabels, 'counts' => $counts, 'losses' => $losses];

        // By Basel category
        $baselRows = LossEvent::where('organization_id', $orgId)
            ->whereYear('date_of_loss', $currentYear)
            ->selectRaw('basel_l1_category, COUNT(*) as count, SUM(gross_loss_amount_kobo) / 100 as total_loss')
            ->groupBy('basel_l1_category')
            ->orderByDesc('total_loss')
            ->get();
        $baselCategoryData = [
            'labels' => $baselRows->pluck('basel_l1_category')->map(fn ($v) => $v ?: 'Unclassified')->toArray(),
            'values' => $baselRows->pluck('total_loss')->map(fn ($v) => (float) $v)->toArray(),
        ];

        $recentEvents = LossEvent::where('organization_id', $orgId)
            ->orderByDesc('date_of_loss')
            ->limit(10)
            ->get();

        $regulatoryAlerts = LossEvent::where('organization_id', $orgId)
            ->where('is_regulatory_reportable', true)
            ->whereIn('current_status', self::OPEN_STATUSES)
            ->orderBy('date_of_loss')
            ->limit(5)
            ->get()
            ->map(function ($e) {
                $deadline = $e->date_of_loss ? $e->date_of_loss->copy()->addDays(3) : null;

                return (object) [
                    'type' => 'CBN ORMS notification',
                    'event_reference' => $e->reference ?? ('LE-'.$e->id),
                    'deadline' => $deadline,
                    'is_overdue' => $deadline ? $deadline->isPast() : false,
                ];
            });

        return view('risk.loss-events.dashboard', compact(
            'totalEvents', 'totalGrossLoss', 'recoveredAmount', 'netLossYtd',
            'pendingCbnNotifications', 'pendingNfiuFilings', 'openInvestigations', 'nearMisses',
            'monthlyTrendData', 'baselCategoryData',
            'recentEvents', 'regulatoryAlerts'
        ));
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
        $orgId = TenantContext::organizationId();

        $step = $request->get('step', 1);
        $risks = Risk::where('organization_id', $orgId)->orderBy('risk_code')->get();
        $businessUnits = BusinessUnit::where('organization_id', $orgId)->orderBy('name')->get();
        $users = User::where('organization_id', $orgId)->orderBy('name')->get();

        return view('risk.loss-events.create', compact('step', 'risks', 'businessUnits', 'users'));
    }

    /**
     * Form fields that are already canonical and pass straight through.
     */
    private const PASSTHROUGH_FIELDS = [
        'date_of_loss',
        'date_discovered',
        'business_unit_id',
        'reported_by',
        'currency',
        'corrective_action_summary',
        'regulatory_body',
        'reporting_deadline',
        'is_regulatory_reportable',
    ];

    /**
     * The subset of the validated input that already names canonical columns.
     *
     * Taken by allow-list rather than by unsetting the deprecated keys: an
     * unset-list silently lets a newly added form field through to a column
     * that may not exist, which is how the two-sources-of-truth problem got
     * here in the first place.
     */
    private function retainedAttributes(array $validated): array
    {
        return array_intersect_key($validated, array_flip(self::PASSTHROUGH_FIELDS));
    }

    /**
     * Translate the form's field names onto the canonical columns.
     *
     * The create/edit forms still post the 200038 names (event_title,
     * gross_loss_amount, severity, …) because those are what the Blade
     * templates and every bookmarked filter URL use. This is the single place
     * that mapping happens; nothing else writes a deprecated column.
     *
     * Case matters. basel_l1_category, cbn_risk_category and event_severity
     * are stored upper case because that is what RegulatoryThresholdService
     * matches on — the previous lower-case write is why the NFIU STR, EFCC and
     * cyber-fraud alerts never fired. See docs/schema/canonical-columns.md.
     */
    private function canonicalAttributes(array $validated): array
    {
        $baselCategory = strtoupper($validated['basel_event_type'] ?? 'OTHER');

        return [
            'title' => $validated['event_title'],
            'description' => $validated['event_description'],
            'initial_root_cause' => $validated['root_cause_summary'] ?? null,
            'basel_l1_category' => $baselCategory,
            // The form collects a single Basel classification. Mirroring it
            // into L2 keeps the NOT NULL constraint satisfied and matches the
            // behaviour this replaced; a genuine L2/L3 taxonomy is WP-10 work.
            'basel_l2_category' => $baselCategory,
            'cbn_risk_category' => strtoupper($validated['cbn_loss_category'] ?? 'OTHER'),
            'loss_category' => $validated['event_type'] ?? 'actual_loss',
            'event_severity' => strtoupper($validated['severity'] ?? 'MODERATE'),
            // Money is stored in minor units, with the currency alongside it.
            'gross_loss_amount_kobo' => (int) round(($validated['gross_loss_amount'] ?? 0) * 100),
            'insurance_recovery_kobo' => (int) round(($validated['insurance_recovery'] ?? 0) * 100),
            'other_recovery_kobo' => (int) round(($validated['recovery_amount'] ?? 0) * 100),
        ];
    }

    /**
     * Store a newly created loss event.
     */
    public function store(Request $request)
    {
        $orgId = TenantContext::organizationId();

        $validated = $request->validate([
            'event_title' => 'required|string|max:255',
            'event_description' => 'required|string|max:5000',
            'date_of_loss' => 'required|date',
            'date_discovered' => 'required|date|after_or_equal:date_of_loss',
            'business_unit_id' => 'required|exists:business_units,id',
            'risk_id' => 'nullable|exists:risks,id',
            'reported_by' => 'required|exists:users,id',
            // Classification
            'basel_event_type' => 'required|in:internal_fraud,external_fraud,employment_practices,clients_products,damage_physical_assets,business_disruption,execution_delivery',
            'cbn_loss_category' => 'nullable|string|max:255',
            'event_type' => 'required|in:actual_loss,potential_loss,near_miss,gain_event',
            'severity' => 'required|in:insignificant,minor,moderate,major,catastrophic',
            // Financial impact
            'gross_loss_amount' => 'required|numeric|min:0',
            'recovery_amount' => 'nullable|numeric|min:0',
            'insurance_recovery' => 'nullable|numeric|min:0',
            'is_near_miss' => 'nullable|boolean',
            'currency' => 'required|string|max:3',
            // Additional
            'root_cause_summary' => 'nullable|string|max:2000',
            'corrective_action_summary' => 'nullable|string|max:2000',
            'is_regulatory_reportable' => 'nullable|boolean',
            'regulatory_body' => 'nullable|string|max:255',
            'reporting_deadline' => 'nullable|date',
        ]);

        return DB::transaction(function () use ($validated, $orgId) {
            // Auto-generate event reference using ReferenceCodeService
            $eventReference = \App\Services\ReferenceCodeService::generate('loss_events', 'event_reference', 'LE');

            // Near misses always have zero loss + get classified as near_miss
            $isNearMiss = (bool) ($validated['is_near_miss'] ?? false);
            if ($isNearMiss) {
                $validated['gross_loss_amount'] = 0;
                $validated['recovery_amount'] = 0;
                $validated['insurance_recovery'] = 0;
                $validated['event_type'] = 'near_miss';
            }

            // Auto-detect regulatory threshold (example: amounts over 10M NGN)
            $regulatoryThreshold = 10000000; // 10 million
            $isRegulatoryReportable = $validated['is_regulatory_reportable']
                ?? ($validated['gross_loss_amount'] >= $regulatoryThreshold);

            // Map form field risk_id to actual DB column risk_register_id
            $riskRegisterId = $validated['risk_id'] ?? null;
            unset($validated['risk_id']);

            $lossEvent = LossEvent::create(array_merge(
                $this->retainedAttributes($validated),
                $this->canonicalAttributes($validated),
                [
                    'organization_id' => $orgId,
                    'event_reference' => $eventReference,
                    'risk_register_id' => $riskRegisterId,
                    'is_regulatory_reportable' => $isRegulatoryReportable,
                    'is_near_miss' => $isNearMiss,
                    'current_status' => 'REPORTED',
                    'created_by' => auth()->id(),
                ]
            ));

            \App\Events\LossEventCreated::dispatch($lossEvent);

            // Evaluate regulatory thresholds
            $regulatoryService = new \App\Services\RegulatoryThresholdService;
            $alerts = $regulatoryService->evaluateThresholds($lossEvent);

            if (! empty($alerts)) {
                session()->flash('regulatory_alerts', $alerts);
            }

            // Audit trail
            \App\Services\AuditTrailService::record($lossEvent, 'create');

            return redirect()->route('risk.loss-events.show', $lossEvent)
                ->with('success', "Loss event {$eventReference} has been reported.");
        });
    }

    /**
     * Display the specified loss event with full detail.
     */
    public function show(LossEvent $lossEvent)
    {
        $orgId = TenantContext::organizationId();

        if ($lossEvent->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this loss event.');
        }

        $lossEvent->load([
            'risk',
            'businessUnit',
            'reporter',
            'rca',
            'failedControls.control',
            'approvals.actionedBy',
            'attachments',
        ]);

        return view('risk.loss-events.show', compact('lossEvent'));
    }

    /**
     * Show the form for editing a loss event.
     */
    public function edit(LossEvent $lossEvent)
    {
        $orgId = TenantContext::organizationId();

        if ($lossEvent->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this loss event.');
        }

        $risks = Risk::where('organization_id', $orgId)->orderBy('risk_code')->get();
        $businessUnits = BusinessUnit::where('organization_id', $orgId)->orderBy('name')->get();
        $users = User::where('organization_id', $orgId)->orderBy('name')->get();

        return view('risk.loss-events.edit', compact('lossEvent', 'risks', 'businessUnits', 'users'));
    }

    /**
     * Update the specified loss event.
     */
    public function update(Request $request, LossEvent $lossEvent)
    {
        $orgId = TenantContext::organizationId();

        if ($lossEvent->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this loss event.');
        }

        $validated = $request->validate([
            'event_title' => 'required|string|max:255',
            'event_description' => 'required|string|max:5000',
            'date_of_loss' => 'required|date',
            'date_discovered' => 'required|date|after_or_equal:date_of_loss',
            'business_unit_id' => 'required|exists:business_units,id',
            'risk_id' => 'nullable|exists:risks,id',
            'basel_event_type' => 'required|in:internal_fraud,external_fraud,employment_practices,clients_products,damage_physical_assets,business_disruption,execution_delivery',
            'cbn_loss_category' => 'nullable|string|max:255',
            'event_type' => 'required|in:actual_loss,potential_loss,near_miss,gain_event',
            'severity' => 'required|in:insignificant,minor,moderate,major,catastrophic',
            'gross_loss_amount' => 'required|numeric|min:0',
            'recovery_amount' => 'nullable|numeric|min:0',
            'insurance_recovery' => 'nullable|numeric|min:0',
            'currency' => 'required|string|max:3',
            'root_cause_summary' => 'nullable|string|max:2000',
            'corrective_action_summary' => 'nullable|string|max:2000',
            'is_regulatory_reportable' => 'nullable|boolean',
            'regulatory_body' => 'nullable|string|max:255',
            'reporting_deadline' => 'nullable|date',
        ]);

        // Map form field risk_id to actual DB column risk_register_id
        $riskRegisterId = $validated['risk_id'] ?? null;
        unset($validated['risk_id']);

        $original = $lossEvent->getAttributes();

        $lossEvent->update(array_merge(
            $this->retainedAttributes($validated),
            $this->canonicalAttributes($validated),
            [
                'risk_register_id' => $riskRegisterId,
                'updated_by' => auth()->id(),
            ]
        ));

        // Audit trail
        \App\Services\AuditTrailService::recordChanges($lossEvent, $original);

        $newKobo = (int) round($validated['gross_loss_amount'] * 100);
        $oldKobo = (int) ($original['gross_loss_amount_kobo'] ?? 0);
        if ($newKobo !== $oldKobo) {
            \App\Events\LossEventAmountChanged::dispatch($lossEvent, $oldKobo, $newKobo);
        }

        return redirect()->route('risk.loss-events.show', $lossEvent)
            ->with('success', "Loss event {$lossEvent->event_reference} has been updated.");
    }

    /**
     * Delete the specified loss event.
     */
    public function destroy(LossEvent $lossEvent)
    {
        $orgId = TenantContext::organizationId();

        if ($lossEvent->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this loss event.');
        }

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
    public function updateStatus(Request $request, LossEvent $lossEvent)
    {
        $orgId = TenantContext::organizationId();

        if ($lossEvent->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this loss event.');
        }

        $validated = $request->validate([
            'status' => 'required|in:reported,under_investigation,pending_approval,approved,closed,reopened',
            'status_notes' => 'nullable|string|max:1000',
        ]);

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
        $orgId = TenantContext::organizationId();

        if ($lossEvent->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this loss event.');
        }

        // The RCA form and details live on the loss event detail page.
        return redirect()->route('risk.loss-events.show', ['loss_event' => $lossEvent, 'tab' => 'rca']);
    }

    /**
     * Store/update RCA for a loss event.
     */
    public function storeRca(Request $request, LossEvent $lossEvent)
    {
        $orgId = TenantContext::organizationId();

        if ($lossEvent->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this loss event.');
        }

        $validated = $request->validate([
            'root_cause_category' => 'required|in:people,process,system,external',
            'root_cause_description' => 'required|string|max:5000',
            'contributing_factors' => 'nullable|string|max:3000',
            'methodology' => 'required|in:five_whys,fishbone,fault_tree,other',
            'analysis_details' => 'nullable|string|max:5000',
            'recommendations' => 'nullable|string|max:3000',
            'lessons_learned' => 'nullable|string|max:3000',
        ]);

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
        $orgId = TenantContext::organizationId();

        if ($lossEvent->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this loss event.');
        }

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
        $orgId = TenantContext::organizationId();

        if ($lossEvent->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this loss event.');
        }

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
        $orgId = TenantContext::organizationId();

        $rcaEvents = LossEvent::where('organization_id', $orgId)
            ->with('rca')
            ->orderByDesc('date_of_loss')
            ->paginate(25)
            ->withQueryString();

        $rcaBase = LossEventRca::where('organization_id', $orgId);
        $totalRcas = (clone $rcaBase)->count();
        $completedRcas = (clone $rcaBase)->whereIn('rca_status', ['APPROVED', 'COMPLETED'])->count();
        $inProgressRcas = (clone $rcaBase)->whereIn('rca_status', ['NOT_STARTED', 'IN_PROGRESS'])->count();
        $pendingRcas = LossEvent::where('organization_id', $orgId)->whereDoesntHave('rca')->count();

        $categoryLabels = ['People', 'Process', 'Systems', 'External', 'Governance'];
        $categoryCounts = (clone $rcaBase)
            ->selectRaw('root_cause_category, COUNT(*) as c')
            ->groupBy('root_cause_category')
            ->pluck('c', 'root_cause_category');
        $rcaCategoryData = [
            'labels' => $categoryLabels,
            'values' => array_map(
                fn ($label) => (int) ($categoryCounts[strtolower($label === 'Systems' ? 'system' : $label)] ?? 0),
                $categoryLabels
            ),
        ];

        $rcaStatusData = [
            'labels' => ['Completed', 'In Progress', 'Pending'],
            'values' => [$completedRcas, $inProgressRcas, $pendingRcas],
        ];

        return view('risk.loss-events.rca', compact(
            'rcaEvents', 'totalRcas', 'completedRcas', 'inProgressRcas', 'pendingRcas',
            'rcaCategoryData', 'rcaStatusData'
        ));
    }

    /**
     * Reports for loss events.
     */
    public function reports()
    {
        $orgId = TenantContext::organizationId();

        $lossEvents = LossEvent::where('organization_id', $orgId)
            ->orderByDesc('date_of_loss')
            ->get();

        $recentReports = \App\Models\GeneratedReport::where('organization_id', $orgId)
            ->where('scope', 'loss_events')
            ->with('generatedBy')
            ->orderByDesc('created_at')
            ->limit(25)
            ->get();

        return view('risk.loss-events.reports', compact('lossEvents', 'recentReports'));
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
        $orgId = TenantContext::organizationId();

        $risks = Risk::where('organization_id', $orgId)->orderBy('risk_code')->get();
        $businessUnits = BusinessUnit::where('organization_id', $orgId)->orderBy('name')->get();
        $users = User::where('organization_id', $orgId)->orderBy('name')->get();
        $controls = \App\Models\Control::where('organization_id', $orgId)->orderBy('control_code')->get();

        return view('risk.loss-events.create-near-miss', compact('risks', 'businessUnits', 'users', 'controls'));
    }

    /**
     * Store a near miss event.
     */
    public function storeNearMiss(Request $request)
    {
        $orgId = TenantContext::organizationId();

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string|max:5000',
            'date_occurred' => 'required|date',
            'business_unit_id' => 'required|exists:business_units,id',
            'risk_register_id' => 'nullable|exists:risks,id',
            'severity' => 'required|in:low,medium,high,critical',
            'potential_loss_amount' => 'nullable|numeric|min:0',
            'control_gap_identified' => 'nullable|boolean',
            'control_gap_description' => 'nullable|string|max:2000',
            'linked_control_id' => 'nullable|exists:controls,id',
            'reported_by' => 'required|exists:users,id',
        ]);

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
        $orgId = TenantContext::organizationId();

        $query = LossEvent::where('organization_id', $orgId)
            ->where('current_status', 'PENDING_APPROVAL')
            ->with(['businessUnit', 'reporter']);

        if ($request->filled('severity')) {
            $query->where('event_severity', strtoupper($request->severity));
        }

        $pendingApprovals = $query->orderByDesc('date_of_loss')->paginate(25);

        return view('risk.loss-events.approvals', compact('pendingApprovals'));
    }

    /**
     * Submit an approval decision for a loss event.
     */
    public function submitApproval(Request $request, LossEvent $lossEvent, \App\Services\Workflow\ModuleApprovals $approvals)
    {
        abort_unless(auth()->user()->can('approve-loss-event', $lossEvent), 403,
            'Only an assigned handler, loss-event-manager, compliance-officer or CRO can decide on loss events.');

        $validated = $request->validate([
            'decision' => 'required|in:approved,rejected,escalated',
            'comments' => 'nullable|string|max:2000',
            'rejection_reason' => 'required_if:decision,rejected|nullable|string|max:2000',
            'approval_level' => 'nullable|in:level_1,level_2,level_3',
        ]);

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
        $orgId = TenantContext::organizationId();

        if ($nearMiss->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this near-miss.');
        }

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
}
