<?php

namespace App\Http\Requests\Bcms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Starting an exercise occurrence (execution-workspace spec, the readiness
 * gate). `confirmed_override` is the documented override path (acceptance
 * criterion 2) — `OccurrenceExecutionService::start()` still calls the
 * existing `ReadinessService::gate()` and audits the override itself; this
 * flag is only the caller's acknowledgement that they saw the warning.
 *
 * `confirmed_early_start` is the same shape of flag for the OTHER gate
 * `start()` checks: today is before `scheduled_date`. Both are
 * acknowledgements the caller saw a warning, not authority of their own —
 * the service decides whether either gate applies.
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
            'confirmed_early_start' => ['nullable', 'boolean'],
        ];
    }
}
