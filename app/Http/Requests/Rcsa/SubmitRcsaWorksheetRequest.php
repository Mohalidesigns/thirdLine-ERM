<?php

namespace App\Http\Requests\Rcsa;

use App\Support\Rcsa\RcsaProgramme;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * File an RCSA worksheet, as a draft or as a submission
 * (risk.rcsa.worksheet.store, migration Phase 3.8).
 *
 * The rules are RcsaController::storeWorksheet()'s, moved here unchanged —
 * they were already tenant-bound `Rule::exists` rather than the string form,
 * which is why this module needed no correction there.
 *
 * `campaign_id` stays NULLABLE deliberately: an organisation that has not set
 * up a campaign must still be able to record an assessment, and
 * RcsaWorksheetService opens an ad-hoc campaign rather than losing the
 * submission. A worksheet is never discarded for want of configuration the
 * respondent cannot change.
 */
class SubmitRcsaWorksheetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('submit', RcsaProgramme::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $orgId = TenantContext::organizationId();

        return [
            'campaign_id' => ['nullable', 'integer', Rule::exists('assessment_campaigns', 'id')->where('organization_id', $orgId)],
            'business_unit_id' => ['required', 'integer', Rule::exists('business_units', 'id')->where('organization_id', $orgId)],
            'process_id' => ['nullable', 'integer', Rule::exists('business_processes', 'id')->where('organization_id', $orgId)],
            'assessment_date' => ['required', 'date'],
            'action' => ['nullable', 'in:draft,submit'],

            'risks' => ['required', 'array', 'min:1'],
            'risks.*.description' => ['required', 'string', 'max:5000'],
            'risks.*.category' => ['nullable', 'string', 'max:60'],
            // Nullable: a worksheet line may describe a risk that is not in the
            // register yet, which is most of what an RCSA finds.
            'risks.*.risk_id' => ['nullable', 'integer', Rule::exists('risks', 'id')->where('organization_id', $orgId)],
            'risks.*.inherent_likelihood' => ['required', 'integer', 'min:1', 'max:5'],
            'risks.*.inherent_impact' => ['required', 'integer', 'min:1', 'max:5'],
            'risks.*.residual_likelihood' => ['required', 'integer', 'min:1', 'max:5'],
            'risks.*.residual_impact' => ['required', 'integer', 'min:1', 'max:5'],
            // One vocabulary, one home — see CampaignResponse::EFFECTIVENESS.
            'risks.*.control_effectiveness' => ['nullable', Rule::in(\App\Models\CampaignResponse::EFFECTIVENESS)],
            'risks.*.existing_controls' => ['nullable', 'string', 'max:5000'],
            'risks.*.action_plan' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'risks.required' => 'A worksheet needs at least one risk line.',
            'risks.*.description.required' => 'Every risk line needs a description.',
        ];
    }

    public function isSubmission(): bool
    {
        return ($this->validated('action') ?? 'submit') === 'submit';
    }
}
