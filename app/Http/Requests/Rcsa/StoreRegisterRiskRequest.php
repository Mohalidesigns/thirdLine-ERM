<?php

namespace App\Http\Requests\Rcsa;

use App\Models\Rcsa\RcsaRegisterControl;
use App\Models\Rcsa\RcsaRegisterRisk;
use App\Support\Rcsa\RcsaMethodologyTemplate as Template;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Add a risk to the RCSA Universe, with its controls (rcsa.universe.store).
 *
 * EVERY FOREIGN KEY IS TENANT-BOUND. `Rule::exists('business_units', 'id')`
 * alone would accept another organisation's unit id typed into the request,
 * and the row would be created pointing at it — the cross-tenant binding
 * defect this product has found in module after module. The `->where(
 * 'organization_id', ...)` clause is what makes the id mean "one of ours".
 */
class StoreRegisterRiskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', RcsaRegisterRisk::class);
    }

    /**
     * Drop control rows the user never filled in.
     *
     * The repeater always shows one empty row so there is something to type
     * into, and `controls.*.description` is required — so an untouched row
     * made every save fail with an error the user could only see by switching
     * to the Controls step. The panel now filters these out before sending;
     * this is the same rule on the server, so a client that forgets cannot
     * bring the form to a halt again.
     *
     * A row is blank only when EVERY field is empty. A row with a type or an
     * owner but no description is a half-filled control, and that is a
     * genuine error the user should be shown.
     */
    protected function prepareForValidation(): void
    {
        $controls = $this->input('controls');

        if (! is_array($controls)) {
            return;
        }

        $this->merge([
            'controls' => array_values(array_filter($controls, function ($control) {
                if (! is_array($control)) {
                    return false;
                }

                foreach (['description', 'control_type', 'frequency', 'control_owner_id'] as $field) {
                    if (filled($control[$field] ?? null)) {
                        return true;
                    }
                }

                return filled($control['id'] ?? null) || ($control['is_key'] ?? false);
            })),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $orgId = TenantContext::organizationId();
        $tenant = fn (string $table) => Rule::exists($table, 'id')->where('organization_id', $orgId);

        return [
            /* --- Placement, workbook columns B to E ---------------------- */
            'business_unit_id' => ['required', 'integer', $tenant('business_units')],
            'process_id' => ['nullable', 'integer', $tenant('business_processes')],
            'sub_process_id' => ['nullable', 'integer', $tenant('business_processes')],
            'system_ids' => ['nullable', 'array'],
            'system_ids.*' => ['integer', $tenant('rcsa_systems')],

            /* --- The risk, columns A and F to I -------------------------- */
            // Nullable: blank means "generate one". A number the user typed is
            // kept, and its uniqueness is enforced by the index, surfaced below.
            'risk_no' => ['nullable', 'string', 'max:40'],

            // 20 characters is §7.3's rule for the import, applied here too so
            // that a row typed into the screen and the same row uploaded are
            // held to one standard. "System failure" is not a risk statement.
            'potential_risk' => ['required', 'string', 'min:20', 'max:2000'],
            'risk_driver' => ['nullable', 'string', 'max:2000'],
            'risk_category' => ['required', Rule::in(Template::RISK_CATEGORIES)],
            'secondary_categories' => ['nullable', 'array', 'max:10'],
            'secondary_categories.*' => ['string', 'max:120'],

            'default_likelihood' => ['nullable', 'integer', 'min:1', 'max:5'],
            'default_impact' => ['nullable', 'integer', 'min:1', 'max:5'],
            'owner_id' => ['nullable', 'integer', $tenant('users')],

            /* --- Controls, column N -------------------------------------- */
            'controls' => ['nullable', 'array', 'max:20'],
            'controls.*.id' => ['nullable', 'integer'],
            'controls.*.description' => ['required', 'string', 'max:2000'],
            'controls.*.control_type' => ['nullable', Rule::in(RcsaRegisterControl::TYPES)],
            'controls.*.frequency' => ['nullable', Rule::in(RcsaRegisterControl::FREQUENCIES)],
            'controls.*.control_owner_id' => ['nullable', 'integer', $tenant('users')],
            'controls.*.is_key' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $orgId = TenantContext::organizationId();

            /* --- Column I is required when the category is Others --------- */
            if ($this->input('risk_category') === Template::CATEGORY_OTHERS
                && blank($this->input('secondary_categories'))) {
                $validator->errors()->add(
                    'secondary_categories',
                    'Name the applicable categories when the risk category is Others.'
                );
            }

            /* --- A sub-process must belong to its process ----------------- */
            // Without this, columns C and D can name two unrelated processes
            // and the export prints a hierarchy that does not exist. Both ids
            // are already known to be this tenant's by the rules above.
            $processId = $this->input('process_id');
            $subProcessId = $this->input('sub_process_id');

            if ($subProcessId !== null && $subProcessId !== '') {
                if (blank($processId)) {
                    $validator->errors()->add('sub_process_id', 'Choose a process before choosing a sub-process.');

                    return;
                }

                $belongs = \App\Models\BusinessProcess::query()
                    ->whereKey($subProcessId)
                    ->where('organization_id', $orgId)
                    ->where('parent_id', $processId)
                    ->exists();

                if (! $belongs) {
                    $validator->errors()->add('sub_process_id', 'That sub-process does not belong to the chosen process.');
                }
            }

            /* --- A typed risk number must be free in that unit ------------ */
            // Checked here rather than left to the unique index, so the user
            // gets a field error on the number they typed instead of a 500.
            $riskNo = $this->input('risk_no');

            if (filled($riskNo)) {
                $taken = RcsaRegisterRisk::withTrashed()
                    ->where('business_unit_id', $this->input('business_unit_id'))
                    ->where('risk_no', $riskNo)
                    ->when($this->route('risk'), fn ($q, $risk) => $q->whereKeyNot($risk->id ?? $risk))
                    ->exists();

                if ($taken) {
                    $validator->errors()->add('risk_no', 'That risk number is already used in this business unit.');
                }
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'potential_risk.min' => 'Describe the risk in at least 20 characters — enough for someone in another unit to understand it.',
            'risk_category.in' => 'Choose one of the approved risk categories.',
            'controls.*.description.required' => 'Every control needs a description, or remove the row.',
        ];
    }
}
