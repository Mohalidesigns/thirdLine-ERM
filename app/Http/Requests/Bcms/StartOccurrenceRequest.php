<?php

namespace App\Http\Requests\Bcms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Starting an exercise occurrence (execution-workspace spec, the readiness
 * gate). `confirmed_override` is the documented override path (acceptance
 * criterion 2) — `OccurrenceExecutionService::start()` still calls the
 * existing `ReadinessService::gate()` and audits the override itself; this
 * flag is only the caller's acknowledgement that they saw the warning.
 */
class StartOccurrenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bcms.exercise.facilitate') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'confirmed_override' => ['nullable', 'boolean'],
        ];
    }
}
