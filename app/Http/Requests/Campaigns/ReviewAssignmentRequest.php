<?php

namespace App\Http\Requests\Campaigns;

use App\Models\AssessmentCampaign;
use App\Models\CampaignAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Accept or return a submitted assignment (migration Phase 4.5).
 *
 * `reviewer_notes` stays OPTIONAL, as it has always been. The defect here was
 * never the validator: the campaign page's Reject button posted no notes field
 * at all, so the only screen most reviewers use could not attach a reason even
 * though the controller has always accepted one and the rejection notification
 * has always quoted it. The ported page offers the field. Whether a rejection
 * without a reason should be refused outright is a policy call for the product,
 * and is recorded in the module notes rather than decided here.
 */
class ReviewAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('review', $this->tenantCampaign());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['approve', 'reject'])],
            'reviewer_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** The assignment's campaign, or 404 — see SubmitResponseRequest. */
    private function tenantCampaign(): AssessmentCampaign
    {
        $assignment = $this->route('assignment');

        abort_if(! $assignment instanceof CampaignAssignment, 404);

        $campaign = $assignment->campaign()->first();

        abort_if($campaign === null, 404);

        return $campaign;
    }
}
