<?php

namespace App\Http\Requests\Bcms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Re-capturing a management review's clause 9.3 inputs — specifically the
 * two manual sections a human types: `internal_audit` (ADR 0021 §1's five
 * keys — report reference, date, auditor, independence statement,
 * conclusion) and free-text `interested_party_feedback` /
 * `context_changes`.
 *
 * B12: `internal_audit` used to be accepted as `(array) $request->input(...)`
 * with no validation at all — an unbounded, arbitrarily-keyed blob written
 * straight into `bcms_management_reviews.inputs` json. Naming the five keys
 * here means anything else submitted is silently dropped by
 * `FormRequest::validated()`, not stored.
 */
class CaptureBcmsReviewInputsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bcms.programme.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'internal_audit' => ['nullable', 'array'],
            'internal_audit.report_reference' => ['nullable', 'string', 'max:100'],
            'internal_audit.date' => ['nullable', 'date'],
            'internal_audit.auditor' => ['nullable', 'string', 'max:200'],
            'internal_audit.independence_statement' => ['nullable', 'string', 'max:2000'],
            'internal_audit.conclusion' => ['nullable', 'string', 'max:2000'],
            'interested_party_feedback' => ['nullable', 'string', 'max:5000'],
            'context_changes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
