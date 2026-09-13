<?php

namespace App\Http\Requests\Questionnaires;

use App\Models\Questionnaire;
use App\Models\QuestionnaireSection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add a question to a section (migration Phase 4.5).
 *
 * THIS ROUTE HAD NO TENANCY CHECK OF ANY KIND. `questionnaire_sections` carries
 * no organization_id, so it is not tenant-scoped, and route model binding on
 * {section} resolved any id in the table — a user holding questionnaire.edit in
 * one bank could post a question into another bank's questionnaire, and a probe
 * against HEAD confirmed the row landed. The section's questionnaire IS scoped,
 * so a foreign parent reads back as null: that walk is both the tenancy check
 * and the lookup, and it 404s.
 *
 * `weight` was not validated at all and went straight into a `decimal(5,2)`.
 */
class AddQuestionRequest extends FormRequest
{
    /** The eight values of the `questions.question_type` enum. */
    public const TYPES = [
        'multiple_choice', 'likert', 'yes_no', 'free_text',
        'numeric', 'file_upload', 'matrix', 'rating',
    ];

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->tenantQuestionnaire());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'question_text' => ['required', 'string', 'max:2000'],
            'question_type' => ['required', Rule::in(self::TYPES)],
            'options' => ['nullable', 'array'],
            'scoring_rules' => ['nullable', 'array'],
            'is_required' => ['nullable', 'boolean'],
            'help_text' => ['nullable', 'string', 'max:2000'],
            'weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    /** The section's questionnaire, or 404. */
    private function tenantQuestionnaire(): Questionnaire
    {
        $section = $this->route('section');

        abort_if(! $section instanceof QuestionnaireSection, 404);

        $questionnaire = $section->questionnaire()->first();

        abort_if($questionnaire === null, 404);

        return $questionnaire;
    }
}
