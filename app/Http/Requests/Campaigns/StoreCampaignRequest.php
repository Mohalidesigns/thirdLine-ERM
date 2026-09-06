<?php

namespace App\Http\Requests\Campaigns;

use App\Models\AssessmentCampaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Open an assessment campaign (migration Phase 4.5).
 *
 * `questionnaire_id` and `reviewer_id` were not validated AT ALL — the
 * controller read them straight off the request and mass-assigned them, so a
 * hand-made POST could hang this bank's campaign off another bank's
 * questionnaire and name another bank's user as its reviewer. Both are now
 * tenant-bound.
 *
 * `questionnaire_id` additionally has to be PUBLISHED. The create screen has
 * only ever offered published questionnaires, so this closes the gap between
 * the form and the validator rather than changing what the screen can do — and
 * a campaign attached to a draft is a campaign whose questions can be rewritten
 * underneath two hundred respondents mid-flight.
 */
class StoreCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', AssessmentCampaign::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $orgId = TenantContext::organizationId();

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'campaign_type' => ['required', Rule::in(['rcsa', 'fraud_risk', 'compliance', 'new_product', 'custom'])],
            'questionnaire_id' => [
                'nullable',
                Rule::exists('questionnaires', 'id')
                    ->where('organization_id', $orgId)
                    ->where('status', 'published')
                    ->whereNull('deleted_at'),
            ],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'reviewer_id' => ['nullable', Rule::exists('users', 'id')->where('organization_id', $orgId)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'questionnaire_id.exists' => 'Select a published questionnaire belonging to your organisation.',
            'reviewer_id.exists' => 'Select a reviewer from your organisation.',
        ];
    }
}
