<?php

namespace App\Http\Requests\Assessments;

use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Models\RiskAssessmentControl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * POST risk/assessments/preview — score the chain as it currently stands
 * (migration Phase 3.3).
 *
 * Deliberately tolerant where StoreAssessmentRequest is strict: this fires
 * while the assessor is still typing, so a half-scored chain is the normal
 * case and not an error. Nothing is written, so the only thing that has to
 * hold is that the caller may create assessments and that the risk is theirs.
 * Which controls count is settled inside the service, against the controls
 * actually mapped to the risk.
 */
class PreviewAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('create', RiskAssessment::class) ?? false)
            && $this->assessedRisk() !== null;
    }

    public function assessedRisk(): ?Risk
    {
        return once(fn () => Risk::where('organization_id', TenantContext::organizationId())
            ->find($this->integer('risk_id')));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'risk_id' => ['required', 'integer'],
            'likelihood' => ['nullable', 'integer', 'min:0'],
            'impacts' => ['nullable', 'array'],
            'impacts.*' => ['nullable', 'integer', 'min:0'],
            'controls' => ['nullable', 'array', 'max:200'],
            'controls.*.design_effectiveness' => ['nullable', 'string', Rule::in(array_keys(RiskAssessmentControl::RATINGS))],
            'controls.*.operating_effectiveness' => ['nullable', 'string', Rule::in(array_keys(RiskAssessmentControl::RATINGS))],
            'residual_likelihood' => ['nullable', 'integer', 'min:0'],
            'residual_impact' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
