<?php

namespace App\Http\Requests\Treatments;

use App\Models\TreatmentPlan;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Add a review comment to a plan (risk.treatments.comment).
 *
 * The comment becomes a RiskAuditTrail row. It was `$request->input('comment',
 * 'Comment added')` — an empty textarea wrote the literal placeholder into the
 * audit trail, so the field is required here.
 */
class StoreTreatmentCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $plan = $this->route('treatment');

        return $plan instanceof TreatmentPlan && $this->user()->can('comment', $plan);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'comment' => ['required', 'string', 'max:2000'],
        ];
    }
}
