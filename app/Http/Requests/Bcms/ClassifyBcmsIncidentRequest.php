<?php

namespace App\Http\Requests\Bcms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The two reportability questions, "unknown" a real answer
 * (`incident-declaration.md` §3, `crisis-room.md` §2 item 3).
 *
 * ANSWERING `yes` IS THE ACT OF CLASSIFYING AN OBLIGATION (ADR 0020 §2 point
 * 1) — `awareness_at` DEFAULTS TO THE INCIDENT'S `detected_at`, FALLING BACK
 * TO `declared_at`, NEVER TO NOW (Amendment 2). It is editable backward with
 * no justification, which is what makes the criterion-5 backdating case
 * (detected yesterday, classified today) answerable from what this request
 * actually accepts. Moving it LATER than that default requires
 * `awareness_reason` (Amendment 2 rule 6) — enforced in
 * `NotificationService::classify()`, which knows the default this request
 * cannot compute without querying the incident itself.
 */
class ClassifyBcmsIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bcms.incident.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'question' => ['required', Rule::in(['cbn', 'personal_data'])],
            'answer' => ['required', Rule::in(['yes', 'no', 'unknown'])],
            'awareness_at' => ['nullable', 'date', 'before_or_equal:now'],
            // Required only when the officer moved awareness LATER than the
            // default (Amendment 2 rule 6) — the service, not this request,
            // is what knows the default, so the service refuses rather than
            // this rule set.
            'awareness_reason' => ['nullable', 'string', 'max:2000'],
            'reason' => ['nullable', 'string', 'max:2000', 'required_if:answer,no'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required_if' => 'Reassessing an obligation as not reportable must give a reason.',
        ];
    }
}
