<?php

namespace App\Http\Requests\Tprm;

use App\Enums\Tprm\EngagementType;
use App\Models\Tprm\Engagement;
use App\Support\Tprm\DefaultRuleset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Raising an intake — FR-INT-01, and the inherent questionnaire of Appendix A
 * inline with it (FR-INT-02).
 *
 * THE PROHIBITED-FUNCTION RULE IS NOT HERE. It looks like validation and it is
 * not: AC-01 requires that no engagement row is created, and a rule in this
 * class only guards this one HTTP route. `IntakeService` enforces it inside
 * the transaction, where the bulk importer and the API reach it too. What this
 * class does is make sure the function ids are real and belong to the tenant.
 *
 * THE ANSWER OPTIONS ARE VALIDATED AGAINST THE RULESET THAT WILL SCORE THEM.
 * Standard §10 is explicit that a test which POSTs a route is not a test of the
 * form in front of it, and that what a schema OFFERS must be a subset of what
 * the validator ACCEPTS. Here the two are the same list by construction: the
 * options come from `DefaultRuleset`, which is also what the calculator reads,
 * so an answer that validates cannot then score zero as "unrecognised".
 */
class SubmitIntakeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Engagement::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = TenantContext::organizationIdOrNull();

        return [
            'third_party_id' => [
                'required',
                Rule::exists('tp_third_parties', 'id')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at'),
            ],

            'name' => ['required', 'string', 'max:255'],
            'service_description' => ['nullable', 'string', 'max:5000'],
            'engagement_type' => ['required', Rule::in(EngagementType::values())],

            'service_type_id' => [
                'nullable',
                Rule::exists('tp_categories', 'id')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at'),
            ],

            'business_unit_id' => [
                'nullable',
                Rule::exists('business_units', 'id')->where('organization_id', $organizationId),
            ],

            'relationship_owner_id' => ['nullable', $this->userInTenant($organizationId)],
            'executive_sponsor_id' => ['nullable', $this->userInTenant($organizationId)],

            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after:start_date'],

            // Money in minor units, per the product's rule, with a currency
            // code alongside it.
            'annual_spend_minor' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],

            // FR-INT-01: the business functions the service supports. At least
            // one, because the CRIT factor and three knockouts read them, and
            // an engagement supporting nothing cannot be tiered meaningfully.
            'business_function_ids' => ['required', 'array', 'min:1'],
            'business_function_ids.*' => [
                Rule::exists('tp_business_functions', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],

            /* --- Appendix A ------------------------------------------- */

            'answers' => ['required', 'array'],
            'answers.A1' => ['required', $this->optionOf('DATA')],
            'answers.A2' => ['required', Rule::in(array_keys(DefaultRuleset::dataVolumeBands()))],
            'answers.A3' => ['required', $this->optionOf('GEO')],
            'answers.A4' => ['nullable', 'string', 'size:2'],
            'answers.A5' => ['required', $this->optionOf('ACCESS')],
            'answers.A6' => ['nullable', 'string', 'max:60'],
            'answers.A8' => ['required', Rule::in(array_keys(DefaultRuleset::rtoBands()))],
            'answers.A9' => ['nullable', Rule::in(['no', 'indirectly', 'directly'])],
            'answers.A10' => ['array'],
            'answers.A10.*' => [$this->optionOf('REG')],
            'answers.A11' => ['boolean'],
            'answers.A12' => ['required', $this->optionOf('SUB')],
            'answers.A13' => ['required', Rule::in(['under_1m', '1m_3m', '3m_6m', 'over_6m'])],
            'answers.A14' => ['required', $this->optionOf('FIN')],
            'answers.A15' => ['nullable', Rule::in(['no', 'disclosed', 'undisclosed', 'unknown'])],
            'answers.A16' => ['nullable', Rule::in(['no', 'escorted', 'unescorted'])],
            'answers.A17' => ['boolean'],
            'answers.A18' => ['boolean'],

            /* --- Engagement attributes the knockouts read -------------- */

            'processes_personal_data' => ['boolean'],
            'cross_border' => ['boolean'],
            'transfer_basis' => ['nullable', Rule::in(Engagement::TRANSFER_BASES)],
            'pci_in_scope' => ['boolean'],
            'cloud_model' => ['nullable', Rule::in(Engagement::CLOUD_MODELS)],
            'substitutability' => ['nullable', Rule::in(Engagement::SUBSTITUTABILITY)],
            'time_to_replace_months' => ['nullable', 'integer', 'min:0', 'max:120'],
        ];
    }

    /**
     * The valid option values for a factor, taken from the ruleset that will
     * score the answer — so the form, the validator and the calculator share
     * one list rather than three copies that drift.
     */
    private function optionOf(string $factorCode): \Illuminate\Validation\Rules\In
    {
        $options = DefaultRuleset::factors()[$factorCode]['options'] ?? [];

        return Rule::in(array_column($options, 'value'));
    }

    private function userInTenant(?int $organizationId): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists('users', 'id')
            ->where('organization_id', $organizationId)
            ->where('is_active', true);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'business_function_ids.required' => 'Name at least one business function this service supports — '
                .'the tier cannot be computed without it.',
            'answers.A1.required' => 'The highest data classification is required.',
            'answers.A5.required' => 'The level of system access is required.',
        ];
    }
}
