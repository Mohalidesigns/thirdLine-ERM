<?php

namespace App\Http\Requests\Register;

use App\Enums\BaselEventTypeL1;
use App\Enums\BaselEventTypeL2;
use App\Http\Requests\Concerns\ValidatesConfiguredAttributes;
use App\Models\Risk;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

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
            'entity_id' => ['nullable', Rule::exists('entities', 'id')->where('organization_id', $orgId)],
            'business_unit_id' => ['nullable', 'required_without:entity_id', Rule::exists('business_units', 'id')->where('organization_id', $orgId)],
            'process_id' => ['nullable', Rule::exists('business_processes', 'id')->where('organization_id', $orgId)],
            'risk_owner_id' => ['required', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'risk_steward_id' => ['nullable', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'identified_by' => ['nullable', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'date_identified' => ['nullable', 'date'],
            'risk_source' => ['nullable', 'string', 'max:100'],
            'basel_l1_event_type' => ['nullable', Rule::in(BaselEventTypeL1::values())],
            'basel_l2_event_type' => [
                'nullable',
                Rule::in(BaselEventTypeL2::values()),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }
                    $l1 = $this->input('basel_l1_event_type');
                    $enum = BaselEventTypeL2::tryFrom((string) $value);
                    if ($enum === null || $l1 === null || $enum->l1()->value !== $l1) {
                        $fail('The selected Basel Level 2 event type does not belong to the chosen Level 1 event type.');
                    }
                },
            ],
            'consequences' => ['nullable', 'array'],
            'consequences.*.consequence' => ['required_with:consequences', 'string', 'max:500'],
            'consequences.*.impact_domain' => ['nullable', 'string', 'max:64'],
            'status' => ['required', Rule::in(['active', 'dormant', 'closed', 'retired'])],
            'inherent_likelihood' => ['required', 'integer', 'min:1', 'max:5'],
            'impact_financial' => ['required', 'integer', 'min:1', 'max:5'],
            'impact_operational' => ['required', 'integer', 'min:1', 'max:5'],
            'impact_reputational' => ['required', 'integer', 'min:1', 'max:5'],
            'impact_regulatory' => ['required', 'integer', 'min:1', 'max:5'],
            'treatment_strategy' => ['nullable', Rule::in(['mitigate', 'accept', 'transfer', 'avoid'])],
            'risk_velocity' => ['nullable', 'string', 'max:50'],
            'review_frequency' => ['nullable', Rule::in(Risk::REVIEW_FREQUENCIES)],
            'financial_exposure' => ['nullable', 'numeric', 'min:0'],
            'regulatory_tags' => ['nullable', 'array'],
            'regulatory_tags.*' => ['string', 'max:100'],
            'appetite_category' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:5000'],
            ...$this->configuredAttributeRules('Risk', $this->route('register')),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return $this->configuredAttributeLabels('Risk');
    }
}
