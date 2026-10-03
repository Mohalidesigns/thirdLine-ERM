<?php

namespace App\Http\Requests\Bcms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Creating a training curriculum — clauses 7.2/7.3.
 *
 * A CURRICULUM ASSERTING COMPETENCE MUST SAY HOW IT IS ASSESSED. `pass_mark`
 * is required whenever `requires_assessment` is true — a curriculum that
 * claims competence without a pass mark produces a record nobody can score
 * against.
 */
class StoreBcmsTrainingCurriculumRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bcms.training.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $organizationId = $this->user()?->organization_id;

        return [
            'code' => [
                'required', 'string', 'max:40',
                \Illuminate\Validation\Rule::unique('bcms_training_curricula', 'code')
                    ->where('organization_id', $organizationId),
            ],
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'target_roles' => ['required', 'array', 'min:1'],
            'target_roles.*' => ['string', 'max:60'],
            'modules' => ['nullable', 'array'],
            'frequency_months' => ['required', 'integer', 'min:1', 'max:60'],
            'is_mandatory' => ['boolean'],
            'requires_assessment' => ['boolean'],
            'pass_mark' => ['required_if:requires_assessment,true', 'nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
