<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\LossEvent;
use App\Models\LossEventApproval;
use App\Models\LossEventAttachment;
use App\Models\LossEventRca;
use App\Models\LossEventControl;
use App\Models\NearMiss;
use App\Models\Risk;
use App\Models\BusinessUnit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LossEventController extends Controller
{
    /**
     * Loss event dashboard with statistics.
     */
    public function dashboard()
    {
        $orgId = auth()->user()->organization_id ?? 1;
        $currentYear = now()->year;

        $baseQuery = fn() => LossEvent::where('organization_id', $orgId)->whereYear('date_of_loss', $currentYear);

        $totalEvents     = $baseQuery()->count();
        $totalGrossLoss  = (float) $baseQuery()->sum('gross_loss_amount');
        $recoveredAmount = (float) $baseQuery()->sum('recovery_amount');
        $netLossYtd      = (float) $baseQuery()->sum('net_loss_amount');

        $pendingCbnNotifications = LossEvent::where('organization_id', $orgId)
            ->where('is_regulatory_reportable', true)
            ->whereIn('status', ['reported', 'under_investigation'])
            ->count();
        $pendingNfiuFilings = LossEvent::where('organization_id', $orgId)
            ->where('nfiu_reportable', true)
            ->where(function ($q) {
                $q->whereNull('nfiu_report_filed')->orWhere('nfiu_report_filed', false);
            })
            ->count();
        $openInvestigations = LossEvent::where('organization_id', $orgId)
            ->whereIn('status', ['reported', 'under_investigation'])
            ->count();
        $nearMisses = NearMiss::where('organization_id', $orgId)
            ->whereYear('date_occurred', $currentYear)
            ->count();

        // Monthly loss trend, 12 months, zero-filled
        $raw = LossEvent::where('organization_id', $orgId)
            ->whereYear('date_of_loss', $currentYear)
            ->selectRaw('MONTH(date_of_loss) as m, COUNT(*) as c, SUM(gross_loss_amount) as s')
            ->groupByRaw('MONTH(date_of_loss)')
            ->get()
            ->keyBy('m');
        $monthLabels = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
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
            ->selectRaw('basel_event_type, COUNT(*) as count, SUM(gross_loss_amount) as total_loss')
            ->groupBy('basel_event_type')
            ->orderByDesc('total_loss')
            ->get();
        $baselCategoryData = [
            'labels' => $baselRows->pluck('basel_event_type')->map(fn($v) => $v ?: 'Unclassified')->toArray(),
            'values' => $baselRows->pluck('total_loss')->map(fn($v) => (float) $v)->toArray(),
        ];

        $recentEvents = LossEvent::where('organization_id', $orgId)
            ->orderByDesc('date_of_loss')
            ->limit(10)
            ->get();

        $regulatoryAlerts = LossEvent::where('organization_id', $orgId)
            ->where('is_regulatory_reportable', true)
            ->whereIn('status', ['reported', 'under_investigation'])
            ->orderBy('date_of_loss')
            ->limit(5)
            ->get()
            ->map(function ($e) {
                $deadline = $e->date_of_loss ? $e->date_of_loss->copy()->addDays(3) : null;
                return (object) [
                    'type' => 'CBN ORMS notification',
                    'event_reference' => $e->reference ?? ('LE-' . $e->id),
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
     * Display loss events listing with filters.
     */
    public function index(Request $request)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $query = LossEvent::where('organization_id', $orgId);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('basel_event_type')) {
            $query->where('basel_event_type', $request->basel_event_type);
        }

        if ($request->filled('cbn_category')) {
            $query->where('cbn_loss_category', $request->cbn_category);
        }

        if ($request->filled('severity')) {
            $query->where('severity', $request->severity);
        }

        if ($request->filled('date_from')) {
            $query->where('date_of_loss', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->where('date_of_loss', '<=', $request->date_to);
        }

        if ($request->filled('regulatory_reportable')) {
            $query->where('is_regulatory_reportable', $request->boolean('regulatory_reportable'));
        }

        if ($request->filled('business_unit_id')) {
            $query->where('business_unit_id', $request->business_unit_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('event_reference', 'like', "%{$search}%")
                  ->orWhere('event_title', 'like', "%{$search}%")
                  ->orWhere('event_description', 'like', "%{$search}%");
            });
        }

        $lossEvents = $query->orderByDesc('date_of_loss')->paginate(25)->withQueryString();

        $businessUnits = BusinessUnit::where('organization_id', $orgId)->orderBy('name')->get();

        $baselL1Categories = LossEvent::where('organization_id', $orgId)
            ->whereNotNull('basel_event_type')
            ->distinct()
            ->orderBy('basel_event_type')
            ->pluck('basel_event_type')
            ->map(fn ($v) => (object) ['id' => $v, 'name' => \Illuminate\Support\Str::of($v)->replace('_', ' ')->title()]);

        $cbnCategories = LossEvent::where('organization_id', $orgId)
            ->whereNotNull('cbn_loss_category')
            ->distinct()
            ->orderBy('cbn_loss_category')
            ->pluck('cbn_loss_category')
            ->map(fn ($v) => (object) ['id' => $v, 'name' => $v]);

        return view('risk.loss-events.index', compact('lossEvents', 'businessUnits', 'baselL1Categories', 'cbnCategories'));
    }

    /**
     * Show the form for creating a new loss event.
     */
    public function create(Request $request)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $step = $request->get('step', 1);
        $risks = Risk::where('organization_id', $orgId)->orderBy('risk_code')->get();
        $businessUnits = BusinessUnit::where('organization_id', $orgId)->orderBy('name')->get();
        $users = User::where('organization_id', $orgId)->orderBy('name')->get();

        return view('risk.loss-events.create', compact('step', 'risks', 'businessUnits', 'users'));
    }

    /**
     * Store a newly created loss event.
     */
    public function store(Request $request)
    {
        $orgId = auth()->user()->organization_id ?? 1;

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

            // Calculate net loss
            $netLoss = $validated['gross_loss_amount']
                - ($validated['recovery_amount'] ?? 0)
                - ($validated['insurance_recovery'] ?? 0);

            // Auto-detect regulatory threshold (example: amounts over 10M NGN)
            $regulatoryThreshold = 10000000; // 10 million
            $isRegulatoryReportable = $validated['is_regulatory_reportable']
                ?? ($validated['gross_loss_amount'] >= $regulatoryThreshold);

            // Map form field risk_id to actual DB column risk_register_id
            $riskRegisterId = $validated['risk_id'] ?? null;
            unset($validated['risk_id']);

            $lossEvent = LossEvent::create(array_merge($validated, [
                'organization_id' => $orgId,
                'event_reference' => $eventReference,
                'risk_register_id' => $riskRegisterId,
                'net_loss_amount' => max(0, $netLoss),
                'is_regulatory_reportable' => $isRegulatoryReportable,
                'status' => 'reported',
                'created_by' => auth()->id(),
                // Populate original NOT NULL columns from the primary migration
                'title' => $validated['event_title'],
                'description' => $validated['event_description'],
                'basel_l1_category' => $validated['basel_event_type'] ?? 'Other',
                'basel_l2_category' => $validated['basel_event_type'] ?? 'Other',
                'cbn_risk_category' => $validated['cbn_loss_category'] ?? 'Other',
                'loss_category' => $validated['event_type'] ?? 'actual_loss',
                'event_severity' => strtoupper($validated['severity'] ?? 'MODERATE'),
                'is_near_miss' => $isNearMiss,
                'current_status' => 'REPORTED',
                // Keep kobo columns in sync — the regulatory threshold engine
                // (CBN/NDIC/EFCC alerts) and AI data services read these.
                'gross_loss_amount_kobo' => (int) round($validated['gross_loss_amount'] * 100),
                'insurance_recovery_kobo' => (int) round(($validated['insurance_recovery'] ?? 0) * 100),
            ]));

            \App\Events\LossEventCreated::dispatch($lossEvent);

            // Evaluate regulatory thresholds
            $regulatoryService = new \App\Services\RegulatoryThresholdService();
            $alerts = $regulatoryService->evaluateThresholds($lossEvent);

            if (!empty($alerts)) {
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
        $orgId = auth()->user()->organization_id ?? 1;

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
        $orgId = auth()->user()->organization_id ?? 1;

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
        $orgId = auth()->user()->organization_id ?? 1;

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

        $netLoss = $validated['gross_loss_amount']
            - ($validated['recovery_amount'] ?? 0)
            - ($validated['insurance_recovery'] ?? 0);

        // Map form field risk_id to actual DB column risk_register_id
        $riskRegisterId = $validated['risk_id'] ?? null;
        unset($validated['risk_id']);

        $original = $lossEvent->getAttributes();

        $lossEvent->update(array_merge($validated, [
            'risk_register_id' => $riskRegisterId,
            'net_loss_amount' => max(0, $netLoss),
            'updated_by' => auth()->id(),
            // Keep original NOT NULL columns in sync
            'title' => $validated['event_title'],
            'description' => $validated['event_description'],
            'basel_l1_category' => $validated['basel_event_type'] ?? $lossEvent->basel_l1_category,
            'basel_l2_category' => $validated['basel_event_type'] ?? $lossEvent->basel_l2_category,
            'cbn_risk_category' => $validated['cbn_loss_category'] ?? $lossEvent->cbn_risk_category,
            'loss_category' => $validated['event_type'] ?? $lossEvent->loss_category,
            'event_severity' => strtoupper($validated['severity'] ?? $lossEvent->event_severity),
            'gross_loss_amount_kobo' => (int) round($validated['gross_loss_amount'] * 100),
            'insurance_recovery_kobo' => (int) round(($validated['insurance_recovery'] ?? 0) * 100),
        ]));

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
        $orgId = auth()->user()->organization_id ?? 1;

        if ($lossEvent->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this loss event.');
        }

        if (!in_array($lossEvent->status, ['reported', 'draft'])) {
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
        $orgId = auth()->user()->organization_id ?? 1;

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

        if (!isset($allowedTransitions[$currentStatus]) || !in_array($newStatus, $allowedTransitions[$currentStatus])) {
            return back()->with('error', "Cannot transition from '{$currentStatus}' to '{$newStatus}'.");
        }

        $original = $lossEvent->getAttributes();

        $lossEvent->update([
            'status' => $newStatus,
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
        $orgId = auth()->user()->organization_id ?? 1;

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
        $orgId = auth()->user()->organization_id ?? 1;

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
                'root_cause_description' => $validated['root_cause_description'],
                'root_cause_statement' => $validated['root_cause_description'], // NOT NULL column
                'contributing_factors_text' => $validated['contributing_factors'] ?? null,
                'analysis_details' => $validated['analysis_details'] ?? null,
                'recommendations' => $validated['recommendations'] ?? null,
                'lessons_learned' => $validated['lessons_learned'] ?? null,
                'status' => 'draft',
                'rca_status' => 'IN_PROGRESS',
                'performed_by' => auth()->id(),
                'analysis_date' => now(),
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
        $orgId = auth()->user()->organization_id ?? 1;

        if ($lossEvent->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this loss event.');
        }

        $validated = $request->validate([
            'file' => 'required|file|max:20480', // 20MB
            'document_type' => 'nullable|string|max:50',
            'is_regulatory' => 'nullable|boolean',
        ]);

        $file = $validated['file'];
        $storagePath = $file->store("loss-events/{$lossEvent->id}/attachments", 'local');

        LossEventAttachment::create([
            'loss_event_id' => $lossEvent->id,
            'file_name' => $file->getClientOriginalName(),
            'file_size_bytes' => $file->getSize(),
            'file_type' => $file->getClientMimeType(),
            'storage_path' => $storagePath,
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
        $orgId = auth()->user()->organization_id ?? 1;

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
        $orgId = auth()->user()->organization_id ?? 1;

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
        $orgId = auth()->user()->organization_id ?? 1;

        if ($lossEvent->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this loss event.');
        }

        $rca = LossEventRca::where('loss_event_id', $lossEvent->id)->firstOrFail();

        $original = $rca->getAttributes();

        $rca->update([
            'status' => 'approved',
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
        $orgId = auth()->user()->organization_id ?? 1;

        $rcaEvents = LossEvent::where('organization_id', $orgId)
            ->with('rca')
            ->orderByDesc('date_of_loss')
            ->paginate(25)
            ->withQueryString();

        $rcaBase = LossEventRca::where('organization_id', $orgId);
        $totalRcas = (clone $rcaBase)->count();
        $completedRcas = (clone $rcaBase)->whereIn('status', ['approved', 'completed'])->count();
        $inProgressRcas = (clone $rcaBase)->whereIn('status', ['draft', 'in_progress'])->count();
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
        $orgId = auth()->user()->organization_id ?? 1;

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
     * List near misses.
     */
    public function nearMisses(Request $request)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $query = NearMiss::where('organization_id', $orgId);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('event_reference', 'like', "%{$search}%")
                  ->orWhere('title', 'like', "%{$search}%");
            });
        }

        $nearMissEvents = $query->orderByDesc('date_occurred')->paginate(25)->withQueryString();

        $businessUnits = BusinessUnit::where('organization_id', $orgId)->orderBy('name')->get();

        $allNearMisses = NearMiss::where('organization_id', $orgId);
        $totalNearMisses = (clone $allNearMisses)->count();
        $openNearMisses = (clone $allNearMisses)->where('status', 'open')->count();
        $underReviewNearMisses = (clone $allNearMisses)->whereIn('status', ['investigating', 'under review'])->count();
        $potentialLossAvoided = ((clone $allNearMisses)->sum('potential_loss_kobo') ?? 0) / 100;

        return view('risk.loss-events.near-misses', compact(
            'nearMissEvents', 'businessUnits',
            'totalNearMisses', 'openNearMisses', 'underReviewNearMisses', 'potentialLossAvoided'
        ));
    }

    /**
     * Show form to create a near miss.
     */
    public function createNearMiss()
    {
        $orgId = auth()->user()->organization_id ?? 1;

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
        $orgId = auth()->user()->organization_id ?? 1;

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
        $orgId = auth()->user()->organization_id ?? 1;

        $query = LossEvent::where('organization_id', $orgId)
            ->where('status', 'pending_approval')
            ->with(['businessUnit', 'reporter']);

        if ($request->filled('severity')) {
            $query->where('severity', $request->severity);
        }

        $pendingApprovals = $query->orderByDesc('date_of_loss')->paginate(25);

        return view('risk.loss-events.approvals', compact('pendingApprovals'));
    }

    /**
     * Submit an approval decision for a loss event.
     */
    public function submitApproval(Request $request, LossEvent $lossEvent, \App\Services\ApprovalService $approvals)
    {
        abort_unless(auth()->user()->can('approve-loss-event', $lossEvent), 403,
            'Only an assigned handler, loss-event-manager, compliance-officer or CRO can decide on loss events.');

        $validated = $request->validate([
            'decision' => 'required|in:approved,rejected,escalated',
            'comments' => 'nullable|string|max:2000',
            'rejection_reason' => 'required_if:decision,rejected|nullable|string|max:2000',
            'approval_level' => 'nullable|in:level_1,level_2,level_3',
        ]);

        return DB::transaction(function () use ($validated, $lossEvent, $approvals) {
            $orgId = $lossEvent->organization_id;

            // Always record the decision in the audit history table.
            LossEventApproval::create([
                'loss_event_id' => $lossEvent->id,
                'stage' => $validated['approval_level'] ?? 'level_1',
                'action' => $validated['decision'],
                'decision' => $validated['decision'],
                'comments' => $validated['decision'] === 'rejected'
                    ? $validated['rejection_reason']
                    : ($validated['comments'] ?? null),
                'actioned_by' => auth()->id(),
                'actioned_at' => now(),
            ]);

            // Mirror the decision through the generic ApprovalService so
            // notifications + the approval_requests timeline stay consistent.
            $pending = $approvals->latestPending($lossEvent)
                ?? $approvals->requestApproval($lossEvent, 'approve_loss_event', reviewerId: $lossEvent->assigned_to_id ?? null);

            if ($validated['decision'] === 'approved') {
                $lossEvent->update([
                    'status' => 'approved',
                    'approved_by' => auth()->id(),
                    'approved_at' => now(),
                ]);
                $approvals->approve($pending, auth()->id(), $validated['comments'] ?? null);
            } elseif ($validated['decision'] === 'rejected') {
                $lossEvent->update(['status' => 'under_investigation']);
                $approvals->reject($pending, auth()->id(), $validated['rejection_reason']);
            } else {
                // escalated — leave approval pending so the next level can act.
                $lossEvent->update(['status' => 'escalated']);
            }

            $decisionLabel = ucfirst($validated['decision']);
            return back()->with('success', "Loss event {$lossEvent->event_reference}: {$decisionLabel}.");
        });
    }

    /**
     * Convert a near-miss to a loss event
     */
    public function convertNearMiss(NearMiss $nearMiss)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        if ($nearMiss->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this near-miss.');
        }

        // Create loss event from near-miss data
        $lossEvent = LossEvent::create([
            'organization_id' => $orgId,
            'event_reference' => \App\Services\ReferenceCodeService::generate('loss_events', 'event_reference', 'LE'),
            'title' => 'Converted: ' . $nearMiss->title,
            'description' => $nearMiss->description . "\n\n[Converted from Near-Miss: {$nearMiss->reference}]",
            'date_of_loss' => $nearMiss->date_occurred,
            'date_discovered' => $nearMiss->date_reported,
            'business_unit_id' => $nearMiss->business_unit_id,
            'gross_loss_amount_kobo' => $nearMiss->potential_loss_kobo ?? 0,
            'event_severity' => strtoupper($nearMiss->severity ?? 'MEDIUM'),
            'risk_register_id' => $nearMiss->risk_register_id,
            'current_status' => 'draft',
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
