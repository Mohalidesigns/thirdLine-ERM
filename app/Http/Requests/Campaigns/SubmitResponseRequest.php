<?php

namespace App\Http\Requests\Campaigns;

use App\Models\AssessmentCampaign;
use App\Models\CampaignAssignment;
use App\Models\CampaignResponse;
use App\Services\Campaigns\QuestionnaireAnswerSheet;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * File a submission against an assignment (migration Phase 4.5).
 *
 * `risk_id` and `control_id` were the string `exists:` form and accepted any
 * id in the table, which would have filed this bank's assessment against
 * another bank's register row. Both are now tenant-bound.
 *
 * `control_effectiveness` was `nullable|string` — free text into a
 * `string(30)`, and the respond form offered `not_applicable`, a value the
 * read-back screen has no label for. It is now the one vocabulary,
 * CampaignResponse::EFFECTIVENESS.
 *
 * `questionnaire_data` was read by the controller and never validated. It is
 * the JSON payload an RCSA worksheet line keeps its free text in, so it stays
 * free-form — but it is now declared, and bounded.
 *
 * `questionnaire_answers` is new in 4.5, and so is anything answering a
 * questionnaire at all — see QuestionnaireAnswerSheet for why the engine had
 * never reached a respondent's screen. A question the builder marked required
 * is required here: the respond page renders the asterisk, and a page that
 * renders a required marker it does not enforce is another screen manufacturing
 * the appearance of work.
 */
class SubmitResponseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('respond', $this->tenantCampaign());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $orgId = TenantContext::organizationId();

        return [
            'responses' => ['required', 'array', 'min:1'],
            // Nullable: an RCSA worksheet line describes a risk that is not in
            // the register yet, which is most of what an RCSA finds.
            'responses.*.risk_id' => ['nullable', 'integer', Rule::exists('risks', 'id')->where('organization_id', $orgId)],
            'responses.*.control_id' => ['nullable', 'integer', Rule::exists('controls', 'id')->where('organization_id', $orgId)],
            'responses.*.likelihood_score' => ['nullable', 'integer', 'min:1', 'max:5'],
            'responses.*.impact_score' => ['nullable', 'integer', 'min:1', 'max:5'],
            'responses.*.control_effectiveness' => ['nullable', Rule::in(CampaignResponse::EFFECTIVENESS)],
            'responses.*.comments' => ['nullable', 'string', 'max:5000'],
            'responses.*.questionnaire_data' => ['nullable', 'array'],
            // Question id => answer. Scalars only: every renderable question
            // type produces one value, and `matrix` — which would not — has no
            // renderer and is refused by the page.
            'questionnaire_answers' => ['nullable', 'array'],
            'questionnaire_answers.*' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * Required questions have to be answered.
     *
     * The questionnaire comes off the CAMPAIGN, never off the request, so a
     * respondent cannot choose which questionnaire they are held to.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $questionnaire = $this->tenantCampaign()->questionnaire;

            if ($questionnaire === null) {
                return;
            }

            $answers = (array) $this->input('questionnaire_answers', []);

            foreach (app(QuestionnaireAnswerSheet::class)->requiredQuestionIds($questionnaire) as $questionId) {
                $answer = $answers[$questionId] ?? $answers[(string) $questionId] ?? null;

                if ($answer === null || $answer === '' || $answer === []) {
                    $validator->errors()->add("questionnaire_answers.{$questionId}", 'This question must be answered.');
                }
            }
        });
    }

    /**
     * The assignment's campaign, or 404.
     *
     * campaign_assignments carries no organization_id, so route model binding
     * on {assignment} resolves any id in the table regardless of tenant.
     * AssessmentCampaign does carry the OrganizationScope, so a foreign
     * campaign reads back as null through the relation — which makes this both
     * the tenancy check and the lookup. Aborting here rather than returning
     * false keeps a foreign assignment a 404, as it has always been, instead of
     * telling the caller the row exists by answering 403.
     */
    private function tenantCampaign(): AssessmentCampaign
    {
        $assignment = $this->route('assignment');

        abort_if(! $assignment instanceof CampaignAssignment, 404);

        $campaign = $assignment->campaign()->first();

        abort_if($campaign === null, 404);

        return $campaign;
    }
}
