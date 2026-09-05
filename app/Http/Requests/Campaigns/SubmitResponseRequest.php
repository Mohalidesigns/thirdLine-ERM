<?php

namespace App\Http\Requests\Campaigns;

use App\Models\AssessmentCampaign;
use App\Models\CampaignAssignment;
use App\Models\CampaignResponse;
use App\Support\Tenancy\TenantContext;
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
        ];
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
