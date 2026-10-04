<?php

namespace App\Http\Requests\Bcms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `dr-test-record.md` §3 — the one write action on an otherwise read-only
 * ingested record: whether the objective was met is our judgement against
 * our target, never accepted from the provider (clause map §3.6).
 */
class ConfirmBcmsDrTestObjectivesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bcms.dr.test.record') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['met' => ['required', 'boolean']];
    }
}
