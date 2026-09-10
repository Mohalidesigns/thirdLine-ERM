<?php

namespace App\Http\Requests\Assessments;

use App\Models\Risk;
use App\Models\RiskAssessment;

/**
 * PUT risk/assessments/{assessment} — the same thirteen steps against a draft
 * being edited (migration Phase 3.3).
 *
 * Identical rules by construction: store() and update() went through one
 * validateChain() in the controller, and a draft that validated differently
 * from a new assessment is exactly the drift that method existed to prevent.
 * Only where the risk comes from differs — the route's assessment already
 * knows it, so `risk_id` is not required on the way in.
 */
class UpdateAssessmentRequest extends StoreAssessmentRequest
{
    public function authorize(): bool
    {
        $assessment = $this->route('assessment');

        return $assessment instanceof RiskAssessment
            && ($this->user()?->can('update', $assessment) ?? false);
    }

    public function assessedRisk(): ?Risk
    {
        $assessment = $this->route('assessment');

        return $assessment instanceof RiskAssessment ? $assessment->risk : null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = parent::rules();

        unset($rules['risk_id']);

        return $rules;
    }
}
