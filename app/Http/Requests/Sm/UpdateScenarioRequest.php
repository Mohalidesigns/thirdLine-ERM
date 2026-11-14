<?php

namespace App\Http\Requests\Sm;

use App\Models\Sm\Scenario;
use App\Services\Sm\OpEventTypeCatalogue;
use App\Support\Measures\UnitRegistry;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The PARTIAL update both workspace tabs post to `PUT {scenario}` (spec 01 §6.1,
 * spec 02 §8, spec 03 §5.6).
 *
 * ── WHY "PARTIAL" IS LOAD-BEARING ─────────────────────────────────────────────
 *
 * BOTH TABS POST TO THIS ROUTE and neither loads the other's fields — spec 01 §4
 * sends only the active tab's data, because the Qualitative payload is the largest
 * thing on the page and a user landing on Overview at 100 kbps should not pay for it.
 * A whole-object write from the Qualitative tab would therefore null `background`,
 * `rationale`, `name` and every governance field — silently, on save, with a 200
 * response. That is standard §6's write-path rule in its most dangerous form: the
 * columns exist, the write "succeeds", and the data is gone.
 *
 * So every rule carries `sometimes`, and only keys actually present in the request
 * reach `validated()` and therefore the model. `ScenarioService::update()` writes
 * exactly that array and nothing else. Spec 01 §6.1 asks for a test that a
 * Qualitative-shaped partial save leaves `background` untouched; that test is
 * `tests/Feature/Sm/PartialUpdateTest.php`.
 *
 * ── WHAT IS DELIBERATELY NOT A RULE HERE ──────────────────────────────────────
 *
 * `reference`, `status` and `validated_on` have NO rule, so they are excluded from
 * `validated()` and can never be written through this route (and
 * `ScenarioService::update()` unsets them again as a backstop). They are not
 * `prohibited`, because spec 02 §8 puts `validated_on` in the Overview tab's `values`
 * as read-only context and a front end echoing its own state back would then 422 on a
 * field it was told to render. Being unreadable is the requirement; being refused with
 * an error is not.
 *
 * The LIFECYCLE is not checked here either. "Only an editable scenario can be updated"
 * is a lifecycle rule, and standard §3 keeps those in the controller answering with a
 * FLASH rather than a 403 — `ScenarioService::refuseIfReadOnly()`.
 */
class UpdateScenarioRequest extends FormRequest
{
    /** Spec 02 §5.8: both narrative fields are `maxlength` 4000. */
    public const NARRATIVE_MAX = 4000;

    public function authorize(): bool
    {
        // Asserts what actually guards the route (standard §3): `permission:scenario.update`
        // on the route, and the policy's own order is permission → tenancy.
        return $this->user()?->can('update', $this->scenario()) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $organizationId = TenantContext::organizationId();

        return [
            /* ---------------- Overview & Governance (spec 02 §8) ---------------- */

            'name' => ['sometimes', 'required', 'string', 'max:250'],

            // §A1.3: `business_units`, NOT `entities`. Tenant-bound per standard §4 —
            // a bare `exists:business_units,id` is an existence oracle that answers,
            // one id at a time, a question the caller is not entitled to ask.
            'business_unit_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('business_units', 'id')->where('organization_id', $organizationId),
            ],

            'risk_taxonomy_node_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('risk_taxonomies', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('framework', OpEventTypeCatalogue::FRAMEWORK),
            ],

            'nature' => ['sometimes', 'nullable', Rule::in(array_keys(Scenario::NATURES))],
            'weighting' => ['sometimes', 'nullable', Rule::in(array_keys(Scenario::WEIGHTINGS))],

            // char(3) NOT NULL (§A1.2 row 12), so `required` when present and never
            // nullable: "every figure here is money and a currency-less scenario
            // cannot be converted". The list is the product's own unit registry, not
            // an invented one.
            'currency' => ['sometimes', 'required', 'string', 'size:3', Rule::in(self::currencyCodes())],

            // Provenance. `fx_rate` and `fx_rate_date` are NOT posted: §A1.2 makes
            // them a SNAPSHOT the server copies off the chosen `fx_rates` row, so a
            // signed-off figure never moves when the rate table does and a client
            // cannot invent a rate (spec 02 §5.6: "Picking a rate on this screen
            // writes all three").
            'fx_rate_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('fx_rates', 'id')->where('organization_id', $organizationId),
            ],

            'risk_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('risks', 'id')->where('organization_id', $organizationId),
            ],

            // Length is checked in after() rather than by `max:` so the message can
            // carry the exact over-count spec 02 §5.8 specifies.
            'background' => ['sometimes', 'nullable', 'string'],
            'rationale' => ['sometimes', 'nullable', 'string'],

            // `_on` columns are Lagos calendar DAYS (ADR 0026), posted as Y-m-d and
            // stored as `date`. `date_format` rather than `date` so "09/11/2026" is
            // refused rather than silently reinterpreted — the two engines and the
            // browser do not agree on what that string means.
            'designed_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'review_due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],

            /* ---------------- Qualitative exposures (spec 03 §2) ---------------- */

            // NULLABLE and three-state: the unset option posts null and must survive
            // as null, because `no_impact` / `no` are ANSWERS and collapsing the two
            // would render "not yet assessed" as a clean bill of health (§A1.2,
            // standard §5, spec 03 §6.1).
            'reputational_exposure' => ['sometimes', 'nullable', Rule::in(array_keys(Scenario::REPUTATIONAL_EXPOSURES))],
            'legal_compliance_exposure' => ['sometimes', 'nullable', Rule::in(array_keys(Scenario::LEGAL_COMPLIANCE_EXPOSURES))],

            'insurance_risk' => ['sometimes', 'nullable', 'boolean'],
            'financial_risk' => ['sometimes', 'nullable', 'boolean'],

            // TWO-STATE, not three: §A1.2 row 25 froze this one NOT NULL default
            // `false` because its workbook dropdown is only 0/1 and Phase 2's netting
            // (`transferable = Σ type_total × eligible_flag`) needs a value — NULL
            // would be a third case in a two-case formula. Spec 03 §6.4 (patched)
            // overrules the earlier three-state radio on exactly those grounds.
            'eligible_to_transfer' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The two cross-field rules a declarative rule list cannot express.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $this->checkNarrativeLength($validator, 'background');
                $this->checkNarrativeLength($validator, 'rationale');
                $this->checkReviewAfterDesign($validator);
            },
        ];
    }

    /**
     * Verbatim from spec 02 §5 and spec 03 §7. Standard §4 puts validation in Form
     * Requests, and "a message that exists only in JSX is a message a non-JS 422 or a
     * stale tab will not carry" — the UI renders whatever the server sends.
     *
     * The keys are the FROZEN COLUMN SPELLINGS (§A1.2, addendum-4): `designed_on` /
     * `validated_on` / `review_due_on`, `background`, `weighting`,
     * `business_unit_id`, `reputational_exposure`, `legal_compliance_exposure` — with
     * the presenter doing no renaming, so a 422 key, a `gate.missing[].field` and a
     * form field name are one vocabulary across three surfaces.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Give the scenario a name.',
            'name.max' => 'Keep the name to 250 characters or fewer.',
            'name.string' => 'Give the scenario a name.',

            'business_unit_id.exists' => "That business unit isn't in your organisation.",
            'business_unit_id.integer' => "That business unit isn't in your organisation.",

            'risk_taxonomy_node_id.exists' => "That event type isn't in your organisation's taxonomy.",
            'risk_taxonomy_node_id.integer' => "That event type isn't in your organisation's taxonomy.",

            'nature.in' => "That isn't a valid scenario nature.",
            'weighting.in' => "That isn't a valid weighting factor.",

            'currency.required' => 'Choose the currency this scenario is measured in.',
            'currency.in' => 'Choose the currency this scenario is measured in.',
            'currency.size' => 'Choose the currency this scenario is measured in.',

            'fx_rate_id.exists' => "That exchange rate isn't available to your organisation.",
            'fx_rate_id.integer' => "That exchange rate isn't available to your organisation.",

            'risk_id.exists' => "That risk isn't in your organisation's register.",
            'risk_id.integer' => "That risk isn't in your organisation's register.",

            'designed_on.date_format' => 'Enter a real date.',
            'review_due_on.date_format' => 'Enter a real date.',

            'reputational_exposure.in' => "That isn't a value on this scale.",
            'legal_compliance_exposure.in' => "That isn't a value on this scale.",

            'insurance_risk.boolean' => 'Choose whether this scenario carries insurance risk.',
            'financial_risk.boolean' => 'Choose whether this scenario carries financial or market risk.',
            'eligible_to_transfer.boolean' => 'Eligible to transfer must be Yes or No.',
        ];
    }

    /**
     * The scenario this request writes, from the route binding.
     *
     * Bound by `id` and scoped through `BelongsToOrganization`, with `ResolveTenant`
     * running before `SubstituteBindings` (§A1.11) — so another tenant's id 404s
     * rather than resolving, and there is no existence oracle to answer.
     */
    public function scenario(): Scenario
    {
        return $this->route('scenario');
    }

    /**
     * Only the keys actually posted, so the write stays partial.
     *
     * @return array<string, mixed>
     */
    public function scenarioAttributes(): array
    {
        $validated = $this->validated();

        foreach (['name', 'background', 'rationale'] as $field) {
            if (isset($validated[$field]) && is_string($validated[$field])) {
                $validated[$field] = trim($validated[$field]);
            }
        }

        return $validated;
    }

    /**
     * The currency codes the product knows, from `UnitRegistry` — real currencies
     * only, not sub-units like kobo and cent, which are factors of one and cannot be
     * a scenario's reporting currency.
     *
     * @return list<string>
     */
    public static function currencyCodes(): array
    {
        return array_values(array_map(
            fn (array $unit) => (string) $unit['code'],
            array_filter(
                UnitRegistry::all(),
                fn (array $unit) => ($unit['category'] ?? null) === 'currency' && ($unit['base'] ?? null) === null
            )
        ));
    }

    /**
     * Spec 02 §5.8: "Keep this to 4 000 characters or fewer — {n} over."
     *
     * The count is in the message, which a declarative `max:` rule cannot produce —
     * hence `after()` rather than a rule. `mb_strlen` because the field is prose and
     * a Nigerian bank's narrative is not ASCII.
     */
    private function checkNarrativeLength(Validator $validator, string $field): void
    {
        if (! $this->has($field)) {
            return;
        }

        $value = $this->input($field);

        if (! is_string($value)) {
            return;
        }

        $length = mb_strlen($value);

        if ($length <= self::NARRATIVE_MAX) {
            return;
        }

        $validator->errors()->forget($field);
        $validator->errors()->add(
            $field,
            sprintf('Keep this to %s characters or fewer — %s over.', number_format(self::NARRATIVE_MAX), number_format($length - self::NARRATIVE_MAX))
        );
    }

    /**
     * Spec 02 §5.9: "The next review can't be before the design date."
     *
     * Compared DAY TO DAY as `Y-m-d` strings, never through `->isPast()` or a bare
     * `now()` — standard §14. `designed_on` may not be in this request (the tabs post
     * separately), so the stored value is the fallback.
     */
    private function checkReviewAfterDesign(Validator $validator): void
    {
        if (! $this->has('review_due_on')) {
            return;
        }

        $reviewDueOn = $this->input('review_due_on');

        if (! is_string($reviewDueOn) || $reviewDueOn === '') {
            return;
        }

        $designedOn = $this->has('designed_on') && is_string($this->input('designed_on')) && $this->input('designed_on') !== ''
            ? $this->input('designed_on')
            : optional($this->scenario()->designed_on)->toDateString();

        if ($designedOn === null || $designedOn === '') {
            return;
        }

        if (strcmp($reviewDueOn, (string) $designedOn) < 0) {
            $validator->errors()->forget('review_due_on');
            $validator->errors()->add('review_due_on', "The next review can't be before the design date.");
        }
    }
}
