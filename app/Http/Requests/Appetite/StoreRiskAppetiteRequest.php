<?php

namespace App\Http\Requests\Appetite;

use App\Models\RiskAppetite;
use App\Services\Appetite\AppetiteFrameworkService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

class StoreRiskAppetiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', RiskAppetite::class);
    }

    /** unit_of_measure is NOT NULL in the schema; the old form defaulted it. */
    protected function prepareForValidation(): void
    {
        if ($this->input('unit_of_measure') === null || $this->input('unit_of_measure') === '') {
            $this->merge(['unit_of_measure' => 'percentage']);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $organizationId = TenantContext::organizationId();

        return [
            'risk_category_id' => [
                'required',
                'integer',
                Rule::exists('risk_categories', 'id')->where('organization_id', $organizationId),
                // One statement per category: the second is an update, not a duplicate.
                Rule::unique('risk_appetite', 'risk_category_id')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at'),
            ],
            ...self::statementRules(),
        ];
    }

    /** @return array<string, mixed> */
    public function messages(): array
    {
        return [
            'risk_category_id.unique' => 'A risk appetite statement already exists for this category. Please update it instead.',
        ];
    }

    /**
     * The rules the store and update requests share.
     *
     * Capacity — the maximum exposure the organisation could absorb, as
     * distinct from the tolerance it is willing to run — stays optional: an
     * unrecorded capacity means no capacity boundary is reported.
     *
     * @return array<string, mixed>
     */
    public static function statementRules(): array
    {
        return [
            'appetite_level' => ['required', Rule::in(AppetiteFrameworkService::LEVELS)],
            'appetite_statement' => ['required', 'string', 'max:2000'],
            'tolerance_metric' => ['required', 'string', 'max:255'],
            'unit_of_measure' => ['nullable', 'string', 'max:100'],
            'target_min' => ['required', 'numeric', 'min:0'],
            'target_max' => ['required', 'numeric', 'gte:target_min'],
            'max_tolerance' => ['required', 'numeric', 'gte:target_max'],
            'capacity' => ['nullable', 'numeric', 'gte:max_tolerance'],
            'appetite_type' => ['nullable', Rule::in(['quantitative', 'qualitative', 'hybrid'])],
            'current_position' => ['nullable', 'numeric', 'min:0'],
            'effective_date' => ['required', 'date'],
            'expiry_date' => ['nullable', 'date', 'after:effective_date'],
        ];
    }
}
