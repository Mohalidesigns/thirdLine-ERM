<?php

namespace App\Http\Requests\Questionnaires;

use App\Models\Questionnaire;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add a question to the shared library (migration Phase 4.5).
 *
 * `question_type` was `required` with no `in:`. The column here is a
 * `string(30)` rather than an enum, so an arbitrary value was stored quietly
 * instead of erroring — and a library question typed `Likert Scale` renders no
 * input at all when it reaches a questionnaire. Bounded to the same eight types
 * a question can actually be.
 *
 * The library is deliberately shared: rows with organization_id NULL and
 * is_global true are visible to every tenant. A row created HERE is the
 * caller's own — BelongsToOrganization stamps organization_id on create — so
 * neither field is accepted from the request.
 */
class StoreLibraryQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Questionnaire::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category' => ['required', 'string', 'max:100'],
            'question_text' => ['required', 'string', 'max:2000'],
            'question_type' => ['required', Rule::in(AddQuestionRequest::TYPES)],
            'default_options' => ['nullable', 'array'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
        ];
    }
}
