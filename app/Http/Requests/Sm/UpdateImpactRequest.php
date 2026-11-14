<?php

namespace App\Http\Requests\Sm;

use App\Models\Sm\ImpactCriterion;
use App\Models\Sm\ImpactType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Impact decomposition update request — `PUT {scenario}/impact` (spec 08, ADR 0027 Amendment 2 §A2.4).
 */
class UpdateImpactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('scenario.design') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'types' => ['nullable', 'array'],
            'types.*.name' => ['required', 'string', 'max:250'],
            'types.*.aggregation_template' => ['required', 'string', Rule::in(array_keys(ImpactType::TEMPLATES))],
            'types.*.eligible_to_transfer' => ['required', 'boolean'],
            'types.*.criteria' => ['required', 'array', 'min:1'],
            'types.*.criteria.*.label' => ['required', 'string', 'max:250'],
            'types.*.criteria.*.unit' => ['nullable', 'string', 'max:50'],
            'types.*.criteria.*.computation_role' => ['required', 'string', Rule::in(array_keys(ImpactCriterion::COMPUTATION_ROLES))],
            'types.*.criteria.*.data_source' => ['nullable', 'string', 'max:100'],
            'types.*.criteria.*.typical_value' => ['required', 'numeric', 'min:0', 'max:9999999999.99999999'],
            'types.*.criteria.*.serious_value' => ['required', 'numeric', 'min:0', 'max:9999999999.99999999'],
            'types.*.criteria.*.extreme_value' => ['required', 'numeric', 'min:0', 'max:9999999999.99999999'],
        ];
    }
}
