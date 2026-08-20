<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Concerns\EnforcesNodeScope;
use App\Http\Controllers\Concerns\PersistsConfiguredAttributes;
use App\Http\Controllers\Controller;
use App\Models\BusinessUnit;
use App\Models\Control;
use App\Models\Risk;
use App\Models\RiskControlMapping;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;

class ControlController extends Controller
{
    // WP-00 node scoping. Route-model binding resolves a record through the
    // tenancy scope only, so every method that receives a bound model asks
    // EnforcesNodeScope whether the caller's subtree admits it — and gets a 404
    // rather than a 403 when it does not, so the record's existence is not
    // itself the answer.
    use EnforcesNodeScope;

    // WP-05 TASK 2 — receives the fields a tenant added through the
    // builder. Without it, a configured field would render on the form,
    // accept what was typed, and discard it on submit.
    use PersistsConfiguredAttributes;

    /**
     * Display the control library listing. Search, filters, sorting and
     * pagination all moved into the shared data grid (WP-09) — see
     * App\Grids\Definitions\ControlsGrid.
     */
    public function index(Request $request)
    {
        // WP-00: scoped like ControlsGrid, so the header total counts the
        // rows the grid beneath it will actually show.
        $total = Control::where('organization_id', TenantContext::organizationId())
            ->visibleTo()
            ->count();

        return view('risk.controls.index', compact('total'));
    }

    /**
     * Show the form for creating a new control.
     */
    public function create()
    {
        $orgId = TenantContext::organizationId();

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
        $orgId = TenantContext::organizationId();

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

        // Fields the tenant added through the builder, if any.
        $this->saveConfiguredAttributes($request, $control);

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
        $orgId = TenantContext::organizationId();

        if ($control->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this control.');
        }

        // WP-00 node scoping: 404, not 403 — see EnforcesNodeScope.
        $this->abortUnlessNodeVisible($control);

        $control->load(['controlOwner', 'businessUnit', 'riskMappings']);

        return view('risk.controls.show', compact('control'));
    }

    /**
     * Show the form for editing the specified control.
     */
    public function edit(Control $control)
    {
        $orgId = TenantContext::organizationId();

        if ($control->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this control.');
        }

        // WP-00 node scoping: 404, not 403 — see EnforcesNodeScope.
        $this->abortUnlessNodeVisible($control);

        $businessUnits = BusinessUnit::where('organization_id', $orgId)->orderBy('name')->get();
        $users = User::where('organization_id', $orgId)->orderBy('name')->get();

        return view('risk.controls.edit', compact('control', 'businessUnits', 'users'));
    }

    /**
     * Update the specified control.
     */
    public function update(Request $request, Control $control)
    {
        $orgId = TenantContext::organizationId();

        if ($control->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this control.');
        }

        // WP-00 node scoping: 404, not 403 — see EnforcesNodeScope.
        $this->abortUnlessNodeVisible($control);

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
        $effectivenessService = new \App\Services\ControlEffectivenessService;
        foreach ($control->risks as $risk) {
            $effectivenessService->recalculateForRisk($risk);
        }

        // Audit trail
        \App\Services\AuditTrailService::recordChanges($control, $original);

        $changedFields = array_keys(array_diff_assoc($control->getAttributes(), $original));
        \App\Events\ControlUpdated::dispatch($control, $changedFields);

        $this->saveConfiguredAttributes($request, $control);

        return redirect()->route('risk.controls.show', $control)
            ->with('success', "Control {$control->control_code} has been updated.");
    }

    /**
     * Delete the specified control.
     */
    public function destroy(Control $control)
    {
        $orgId = TenantContext::organizationId();

        if ($control->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this control.');
        }

        // WP-00 node scoping: 404, not 403 — see EnforcesNodeScope.
        $this->abortUnlessNodeVisible($control);

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
        $orgId = TenantContext::organizationId();

        if ($control->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this control.');
        }

        // WP-00 node scoping: 404, not 403 — see EnforcesNodeScope.
        $this->abortUnlessNodeVisible($control);

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
        $effectivenessService = new \App\Services\ControlEffectivenessService;
        $effectivenessService->recalculateForRisk($risk);

        return back()->with('success', "Control {$control->control_code} linked to risk {$risk->risk_code}.");
    }

    /**
     * Unlink a control from a risk.
     */
    public function unlinkFromRisk(Control $control, Risk $risk)
    {
        $orgId = TenantContext::organizationId();

        if ($control->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this control.');
        }

        // WP-00 node scoping: 404, not 403 — see EnforcesNodeScope.
        $this->abortUnlessNodeVisible($control);

        $mapping = RiskControlMapping::where('risk_id', $risk->id)
            ->where('control_id', $control->id)
            ->firstOrFail();

        $mapping->delete();

        // Recalculate residual risk score
        $effectivenessService = new \App\Services\ControlEffectivenessService;
        $effectivenessService->recalculateForRisk($risk);

        return back()->with('success', "Control {$control->control_code} unlinked from risk {$risk->risk_code}.");
    }
}
