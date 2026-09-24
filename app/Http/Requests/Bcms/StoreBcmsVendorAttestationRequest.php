<?php

namespace App\Http\Requests\Bcms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Recording a vendor BCP/DR test result — writes `tp_bcp_tests` through
 * TPRM's own service (`App\Services\Tprm\Continuity\BcpTestRecorder`), never
 * directly. `bcms.report.view` is the floor; the recorder itself additionally
 * requires TPRM's `tprm.edit` (phase-11-spec §5).
 */
class StoreBcmsVendorAttestationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bcms.report.view') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $organizationId = $this->user()?->organization_id;

        return [
            'engagement_id' => [
                'required', 'integer',
                Rule::exists('tp_engagements', 'id')->where('organization_id', $organizationId),
            ],
            'test_date' => ['required', 'date'],
            'test_type' => ['required', 'string', 'max:30'],
            'our_participation' => ['boolean'],
            'rto_achieved_hours' => ['nullable', 'integer', 'min:0'],
            'rpo_achieved_hours' => ['nullable', 'integer', 'min:0'],
            'outcome' => ['nullable', 'string', 'max:40'],
            'findings_raised' => ['nullable', 'array'],
            'findings_raised.*' => ['string', 'max:500'],
            // B13: was an unconstrained integer — another tenant's
            // `tp_documents` id was accepted outright, and a nonexistent id
            // 500'd instead of failing validation.
            'evidence_document_id' => [
                'nullable', 'integer',
                Rule::exists('tp_documents', 'id')->where('organization_id', $organizationId),
            ],
            'next_due_at' => ['nullable', 'date'],
        ];
    }
}
