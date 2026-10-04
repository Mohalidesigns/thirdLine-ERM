<?php

namespace App\Http\Requests\Bcms;

use App\Enums\Bcms\NotificationKind;
use App\Enums\Bcms\NotificationRegulator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `incident-notification-log.md` §2/§4 — "Record as submitted", deliberately
 * not "Submit" or "Send": nothing on this route transmits anything to a
 * regulator (ADR 0020 §2 point 4).
 */
class RecordBcmsIncidentNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bcms.incident.notify') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'regulator' => ['required', Rule::enum(NotificationRegulator::class)],
            'kind' => ['required', Rule::enum(NotificationKind::class)],
            'reference' => ['nullable', 'string', 'max:120'],
            'content_snapshot' => ['nullable', 'array'],
            'awareness_at' => ['nullable', 'date', 'before_or_equal:now'],
            // Only consumed on the implicit classify() this triggers when no
            // obligation exists yet, and only required by the service if
            // `awareness_at` is later than the incident's default (ADR 0020
            // Amendment 2 rule 6).
            'awareness_reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
