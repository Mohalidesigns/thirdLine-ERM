<?php

namespace App\Http\Requests\Questionnaires;

use App\Models\Questionnaire;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Start a questionnaire (migration Phase 4.5).
 *
 * `questionnaire_type` was validated `required` with no `in:` at all, while the
 * column is an ENUM of six values — so anything the form did not send became a
 * driver-level error on MySQL rather than a validation message. It is now
 * bounded to the enum, which is exactly the list the create screen offers.
 */
class StoreQuestionnaireRequest extends FormRequest
{
    /** The six values of the `questionnaires.questionnaire_type` enum. */
    public const TYPES = ['rcsa', 'fraud_risk', 'compliance', 'new_product', 'vendor', 'custom'];

    /** The four values of the `questionnaires.scoring_method` enum. */
    public const SCORING_METHODS = ['average', 'weighted', 'highest', 'sum'];

    public function authorize(): bool
    {
        return $this->user()->can('create', Questionnaire::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'questionnaire_type' => ['required', Rule::in(self::TYPES)],
            'scoring_method' => ['required', Rule::in(self::SCORING_METHODS)],
        ];
    }
}
