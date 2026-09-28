<?php

namespace App\Http\Requests\Bcms;

use App\Enums\Bcms\InjectDeliveryChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Editing an unreleased inject (gap 2). Whether it has already been released
 * is a database fact, not a validation rule — `InjectService::update()`
 * refuses that case with a named reason, the same split every other
 * lifecycle rule in this module uses (standard §3).
 */
class UpdateExerciseInjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bcms.exercise.facilitate') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:250'],
            'content' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'release_offset_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'],
            'delivery_channel' => ['sometimes', 'nullable', 'string', Rule::in(InjectDeliveryChannel::values())],
        ];
    }
}
