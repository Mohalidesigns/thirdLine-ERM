<?php

namespace App\Http\Requests\Rcsa;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Open a cycle (rcsa.cycles.open).
 *
 * `business_unit_ids` is optional and means "only these units". Omitting it
 * provisions every unit that has published risks, which is the normal case; the
 * narrowing exists for a pilot — §13's parallel run wants one business unit in
 * the new module before the bank commits to it.
 */
class OpenCycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('open', $this->route('cycle'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $orgId = TenantContext::organizationId();

        return [
            'business_unit_ids' => ['nullable', 'array'],
            'business_unit_ids.*' => [
                'integer',
                Rule::exists('business_units', 'id')->where('organization_id', $orgId),
            ],
        ];
    }
}
