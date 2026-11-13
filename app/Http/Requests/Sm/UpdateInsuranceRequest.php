<?php

namespace App\Http\Requests\Sm;

use App\Models\Sm\InsuranceCoverage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Insurance netting update request — `PUT {scenario}/insurance` (spec 09, ADR 0027 Amendment 2 §A2.5).
 */
class UpdateInsuranceRequest extends FormRequest
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
            'coverages' => ['required', 'array'],
            'coverages.*.severity' => ['required', 'string', 'distinct', Rule::in(array_keys(InsuranceCoverage::SEVERITIES))],
            'coverages.*.deductible_local' => ['required', 'numeric', 'min:0', 'max:9999999999.99999999'],
            'coverages.*.limit_local' => ['required', 'numeric', 'min:0', 'max:9999999999.99999999'],
            'coverages.*.deductible_group' => ['required', 'numeric', 'min:0', 'max:9999999999.99999999'],
            'coverages.*.limit_group' => ['required', 'numeric', 'min:0', 'max:9999999999.99999999'],
            'coverages.*.recovery_rate' => ['required', 'numeric', 'between:0,1'],
            'coverages.*.probability_of_cover' => ['required', 'numeric', 'between:0,1'],
            'coverages.*.insurer_default_haircut' => ['required', 'numeric', 'between:0,1'],
        ];
    }
}
