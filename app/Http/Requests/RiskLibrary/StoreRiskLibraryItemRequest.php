<?php

namespace App\Http\Requests\RiskLibrary;

use App\Enums\BaselEventTypeL1;
use App\Enums\BaselEventTypeL2;
use App\Models\RiskLibraryItem;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

class StoreRiskLibraryItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', RiskLibraryItem::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $orgId = TenantContext::organizationId();
        $riskSourceValues = array_column(RiskLibraryItem::RISK_SOURCES, 'value');

        return [
            'code' => [
                'nullable',
                'string',
                'max:32',
                Rule::unique('risk_library_items', 'code')->where('organization_id', $orgId),
            ],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
            'category_id' => [
                'required',
                'integer',
                Rule::exists('risk_categories', 'id')->where('organization_id', $orgId),
            ],
            'risk_source' => ['nullable', Rule::in($riskSourceValues)],
            'basel_l1_event_type' => ['nullable', Rule::in(BaselEventTypeL1::values())],
            'basel_l2_event_type' => [
                'nullable',
                Rule::in(BaselEventTypeL2::values()),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }
                    $l1 = $this->input('basel_l1_event_type');
                    $enum = BaselEventTypeL2::tryFrom((string) $value);
                    if ($enum === null || $l1 === null || $enum->l1()->value !== $l1) {
                        $fail('The selected Basel Level 2 event type must belong to the chosen Level 1 event type.');
                    }
                },
            ],
            'typical_causes' => ['nullable', 'array'],
            'typical_causes.*.cause' => ['required', 'string', 'max:500'],
            'typical_causes.*.cause_category_id' => [
                'nullable',
                'integer',
                Rule::exists('risk_cause_categories', 'id')->where(function ($q) use ($orgId) {
                    $q->whereNull('organization_id')->orWhere('organization_id', $orgId);
                }),
            ],
            'typical_consequences' => ['nullable', 'array'],
            'typical_consequences.*.consequence' => ['required', 'string', 'max:500'],
            'typical_consequences.*.impact_domain' => ['nullable', 'string', 'max:64'],
            'suggested_controls' => ['nullable', 'array'],
            'suggested_controls.*.title' => ['required', 'string', 'max:255'],
            'suggested_controls.*.control_id' => [
                'nullable',
                'integer',
                Rule::exists('controls', 'id')->where('organization_id', $orgId),
            ],
            'suggested_controls.*.control_type' => ['nullable', Rule::in(RiskLibraryItem::CONTROL_TYPES)],
            'suggested_kris' => ['nullable', 'array'],
            'suggested_kris.*.name' => ['required', 'string', 'max:255'],
            'suggested_kris.*.kri_id' => [
                'nullable',
                'integer',
                Rule::exists('key_risk_indicators', 'id')->where('organization_id', $orgId),
            ],
            'suggested_kris.*.metric_unit' => ['nullable', 'string', 'max:64'],
        ];
    }
}
