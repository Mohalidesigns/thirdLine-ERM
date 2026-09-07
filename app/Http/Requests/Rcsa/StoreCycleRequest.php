<?php

namespace App\Http\Requests\Rcsa;

use App\Models\Rcsa\RcsaCycle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Schedule an RCSA cycle (rcsa.cycles.store / .update).
 *
 * `methodology_id` accepts the tenant's own methodologies AND the system one
 * (organization_id NULL), which is the row every tenant scores against until
 * somebody clones it. A plain tenant-bound `Rule::exists` would reject the
 * default and make it impossible to create a cycle on a fresh install.
 */
class StoreCycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $cycle = $this->route('cycle');

        return $cycle === null
            ? $this->user()->can('create', RcsaCycle::class)
            : $this->user()->can('update', $cycle);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $orgId = TenantContext::organizationId();

        return [
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            // Nullable: a bank that runs RCSA continuously has no due date, and
            // inventing one would put every assessment on a countdown nobody agreed.
            'due_date' => ['nullable', 'date', 'after_or_equal:period_start'],
            'methodology_id' => [
                'required',
                'integer',
                Rule::exists('rcsa_methodologies', 'id')->where(
                    fn ($query) => $query->where(fn ($q) => $q->where('organization_id', $orgId)->orWhereNull('organization_id'))
                ),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'period_end.after_or_equal' => 'The period cannot end before it starts.',
        ];
    }
}
