<?php

namespace App\Http\Requests\Rcsa;

use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaExportJob;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The filters on a bulk download (§10.2).
 *
 * EVERY FOREIGN KEY IS BOUND TO THE TENANT IN THE RULE ITSELF, not merely
 * scoped in the query afterwards. The query would return nothing for a foreign
 * cycle anyway, but "returns nothing" and "is refused" are different things to
 * the export log: the first records an innocent empty export and the second
 * records an attempt. On the one screen in this module that hands out the whole
 * risk profile as a file, that distinction is worth a validation rule.
 */
class StoreRcsaExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', RcsaExportJob::class) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $orgId = TenantContext::organizationId();

        return [
            'cycle' => ['nullable', Rule::exists('rcsa_cycles', 'id')->where('organization_id', $orgId)],

            'business_units' => ['nullable', 'array', 'max:200'],
            'business_units.*' => [Rule::exists('business_units', 'id')->where('organization_id', $orgId)],

            'risk_category' => ['nullable', 'string', 'max:64'],
            'inherent_level' => ['nullable', 'string', 'max:32'],
            'residual_level' => ['nullable', 'string', 'max:32'],
            'treatment' => ['nullable', 'string', 'max:32'],

            'appetite' => ['nullable', Rule::in(['above', 'within'])],

            'assessment_status' => ['nullable', Rule::in([
                RcsaAssessment::DRAFT, RcsaAssessment::IN_PROGRESS, RcsaAssessment::BU_APPROVAL,
                RcsaAssessment::SUBMITTED, RcsaAssessment::UNDER_REVIEW, RcsaAssessment::VALIDATED,
                RcsaAssessment::RETURNED, RcsaAssessment::CLOSED,
            ])],

            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'cycle.exists' => 'That cycle does not belong to this organisation.',
            'business_units.*.exists' => 'One of those business units does not belong to this organisation.',
            'to.after_or_equal' => 'The end of the date range cannot be before its start.',
        ];
    }
}
