<?php

namespace App\Http\Requests\Questionnaires;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Add a section to a questionnaire (migration Phase 4.5).
 *
 * The route binds {questionnaire}, which IS tenant-scoped, so binding does the
 * tenancy work here and the policy only has to answer the permission.
 *
 * `weight` is `decimal(5,2)`, so 999.99 is the real ceiling; the old
 * `max:100` rule was tighter than the column and is kept, because a section
 * weight is a share and the edit screen presents it as one.
 */
class AddSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('questionnaire'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
