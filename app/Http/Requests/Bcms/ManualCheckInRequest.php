<?php

namespace App\Http\Requests\Bcms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The facilitator checking a participant in by hand, from the execution
 * workspace (execution-workspace spec §4) — the manual method among
 * `qr|sms|manual|geo`. `participant_id` is scoped to the route's own
 * occurrence in the controller, not validated against a bare `exists:` here:
 * a participant belongs to exactly one occurrence and the controller already
 * has it in scope.
 */
class ManualCheckInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bcms.exercise.facilitate') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'method' => ['required', Rule::in(['manual'])],
            'participant_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
