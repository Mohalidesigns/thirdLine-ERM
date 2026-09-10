<?php

namespace App\Http\Requests\LossEvents;

use App\Models\LossEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Report a loss event (migration Phase 4.3).
 *
 * Every foreign key was the string `exists:` form, which accepts ANOTHER
 * TENANT'S id; all three are tenant-bound here.
 *
 * The enum lists move to constants on the model, sourced from these very
 * rules, so the create form's options and the server's answer cannot drift.
 */
class StoreLossEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', LossEvent::class);
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
            'reported_by' => ['required', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'is_near_miss' => ['nullable', 'boolean'],
        ];
    }
}
