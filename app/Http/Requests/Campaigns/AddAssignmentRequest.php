<?php

namespace App\Http\Requests\Campaigns;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Give a business unit's respondent a piece of the campaign (Phase 4.5).
 *
 * All three foreign keys were the string `exists:` form, which accepts any id in
 * the table — so this bank's campaign could be assigned to another bank's
 * business unit, answered by another bank's user, and reviewed by a third. A
 * probe against HEAD confirmed all three land. Every one is now tenant-bound.
 *
 * `due_date` is `date()` NOT NULL on campaign_assignments, and required here.
 */
class AddAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', $this->route('campaign'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $orgId = TenantContext::organizationId();

        return [
            'business_unit_id' => ['required', Rule::exists('business_units', 'id')->where('organization_id', $orgId)],
            'respondent_id' => ['required', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'reviewer_id' => ['nullable', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'due_date' => ['required', 'date'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'business_unit_id.exists' => 'Select a business unit belonging to your organisation.',
            'respondent_id.exists' => 'Select a respondent from your organisation.',
            'reviewer_id.exists' => 'Select a reviewer from your organisation.',
        ];
    }
}
