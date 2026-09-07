<?php

namespace App\Http\Requests\Rcsa;

use App\Models\Rcsa\RcsaRegisterRisk;
use App\Support\Rcsa\RcsaMethodologyTemplate as Template;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Reassign owner, category or status across selected rows
 * (rcsa.universe.bulk-update).
 *
 * THREE FIELDS, NAMED. Not "the attributes the client sent": a bulk endpoint
 * that forwards an arbitrary attribute bag is how a mis-typed key ends up
 * rewriting sixty risk statements at once, with no per-row confirmation and
 * nothing on screen to show it happened. The service applies the same
 * allow-list independently, so neither layer is the only guard.
 *
 * Authorised as `update` on the MODEL CLASS, with each row's own policy check
 * left to the controller — a class-level ability cannot see which rows were
 * selected, and asking it to would make "may I bulk edit" mean "may I edit
 * everything".
 */
class BulkUpdateRegisterRisksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('rcsa_universe.update');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $orgId = TenantContext::organizationId();

        return [
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', Rule::exists('rcsa_register_risks', 'id')->where('organization_id', $orgId)],

            'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'risk_category' => ['nullable', Rule::in(Template::RISK_CATEGORIES)],
            'status' => ['nullable', Rule::in(RcsaRegisterRisk::STATUSES)],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (blank($this->input('owner_id')) && blank($this->input('risk_category')) && blank($this->input('status'))) {
                $validator->errors()->add('owner_id', 'Choose at least one thing to change.');
            }
        });
    }
}
