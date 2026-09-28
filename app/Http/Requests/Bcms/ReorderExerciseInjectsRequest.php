<?php

namespace App\Http\Requests\Bcms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reordering an occurrence's injects (gap 2). The payload is the full,
 * ordered list of inject ids — `InjectService::reorder()` checks it is
 * exactly the occurrence's current set, tenant-bound through the route model
 * binding rather than a bare `exists:`.
 */
class ReorderExerciseInjectsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bcms.exercise.facilitate') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'inject_ids' => ['required', 'array', 'min:1'],
            'inject_ids.*' => ['required', 'integer'],
        ];
    }
}
