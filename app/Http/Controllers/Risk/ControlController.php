<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\Control;
use App\Models\Risk;
use App\Models\RiskControlMapping;
use App\Models\BusinessUnit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ControlController extends Controller
{
    /**
     * Display the control library listing.
     */
    public function index(Request $request)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $query = Control::withCount('risks')->where('organization_id', $orgId);

        if ($request->filled('control_type')) {
            $query->where('control_type', $request->control_type);
        }

        if ($request->filled('control_nature')) {
            $query->where('control_nature', $request->control_nature);
        }

        if ($request->filled('effectiveness')) {
            $query->where('effectiveness_rating', $request->effectiveness);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('control_code', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $controls = $query->orderBy('control_code')->paginate(25)->withQueryString();

        return view('risk.controls.index', compact('controls'));
    }

    /**
     * Show the form for creating a new control.
     */
    public function create()
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $businessUnits = BusinessUnit::where('organization_id', $orgId)->orderBy('name')->get();
        $users = User::where('organization_id', $orgId)->orderBy('name')->get();
        $risks = Risk::where('organization_id', $orgId)->orderBy('risk_code')->get();

        return view('risk.controls.create', compact('businessUnits', 'users', 'risks'));
    }

    /**
     * Store a newly created control.
     */
    public function store(Request $request)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        $validated = $request->validate([
            'name' => 'required|string|max:200',
            'description' => 'required|string|max:5000',
            'control_type' => 'required|in:preventive,detective,corrective,directive',
            'control_nature' => 'nullable|in:manual,automated,semi_automated',
            'frequency' => 'nullable|in:continuous,daily,weekly,monthly,quarterly,annually,ad_hoc',
            'owner_id' => 'required|exists:users,id',
            'business_unit_id' => 'nullable|exists:business_units,id',
            'effectiveness_rating' => 'nullable|in:effective,partially_effective,ineffective',
            'status' => 'nullable|in:active,inactive,under_review',
            'risk_ids' => 'nullable|array',
            'risk_ids.*' => 'exists:risks,id',
        ]);

        $riskIds = $validated['risk_ids'] ?? [];
        unset($validated['risk_ids']);

        // Auto-generate control code: CTL-NNNN
        $lastControl = Control::where('organization_id', $orgId)
            ->orderByDesc('id')
            ->first();

        $nextNumber = $lastControl ? $lastControl->id + 1 : 1;
        $controlCode = sprintf('CTL-%04d', $nextNumber);

        $control = Control::create(array_merge($validated, [
            'organization_id' => $orgId,
            'control_code' => $controlCode,
            'status' => $validated['status'] ?? 'active',
            'created_by' => auth()->id(),
        ]));

        // Link to risks if any selected
        foreach ($riskIds as $riskId) {
            RiskControlMapping::create([
                'risk_id' => $riskId,
                'control_id' => $control->id,
                'organization_id' => $orgId,
                'mapping_rationale' => 'Linked during control creation',
                'created_by' => auth()->id(),
            ]);
        }

        // Audit trail
        \App\Services\AuditTrailService::record($control, 'create');

        return redirect()->route('risk.controls.show', $control)
            ->with('success', "Control {$controlCode} has been created.");
    }

    /**
     * Display the specified control.
     */
    public function show(Control $control)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        if ($control->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this control.');
        }

        $control->load(['controlOwner', 'businessUnit', 'riskMappings']);

        return view('risk.controls.show', compact('control'));
    }

    /**
     * Show the form for editing the specified control.
     */
    public function edit(Control $control)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        if ($control->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this control.');
        }

        $businessUnits = BusinessUnit::where('organization_id', $orgId)->orderBy('name')->get();
        $users = User::where('organization_id', $orgId)->orderBy('name')->get();

        return view('risk.controls.edit', compact('control', 'businessUnits', 'users'));
    }

    /**
     * Update the specified control.
     */
    public function update(Request $request, Control $control)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        if ($control->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this control.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:200',
            'description' => 'required|string|max:5000',
            'control_type' => 'required|in:preventive,detective,corrective,directive',
            'control_nature' => 'nullable|in:manual,automated,semi_automated',
            'frequency' => 'nullable|in:continuous,daily,weekly,monthly,quarterly,annually,ad_hoc',
            'owner_id' => 'required|exists:users,id',
            'business_unit_id' => 'nullable|exists:business_units,id',
            'effectiveness_rating' => 'nullable|in:effective,partially_effective,ineffective',
            'status' => 'nullable|in:active,inactive,under_review',
        ]);

        $original = $control->getAttributes();

        $control->update(array_merge($validated, [
            'updated_by' => auth()->id(),
        ]));

        // Recalculate residual risk scores for all linked risks
        $effectivenessService = new \App\Services\ControlEffectivenessService();
        foreach ($control->risks as $risk) {
            $effectivenessService->recalculateForRisk($risk);
        }

        // Audit trail
        \App\Services\AuditTrailService::recordChanges($control, $original);

        return redirect()->route('risk.controls.show', $control)
            ->with('success', "Control {$control->control_code} has been updated.");
    }

    /**
     * Delete the specified control.
     */
    public function destroy(Control $control)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        if ($control->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this control.');
        }

        // Check if control is linked to any risks
        $linkedRisks = RiskControlMapping::where('control_id', $control->id)->count();
        if ($linkedRisks > 0) {
            return back()->with('error', "Cannot delete control {$control->control_code}: it is linked to {$linkedRisks} risk(s). Please unlink first.");
        }

        $code = $control->control_code;

        // Audit trail
        \App\Services\AuditTrailService::record($control, 'delete');

        $control->delete();

        return redirect()->route('risk.controls.index')
            ->with('success', "Control {$code} has been deleted.");
    }

    /**
     * Link a control to a risk.
     */
    public function linkToRisk(Request $request, Control $control)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        if ($control->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this control.');
        }

        $validated = $request->validate([
            'risk_id' => 'required|exists:risks,id',
            'weight' => 'nullable|numeric|min:0|max:100',
            'rationale' => 'nullable|string|max:1000',
            'mapping_status' => 'nullable|in:active,inactive,under_review',
        ]);

        // Verify risk belongs to org
        $risk = Risk::where('id', $validated['risk_id'])
            ->where('organization_id', $orgId)
            ->firstOrFail();

        // Check for existing mapping
        $existing = RiskControlMapping::where('risk_id', $risk->id)
            ->where('control_id', $control->id)
            ->first();

        if ($existing) {
            return back()->with('error', "Control {$control->control_code} is already linked to risk {$risk->risk_code}.");
        }

        RiskControlMapping::create([
            'risk_id' => $risk->id,
            'control_id' => $control->id,
            'organization_id' => $orgId,
            'weight' => $validated['weight'] ?? 0,
            'rationale' => $validated['rationale'] ?? null,
            'mapping_status' => $validated['mapping_status'] ?? 'active',
            'linked_by' => auth()->id(),
        ]);

        // Recalculate residual risk score
        $effectivenessService = new \App\Services\ControlEffectivenessService();
        $effectivenessService->recalculateForRisk($risk);

        return back()->with('success', "Control {$control->control_code} linked to risk {$risk->risk_code}.");
    }

    /**
     * Unlink a control from a risk.
     */
    public function unlinkFromRisk(Control $control, Risk $risk)
    {
        $orgId = auth()->user()->organization_id ?? 1;

        if ($control->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this control.');
        }

        $mapping = RiskControlMapping::where('risk_id', $risk->id)
            ->where('control_id', $control->id)
            ->firstOrFail();

        $mapping->delete();

        // Recalculate residual risk score
        $effectivenessService = new \App\Services\ControlEffectivenessService();
        $effectivenessService->recalculateForRisk($risk);

        return back()->with('success', "Control {$control->control_code} unlinked from risk {$risk->risk_code}.");
    }
}
