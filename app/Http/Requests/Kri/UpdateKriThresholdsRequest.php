<?php

namespace App\Http\Requests\Kri;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Bulk edit of the traffic-light bands (migration Phase 4.1).
 *
 * THIS REQUEST EXISTS BECAUSE THE SCREEN IT VALIDATES SAVED NOTHING. The Blade
 * form posted `kris[{id}][green_threshold]` and
 * KriController::updateThresholds() read `$request->input('thresholds', [])`,
 * expecting keys `green_min` / `green_max` / `amber_min` / … — a shape nothing
 * in the codebase produced. The loop ran zero times, the method redirected with
 * "Thresholds updated.", and every bulk edit of a bank's risk tolerances was
 * silently discarded. A test now pins the columns that change.
 *
 * The payload is the two boundaries a band set actually needs, per KRI:
 * `green` and `red`. Which columns they land in depends on the indicator's own
 * direction, which is why the service reads it per row rather than taking a
 * direction from the form.
 */
class UpdateKriThresholdsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageThresholds', \App\Models\KeyRiskIndicator::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $orgId = TenantContext::organizationId();

        return [
            'kris' => ['required', 'array', 'min:1'],
            // Tenant-bound on the KEY, so an id from another organisation is
            // rejected rather than quietly skipped by a where() that matches
            // nothing.
            'kris.*.id' => ['required', 'integer', Rule::exists('key_risk_indicators', 'id')->where('organization_id', $orgId)],
            'kris.*.green_threshold' => ['nullable', 'numeric'],
            'kris.*.red_threshold' => ['nullable', 'numeric'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'kris.*.id.exists' => 'One of these indicators does not belong to your organisation.',
        ];
    }
}
