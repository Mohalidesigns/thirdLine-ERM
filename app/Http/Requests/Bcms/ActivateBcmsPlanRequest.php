<?php

namespace App\Http\Requests\Bcms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Activating a plan — from the plan library, or from the crisis room with an
 * incident attached (Gate 2 review #1 defect 13).
 *
 * `incident_id` IS VALIDATED AS A SHAPE, NOT A TENANT-BOUND `Rule::exists()`.
 * An incident is visible only to its business unit (`ScopedToOrgHierarchy`),
 * not merely its tenant, so a bare `Rule::exists('bcms_incidents',
 * 'uuid')->where('organization_id', ...)` would let an officer in one unit
 * attach another unit's incident to a plan activation — an existence oracle
 * across the unit boundary, the same defect family a bare `exists:` is
 * flagged for across a tenant one. `PlanDocumentController::activate()`
 * resolves the uuid through `Incident::visibleTo($request->user())` and 404s
 * if it does not resolve.
 */
class ActivateBcmsPlanRequest extends FormRequest
{
    /**
     * `bcms.plan.activate` always; ADDITIONALLY `bcms.incident.manage`
     * whenever `incident_id` is present (Gate 1 re-gate defect 9, A13) — an
     * activation attributed to a specific incident is an incident-management
     * act reached from the crisis room, not only a plan-library one, and a
     * plan-activate holder with no standing in that incident should not be
     * able to attribute an activation to it.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null || ! $user->can('bcms.plan.activate')) {
            return false;
        }

        if ($this->filled('incident_id') && ! $user->can('bcms.incident.manage')) {
            return false;
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
            'is_exercise' => ['nullable', 'boolean'],
            'incident_id' => ['nullable', 'string', 'uuid'],
        ];
    }
}
