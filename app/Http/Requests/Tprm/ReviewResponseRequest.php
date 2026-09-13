<?php

namespace App\Http\Requests\Tprm;

use App\Enums\Tprm\AssuranceLevel;
use App\Enums\Tprm\ComplianceLevel;
use App\Models\Tprm\Assessment;
use App\Models\Tprm\AssessmentResponse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A reviewer's verdict on one answer — FR-ASM-08.
 *
 * A REJECTION OR A CLARIFICATION MUST CARRY A COMMENT. "Rejected" with no
 * reason gives the vendor nothing to act on, and the resubmission is a guess;
 * two rounds of guessing is how a twenty-one-day assessment turns into ninety.
 */
class ReviewResponseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $assessment = $this->route('assessment');

        return $assessment instanceof Assessment
            && ($this->user()?->can('review', $assessment) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $needsReason = in_array($this->input('reviewer_status'), [
            AssessmentResponse::REVIEW_REJECTED,
            AssessmentResponse::REVIEW_CLARIFICATION,
        ], true);

        return [
            'reviewer_status' => ['required', Rule::in([
                AssessmentResponse::REVIEW_ACCEPTED,
                AssessmentResponse::REVIEW_REJECTED,
                AssessmentResponse::REVIEW_CLARIFICATION,
            ])],

            'reviewer_comment' => [$needsReason ? 'required' : 'nullable', 'string', 'max:2000'],
            'message' => ['nullable', 'string', 'max:2000'],

            // A reviewer may correct both. Correcting the assurance level is
            // the commonest one: a vendor claiming `independently_assured`
            // with a policy PDF attached is what review is for.
            'compliance' => ['nullable', Rule::in(ComplianceLevel::values())],
            'assurance_level' => ['nullable', Rule::in(AssuranceLevel::values())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reviewer_comment.required' => 'Say what is wrong with the answer — a rejection with no reason leaves '
                .'the vendor guessing, and the resubmission will be a guess.',
        ];
    }
}
