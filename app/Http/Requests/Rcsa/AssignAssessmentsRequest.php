<?php

namespace App\Http\Requests\Rcsa;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Assign assessors to a cycle's assessments (rcsa.cycles.assign).
 *
 * THE SHAPE ONLY. Whether the assignee holds `rcsa_assessment.complete`, whether
 * they can reach each unit, and whether each assessment still accepts edits are
 * domain rules that need the assessments loaded, and RcsaAssignmentService
 * answers them under a lock — a check made here would be a check made before
 * somebody else's submit committed.
 *
 * `assigned_to` MUST BE PRESENT. Null means "unassign", and a request that
 * merely forgot the key must not silently clear forty assessors.
 *
 * The assignee is a tenant-bound, active user: a bare `exists:users,id` would
 * confirm to a coordinator at one bank that a user id exists at another.
 */
class AssignAssessmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('assign', $this->route('cycle'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'assessment_ids' => ['required', 'array', 'min:1', 'max:500'],
            'assessment_ids.*' => ['integer', 'distinct'],
            'assigned_to' => [
                'present',
                'nullable',
                'integer',
                Rule::exists('users', 'id')
                    ->where('organization_id', TenantContext::organizationId())
                    ->where('is_active', true),
            ],
        ];
    }
}
