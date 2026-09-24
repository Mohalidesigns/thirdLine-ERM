<?php

namespace App\Http\Requests\Bcms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An observer's score against one objective (observer-scoring spec §4).
 *
 * `evaluator_id` IS NEVER IN THIS PAYLOAD. `ScoringService::score()` always
 * takes it from the signed-in user (clause map §1.3: "no scoring on behalf of
 * another evaluator") — accepting one here would be exactly the hole that
 * rule exists to close.
 *
 * COMMENTARY BELOW 3 IS ENFORCED BY THE SERVICE, NOT ONLY HERE. This
 * `required_if` gives the ordinary validation-error UX; a client that skips
 * it entirely still hits the same rule in `ScoringService::score()`.
 */
class StoreExerciseScoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bcms.exercise.evaluate') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'objective_index' => ['required', 'integer', 'min:0'],
            'score' => ['required', 'integer', 'min:1', 'max:5'],
            'commentary' => ['nullable', 'string', 'max:5000', 'required_if:score,1,2'],
        ];
    }

    public function messages(): array
    {
        return [
            'commentary.required_if' => 'A score of 1 or 2 needs commentary — that sentence is what the '
                .'after-action report and the next exercise will act on.',
        ];
    }
}
