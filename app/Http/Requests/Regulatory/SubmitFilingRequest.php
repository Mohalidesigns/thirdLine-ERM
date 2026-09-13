<?php

namespace App\Http\Requests\Regulatory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Record that a return was filed against a deadline (migration Phase 5.3).
 *
 * `regulatory_filings` carries NO `organization_id` — it is scoped through its
 * deadline, which does. That is why the deadline is authorised here rather
 * than the filing: there is no tenant on the filing row to check.
 */
class SubmitFilingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('file', $this->route('deadline'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'filing_date' => ['required', 'date'],
            'document_ref' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
