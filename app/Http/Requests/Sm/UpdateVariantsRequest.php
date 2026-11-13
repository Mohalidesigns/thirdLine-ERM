<?php

namespace App\Http\Requests\Sm;

use App\Models\Sm\ScenarioVariant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Variants deck update request — `PUT {scenario}/variants` (spec 10, ADR 0027 Amendment 3 §A3.2).
 */
class UpdateVariantsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('scenario.update') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'variants' => ['required', 'array'],
            'variants.*.tier' => ['required', 'string', 'distinct', Rule::in(ScenarioVariant::TIERS)],
            'variants.*.name' => ['required', 'string', 'max:150'],
            'variants.*.narrative' => ['nullable', 'string', 'max:10000'],
            'variants.*.is_cumulative' => ['sometimes', 'boolean'],
            'variants.*.triggers' => ['nullable', 'array'],
            'variants.*.triggers.*' => ['nullable', 'string', 'max:1000'],
            'variants.*.operational_impacts' => ['nullable', 'array'],
            'variants.*.operational_impacts.*' => ['nullable', 'string', 'max:1000'],
            'variants.*.responses' => ['nullable', 'array'],
            'variants.*.responses.*' => ['nullable', 'string', 'max:1000'],
            'variants.*.response_owners' => ['nullable', 'array'],
            'variants.*.response_owners.*' => ['nullable', 'string', 'max:200'],
        ];
    }
}
