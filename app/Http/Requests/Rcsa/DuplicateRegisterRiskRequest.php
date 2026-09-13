<?php

namespace App\Http\Requests\Rcsa;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Copy a universe risk into another unit or process
 * (rcsa.universe.duplicate).
 *
 * The destination is the whole payload: the copy takes its statement, driver,
 * category, systems and controls from the source and nothing else is
 * negotiable here. Editing the copy is a separate act on the row that results,
 * which keeps "duplicate" meaning one thing.
 */
class DuplicateRegisterRiskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('duplicate', $this->route('risk'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $orgId = TenantContext::organizationId();
        $tenant = fn (string $table) => Rule::exists($table, 'id')->where('organization_id', $orgId);

        return [
            'business_unit_id' => ['required', 'integer', $tenant('business_units')],
            'process_id' => ['nullable', 'integer', $tenant('business_processes')],
            'sub_process_id' => ['nullable', 'integer', $tenant('business_processes')],
        ];
    }
}
