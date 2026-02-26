<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\LossEvent;
use App\Models\LossEventApproval;
use App\Models\LossEventRca;
use App\Models\LossEventControl;
use App\Models\NearMiss;
use App\Models\Risk;
use App\Models\BusinessUnit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LossEventController extends Controller
{
    /**
     * Loss event dashboard with statistics.
     */
    public function dashboard()
    {
        $orgId = auth()->user()->organization_id ?? 1;
        $currentYear = now()->year;

        $stats = [
            'total_ytd' => LossEvent::where('organization_id', $orgId)->whereYear('date_of_loss', $currentYear)->count(),
            'total_loss_amount_ytd' => LossEvent::where('organization_id', $orgId)->whereYear('date_of_loss', $currentYear)->sum('gross_loss_amount'),
            'recovered_amount_ytd' => LossEvent::where('organization_id', $orgId)->whereYear('date_of_loss', $currentYear)->sum('recovery_amount'),
            'net_loss_ytd' => LossEvent::where('organization_id', $orgId)->whereYear('date_of_loss', $currentYear)->sum('net_loss_amount'),
            'pending_approval' => LossEvent::where('organization_id', $orgId)->where('status', 'pending_approval')->count(),
            'open_events' => LossEvent::where('organization_id', $orgId)->whereIn('status', ['reported', 'under_investigation'])->count(),
            'near_misses_ytd' => NearMiss::where('organization_id', $orgId)->whereYear('date_occurred', $currentYear)->count(),
            'regulatory_reportable' => LossEvent::where('organization_id', $orgId)->where('is_regulatory_reportable', true)->whereYear('date_of_loss', $currentYear)->count(),
        ];

        // Monthly loss trend for current year
        $monthlyTrend = LossEvent::where('organization_id', $orgId)
            ->whereYear('date_of_loss', $currentYear)
            ->selectRaw('MONTH(date_of_loss) as month, COUNT(*) as count, SUM(gross_loss_amount) as total_loss')
            ->groupByRaw('MONTH(date_of_loss)')
            ->orderBy('month')
            ->get();

        // By Basel category
        $byBaselCategory = LossEvent::where('organization_id', $orgId)
            ->whereYear('date_of_loss', $currentYear)
            ->selectRaw('basel_event_type, COUNT(*) as count, SUM(gross_loss_amount) as total_loss')
            ->groupBy('basel_event_type')
            ->orderByDesc('total_loss')
            ->get();

        // Recent events
        $recentEvents = LossEvent::where('organization_id', $orgId)
            ->orderByDesc('date_of_loss')
            ->limit(10)
            ->get();

        return view('risk.loss-events.dashboard', compact('stats', 'monthlyTrend', 'byBaselCategory', 'recentEvents'));
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

        return view('risk.loss-events.index', compact('lossEvents', 'businessUnits'));
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
                'current_status' => 'REPORTED',
            ]));

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
        ]));

        // Audit trail
        \App\Services\AuditTrailService::recordChanges($lossEvent, $original);

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

        $lossEvent->load(['rca.remediationActions', 'risk']);

        return view('risk.loss-events.rca', compact('lossEvent'));
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
            [
                'loss_event_id' => $lossEvent->id,
                'organization_id' => $orgId,
            ],
            array_merge($validated, [
                'status' => 'draft',
                'performed_by' => auth()->id(),
                'analysis_date' => now(),
            ])
        );

        // Audit trail
        \App\Services\AuditTrailService::record($rca, 'create');

        return redirect()->route('risk.loss-events.show-rca', $lossEvent)
            ->with('success', 'Root Cause Analysis has been saved.');
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

        $rcas = LossEventRca::where('organization_id', $orgId)
            ->with(['lossEvent'])
            ->orderByDesc('analysis_date')
            ->paginate(25);

        return view('risk.loss-events.rca-index', compact('rcas'));
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

        return view('risk.loss-events.reports', compact('lossEvents'));
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

        $nearMisses = $query->orderByDesc('date_occurred')->paginate(25)->withQueryString();

        $businessUnits = BusinessUnit::where('organization_id', $orgId)->orderBy('name')->get();

        return view('risk.loss-events.near-misses', compact('nearMisses', 'businessUnits'));
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

        return view('risk.loss-events.create-near-miss', compact('risks', 'businessUnits', 'users'));
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
            'risk_id' => 'nullable|exists:risks,id',
            'potential_impact' => 'required|in:insignificant,minor,moderate,major,catastrophic',
            'potential_loss_amount' => 'nullable|numeric|min:0',
            'how_detected' => 'nullable|string|max:1000',
            'preventive_action' => 'nullable|string|max:2000',
            'reported_by' => 'required|exists:users,id',
        ]);

        // Auto-generate near miss reference: NM-YYYY-NNNN
        $year = now()->year;
        $lastNm = NearMiss::where('organization_id', $orgId)
            ->where('event_reference', 'like', "NM-{$year}-%")
            ->orderByDesc('event_reference')
            ->first();

        $nextNumber = $lastNm ? ((int) substr($lastNm->event_reference, -4)) + 1 : 1;
        $eventReference = sprintf('NM-%d-%04d', $year, $nextNumber);

        $nearMiss = NearMiss::create(array_merge($validated, [
            'organization_id' => $orgId,
            'event_reference' => $eventReference,
            'status' => 'reported',
            'created_by' => auth()->id(),
        ]));

        return redirect()->route('risk.loss-events.near-misses')
            ->with('success', "Near miss {$eventReference} has been reported.");
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
    public function submitApproval(Request $request, LossEvent $lossEvent)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        if ($lossEvent->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this loss event.');
        }

        $validated = $request->validate([
            'decision' => 'required|in:approved,rejected,escalated',
            'comments' => 'nullable|string|max:2000',
            'approval_level' => 'nullable|in:level_1,level_2,level_3',
        ]);

        return DB::transaction(function () use ($validated, $lossEvent, $orgId) {
            LossEventApproval::create([
                'loss_event_id' => $lossEvent->id,
                'organization_id' => $orgId,
                'approver_id' => auth()->id(),
                'decision' => $validated['decision'],
                'comments' => $validated['comments'] ?? null,
                'approval_level' => $validated['approval_level'] ?? 'level_1',
                'decided_at' => now(),
            ]);

            if ($validated['decision'] === 'approved') {
                $lossEvent->update([
                    'status' => 'approved',
                    'approved_by' => auth()->id(),
                    'approved_at' => now(),
                ]);
            } elseif ($validated['decision'] === 'rejected') {
                $lossEvent->update([
                    'status' => 'under_investigation',
                ]);
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
            'description' => $nearMiss->description . "\n\n[Converted from Near-Miss: {$nearMiss->event_code}]",
            'date_of_loss' => $nearMiss->date_occurred,
            'date_discovered' => $nearMiss->date_reported,
            'business_unit_id' => $nearMiss->business_unit_id,
            'gross_loss_amount_kobo' => $nearMiss->potential_loss_kobo ?? 0,
            'event_severity' => $nearMiss->potential_impact,
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
        \App\Services\AuditTrailService::record($lossEvent, 'create', null, null, null, "Converted from near-miss: {$nearMiss->event_code}");

        return redirect()->route('risk.loss-events.edit', $lossEvent)
            ->with('success', 'Near-miss converted to loss event successfully. Please complete the remaining fields.');
    }
}
