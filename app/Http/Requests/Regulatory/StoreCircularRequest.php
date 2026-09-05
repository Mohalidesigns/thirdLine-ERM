<?php

namespace App\Http\Requests\Regulatory;

use App\Models\RegulatoryCircular;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Record a regulator's circular (migration Phase 5.3).
 *
 * THREE FOREIGN KEYS WENT IN UNVALIDATED. The controller checked four scalar
 * fields and then wrote `assigned_to` and `affected_risk_ids` from the request
 * body without a rule between them and the row, so a circular could be
 * assigned to another institution's user and linked to another institution's
 * risks. `affected_risk_ids` is a json array, so each element needs its own
 * tenant-bound rule — the array itself having a rule says nothing about what
 * is in it.
 *
 * `impact_level` and `compliance_status` are enum columns; the controller
 * defaulted the first with `?? 'medium'` and hardcoded the second. Both are
 * validated against the vocabulary the column actually holds, which is the
 * 4.6 lesson: what a form OFFERS must be a subset of what the validator
 * ACCEPTS, and both should come from one place.
 */
class StoreCircularRequest extends FormRequest
{
    /** @var list<string> */
    public const IMPACT_LEVELS = ['low', 'medium', 'high', 'critical'];

    public function authorize(): bool
    {
        return $this->user()->can('create', RegulatoryCircular::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $orgId = TenantContext::organizationId();

        return [
            'regulator' => ['required', 'string', 'max:50'],
            'circular_ref' => ['required', 'string', 'max:100'],
            'title' => ['required', 'string', 'max:255'],
            'date_issued' => ['required', 'date'],
            'effective_date' => ['nullable', 'date'],
            'summary' => ['nullable', 'string', 'max:10000'],
            'impact_level' => ['nullable', Rule::in(self::IMPACT_LEVELS)],
            'action_required' => ['nullable', 'string', 'max:10000'],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'affected_risk_ids' => ['nullable', 'array'],
            // Each element, not just the array: a rule on the array says
            // nothing about what is inside it.
            'affected_risk_ids.*' => ['integer', Rule::exists('risks', 'id')->where('organization_id', $orgId)],
        ];
    }
}
