<?php

namespace App\Http\Requests\Rcsa;

use App\Models\Rcsa\RcsaRegisterRisk;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Create a process or sub-process without leaving the Add Risk panel
 * (rcsa.universe.processes.store).
 *
 * §6.2 requires inline creation: a risk champion entering sixty risks must not
 * have to abandon a half-filled form, navigate to process administration,
 * create one, and come back. This writes to `business_processes`, the
 * product's own process inventory, so a process created here is the same row
 * the risk register and the legacy RCSA screens see — that is the whole
 * argument for not having built a separate `rcsa_processes` table.
 *
 * IT IS GATED ON `rcsa_universe.create`, NOT on a process-administration
 * permission, because there is no such permission in this product: processes
 * have never had their own CRUD screen. Anyone who may add a universe risk may
 * name the process it sits in.
 */
class StoreUniverseProcessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', RcsaRegisterRisk::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $orgId = TenantContext::organizationId();

        return [
            'business_unit_id' => ['required', 'integer', Rule::exists('business_units', 'id')->where('organization_id', $orgId)],
            'name' => ['required', 'string', 'max:200'],
            // Present = create a sub-process under this parent.
            'parent_id' => ['nullable', 'integer', Rule::exists('business_processes', 'id')->where('organization_id', $orgId)],
        ];
    }
}
