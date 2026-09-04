<?php

namespace App\Http\Requests\LossEvents;

use App\Models\NearMiss;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Report a near miss (migration Phase 4.3).
 *
 * Four foreign keys, all of which were the untenanted string `exists:` form.
 */
class StoreNearMissRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', NearMiss::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $orgId = TenantContext::organizationId();

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'date_occurred' => ['required', 'date'],
            'business_unit_id' => ['required', Rule::exists('business_units', 'id')->where('organization_id', $orgId)],
            'risk_register_id' => ['nullable', Rule::exists('risks', 'id')->where('organization_id', $orgId)],
            'severity' => ['required', Rule::in(NearMiss::SEVERITIES)],
            'potential_loss_amount' => ['nullable', 'numeric', 'min:0'],
            'control_gap_identified' => ['nullable', 'boolean'],
            'control_gap_description' => ['nullable', 'string', 'max:2000'],
            'linked_control_id' => ['nullable', Rule::exists('controls', 'id')->where('organization_id', $orgId)],
            'reported_by' => ['required', Rule::exists('users', 'id')->where('organization_id', $orgId)],
        ];
    }
}
