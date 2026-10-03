<?php

namespace App\Http\Requests\Bcms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `dr-system-register.md` §4 — the statement text IS the attestation's
 * evidence (clause map §3.5): actor, time and statement, no new table.
 */
class RecordBcmsDrBackupAttestationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bcms.dr.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'statement' => ['required', 'string', 'max:2000'],
        ];
    }
}
