<?php

namespace App\Http\Requests\Issues;

use App\Http\Requests\Concerns\ValidatesConfiguredAttributes;
use App\Models\Issue;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Amend an issue (migration Phase 4.4).
 *
 * `remediation_due_date` drops the `after:today` the create rules carry: an
 * issue already past its date must still be editable, and requiring a future
 * date would make the overdue ones the only ones nobody can correct.
 */
class UpdateIssueRequest extends FormRequest
{
    use ValidatesConfiguredAttributes;

    public function authorize(): bool
    {
        $issue = $this->route('issue');

        return $issue instanceof Issue && $this->user()->can('update', $issue);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $orgId = TenantContext::organizationId();

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'issue_source' => ['nullable', 'string', 'max:255'],
            // REQUIRED, and max:50, because the column is
            // `string('issue_category', 50)` NOT NULL with no default. It was
            // validated as `nullable|max:100`, so an issue raised without a
            // category hit a NOT NULL violation — a 500, not a validation
            // message — and one with a 51-character category was truncated or
            // rejected by the driver. See the module notes.
            'issue_category' => ['required', 'string', 'max:50'],
            'priority' => ['required', Rule::in(Issue::PRIORITIES)],
            'business_unit_id' => ['required', Rule::exists('business_units', 'id')->where('organization_id', $orgId)],
            'responsible_owner_id' => ['required', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            // The schema field is `risk_register_id`, which is the column;
            // `risk_id` is the legacy form name the controller still maps.
            // Both are accepted and both are tenant-bound.
            'risk_register_id' => ['nullable', Rule::exists('risks', 'id')->where('organization_id', $orgId)],
            'risk_id' => ['nullable', Rule::exists('risks', 'id')->where('organization_id', $orgId)],
            'department' => ['nullable', 'string', 'max:255'],
            'remediation_due_date' => ['required', 'date'],
            'management_response_due' => ['nullable', 'date'],
            'root_cause' => ['nullable', 'string', 'max:3000'],
            'impact_description' => ['nullable', 'string', 'max:2000'],
            'recommended_action' => ['nullable', 'string', 'max:3000'],
            'management_response' => ['nullable', 'string', 'max:5000'],
            'action_plan' => ['nullable', 'string', 'max:5000'],
            'interim_controls' => ['nullable', 'string', 'max:3000'],
            'cbn_examination_finding' => ['nullable', 'string', 'max:3000'],
            'cbn_response_deadline' => ['nullable', 'date'],
            'regulatory_reportable' => ['nullable', 'boolean'],
            'ndpa_breach_type' => ['nullable', 'string', 'max:100'],
            ...$this->configuredAttributeRules('Issue'),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return $this->configuredAttributeLabels('Issue');
    }
}
