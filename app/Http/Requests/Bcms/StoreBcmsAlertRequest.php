<?php

namespace App\Http\Requests\Bcms;

use App\Enums\Bcms\AlertSeverity;
use App\Enums\Bcms\ChannelKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Composing an alert — from the EMNS console, or from the crisis room with an
 * incident attached (Gate 2 review #1 defect 13).
 *
 * `incident_id` IS VALIDATED AS A SHAPE, NOT A TENANT-BOUND `Rule::exists()` —
 * same reasoning as `ActivateBcmsPlanRequest`: an incident is visible only to
 * its business unit, not merely its tenant, so `AlertController::store()`
 * resolves the uuid through `Incident::visibleTo($request->user())` and 404s
 * if it does not resolve, rather than trusting a bare tenant-scoped `exists`.
 */
class StoreBcmsAlertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bcms.alert.compose') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'message' => ['nullable', 'string', 'max:4000'],
            'template_id' => ['nullable', 'integer'],
            'severity' => ['required', Rule::in(array_column(AlertSeverity::cases(), 'value'))],
            'channels' => ['nullable', 'array'],
            'channels.*' => ['string', Rule::in(array_column(ChannelKey::cases(), 'value'))],
            'audience_rule' => ['nullable', 'array'],
            'occurrence_id' => ['nullable', 'integer'],
            'incident_id' => ['nullable', 'string', 'uuid'],
            'response_required' => ['nullable', 'boolean'],
            'ack_window_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ];
    }
}
