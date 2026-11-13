<?php

namespace App\Http\Requests\Sm;

use App\Models\Sm\Scorecard;
use App\Models\Sm\ScorecardComponent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Scorecard update request — `PUT {scenario}/scorecard` (spec 06, ADR 0027 Amendment 2 §A2.2).
 */
class UpdateScorecardRequest extends FormRequest
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
            'control_nature' => ['required', 'string', Rule::in(array_keys(Scorecard::CONTROL_NATURES))],
            'components' => ['required', 'array'],
            'components.*.component_code' => ['required', 'string', 'distinct', Rule::in(array_keys(ScorecardComponent::COMPONENTS))],
            'components.*.efficiency_level' => ['required', 'integer', 'between:0,5'],
            'components.*.weighting_factor' => ['required', 'integer', 'between:0,5'],
            'components.*.notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
