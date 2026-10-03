<?php

namespace App\Http\Requests\Bcms;

use App\Enums\Bcms\InjectDeliveryChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Authoring a scripted inject on an occurrence (gap 2 — `bcms_exercise_injects`
 * existed with nothing outside a test factory ever writing a row).
 *
 * `delivery_channel` IS VALIDATED AGAINST `InjectDeliveryChannel`, NEVER CAST
 * ON THE MODEL (ADR 0023 Amendment 1, A1.3(c)) — the enforcement is here, at
 * the one place a value enters the column.
 */
class StoreExerciseInjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bcms.exercise.facilitate') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:250'],
            'content' => ['nullable', 'string', 'max:20000'],
            'release_offset_minutes' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'delivery_channel' => ['nullable', 'string', Rule::in(InjectDeliveryChannel::values())],
        ];
    }
}
