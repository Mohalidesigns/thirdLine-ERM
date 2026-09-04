<?php

namespace App\Http\Requests\Register;

use App\Http\Requests\Concerns\ValidatesConfiguredAttributes;
use App\Models\Risk;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST risk/register — the rules RiskRegisterController@store used to hold
 * inline (migration Phase 3.2).
 *
 * Every foreign key is checked INSIDE THE TENANT. A bare `exists:users,id`
 * accepts any user in the database, so a caller could make a user from
 * another bank the owner of one of their risks — and that user's name would
 * then render on the page. The tenant-scoped rule is the difference.
 */
class StoreRiskRequest extends FormRequest
{
    use ValidatesConfiguredAttributes;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Risk::class) ?? false;
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
            'risk_type' => ['nullable', Rule::in(['strategic', 'operational', 'financial', 'compliance', 'technology', 'reputational'])],
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
            'notes' => ['nullable', 'string', 'max:5000'],
            'status' => ['nullable', Rule::in(['active', 'dormant', 'closed', 'retired'])],
            ...$this->configuredAttributeRules('Risk'),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return $this->configuredAttributeLabels('Risk');
    }
}
