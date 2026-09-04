<?php

namespace App\Http\Requests\Register;

use App\Http\Requests\Concerns\ValidatesConfiguredAttributes;
use App\Models\Risk;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT/PATCH risk/register/{register} (migration Phase 3.2).
 *
 * Differs from StoreRiskRequest exactly as the two inline rule sets did:
 * status is required here (a saved risk always has one), risk_type is not
 * editable, and appetite_category is accepted. Foreign keys are tenant-scoped
 * for the reason given on the store request.
 */
class UpdateRiskRequest extends FormRequest
{
    use ValidatesConfiguredAttributes;

    public function authorize(): bool
    {
        $risk = $this->route('register');

        return $risk instanceof Risk && ($this->user()?->can('update', $risk) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $orgId = TenantContext::organizationId();

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'category_id' => ['required', Rule::exists('risk_categories', 'id')->where('organization_id', $orgId)],
            'business_unit_id' => ['required', Rule::exists('business_units', 'id')->where('organization_id', $orgId)],
            'process_id' => ['nullable', Rule::exists('business_processes', 'id')->where('organization_id', $orgId)],
            'risk_owner_id' => ['required', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'risk_steward_id' => ['nullable', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'identified_by' => ['nullable', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'date_identified' => ['nullable', 'date'],
            'risk_source' => ['nullable', 'string', 'max:100'],
            'status' => ['required', Rule::in(['active', 'dormant', 'closed', 'retired'])],
            'inherent_likelihood' => ['required', 'integer', 'min:1', 'max:5'],
            'impact_financial' => ['required', 'integer', 'min:1', 'max:5'],
            'impact_operational' => ['required', 'integer', 'min:1', 'max:5'],
            'impact_reputational' => ['required', 'integer', 'min:1', 'max:5'],
            'impact_regulatory' => ['required', 'integer', 'min:1', 'max:5'],
            'treatment_strategy' => ['nullable', Rule::in(['mitigate', 'accept', 'transfer', 'avoid'])],
            'risk_velocity' => ['nullable', 'string', 'max:50'],
            'review_frequency' => ['nullable', 'string', 'max:50'],
            'financial_exposure' => ['nullable', 'numeric', 'min:0'],
            'regulatory_tags' => ['nullable', 'array'],
            'regulatory_tags.*' => ['string', 'max:100'],
            'appetite_category' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:5000'],
            ...$this->configuredAttributeRules('Risk'),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return $this->configuredAttributeLabels('Risk');
    }
}
