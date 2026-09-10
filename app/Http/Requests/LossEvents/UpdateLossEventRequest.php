<?php

namespace App\Http\Requests\LossEvents;

use App\Models\LossEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Amend a reported loss event (migration Phase 4.3).
 *
 * `reported_by` and `is_near_miss` are absent, exactly as they were: update()
 * never accepted either. Who reported an event and whether it was a near miss
 * are facts about what happened, not fields to revise on the edit form.
 */
class UpdateLossEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('loss_event');

        return $event instanceof LossEvent && $this->user()->can('update', $event);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $orgId = TenantContext::organizationId();

        return [
            'event_title' => ['required', 'string', 'max:255'],
            'event_description' => ['required', 'string', 'max:5000'],
            'date_of_loss' => ['required', 'date'],
            'date_discovered' => ['required', 'date', 'after_or_equal:date_of_loss'],
            'business_unit_id' => ['required', Rule::exists('business_units', 'id')->where('organization_id', $orgId)],
            'risk_id' => ['nullable', Rule::exists('risks', 'id')->where('organization_id', $orgId)],
            'basel_event_type' => ['required', Rule::in(LossEvent::BASEL_EVENT_TYPES)],
            'cbn_loss_category' => ['nullable', 'string', 'max:255'],
            'event_type' => ['required', Rule::in(LossEvent::EVENT_TYPES)],
            'severity' => ['required', Rule::in(LossEvent::SEVERITIES)],
            'gross_loss_amount' => ['required', 'numeric', 'min:0'],
            'recovery_amount' => ['nullable', 'numeric', 'min:0'],
            'insurance_recovery' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'max:3'],
            'root_cause_summary' => ['nullable', 'string', 'max:2000'],
            'corrective_action_summary' => ['nullable', 'string', 'max:2000'],
            'is_regulatory_reportable' => ['nullable', 'boolean'],
            'regulatory_body' => ['nullable', 'string', 'max:255'],
            'reporting_deadline' => ['nullable', 'date'],
        ];
    }
}
