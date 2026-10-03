<?php

namespace App\Http\Requests\Bcms;

use App\Enums\Bcms\ExerciseOutcome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ending an exercise occurrence — "End exercise" or "Abort"
 * (execution-workspace spec §4).
 *
 * ABORTING NEEDS A REASON; ENDING NORMALLY DOES NOT. The screen's own
 * confirm modal states the consequence; the reason field only appears (and is
 * only required) once `aborted` is ticked, mirroring the call-tree console's
 * `window.prompt`-for-reason pattern the spec asks this to match.
 */
class CompleteOccurrenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bcms.exercise.facilitate') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'outcome' => ['nullable', Rule::in(array_column(ExerciseOutcome::cases(), 'value'))],
            'aborted' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:2000', 'required_if:aborted,1'],
        ];
    }
}
