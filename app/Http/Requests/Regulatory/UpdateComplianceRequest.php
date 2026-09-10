<?php

namespace App\Http\Requests\Regulatory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Record this institution's compliance position against a circular
 * (migration Phase 5.3).
 *
 * This is the assertion a supervisor reads, which is why it asks for
 * `regulatory.file` through RegulatoryCircularPolicy::assessCompliance()
 * rather than the register-administration permission.
 */
class UpdateComplianceRequest extends FormRequest
{
    /** @var list<string> */
    public const STATUSES = ['not_assessed', 'compliant', 'partially_compliant', 'non_compliant', 'not_applicable'];

    public function authorize(): bool
    {
        return $this->user()->can('assessCompliance', $this->route('circular'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'compliance_status' => ['required', Rule::in(self::STATUSES)],
            'compliance_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'action_required' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
