<?php

namespace App\Http\Requests\Sm;

use App\Models\Sm\Scenario;
use App\Services\Sm\OpEventTypeCatalogue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The create form — FOUR fields and then out of the way (spec 04 §1).
 *
 * `authorize()` asserts what actually guards the route (standard §3): the route
 * carries `permission:scenario.create`, so that is what is asked here. A route
 * rewritten without the middleware fails in this file rather than opening quietly.
 *
 * WHY ONLY FOUR. Spec 04 §1: everything else the module collects lives on the
 * Overview & Governance tab, and a create form that duplicated it would be a second
 * copy of fifteen fields with their own requiredness rules, option lists and
 * messages — the two would drift, and the drift would be invisible until a user's
 * answers disagreed depending on which door they came through.
 *
 * ONLY `name` IS REQUIRED. §A1.2 makes it NOT NULL with no default; every other
 * column is nullable or carries a default, and spec 04 §3 orders the fields required
 * first so "a user in a hurry fills it and presses Create without discovering they
 * missed something". `business_unit_id` and `nature` are deliberately NOT required
 * even though an earlier draft of that spec treated the owning unit as mandatory:
 * §A1.8's frozen `in_design → designed` guard does not list them, and "blocking
 * creation on it would enforce a rule the server does not have".
 *
 * NO REFERENCE, PROVISIONAL OR OTHERWISE. Addendum-2 and spec 04 §4: the sequence is
 * `max + 1`, read at write time, so any number shown before the write is a guess —
 * two designers creating at once are both shown the same provisional reference and one
 * of them is saved with a different one. The screen would have displayed a reference
 * that never existed, which is standard §5's defect class in the one field a user is
 * most likely to copy into an email before saving. `prohibited` makes that a 422
 * rather than a silently discarded value, so a stale tab is told the truth.
 */
class StoreScenarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('scenario.create') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $organizationId = TenantContext::organizationId();

        return [
            'name' => ['required', 'string', 'max:250'],

            // §A1.3: the owning unit is `business_units`, NOT `entities` —
            // `entity_id` was refused because ADR 0026 decision 0.5 settled that
            // business_units are authoritative, and a second org FK nothing writes
            // beside the authoritative one is the KRI defect one ADR later.
            // Nullable: spec 04 §5 says Create is NOT blocked when a tenant has no
            // units, because §A1.2 row 10 makes the column nullable.
            'business_unit_id' => [
                'nullable', 'integer',
                Rule::exists('business_units', 'id')->where('organization_id', $organizationId),
            ],

            // Defaults to `local` — the narrowest claim (§A1.2), and spec 04 §2
            // sends it pre-set so the form opens on a real value rather than a
            // "Choose…" the user has to clear.
            'nature' => ['nullable', Rule::in(array_keys(Scenario::NATURES))],

            // Restricted to the Basel III framework: spec 02 §5.4's failure message
            // is "not a Basel III node in this tenant", and the picker is fed from
            // the tenant's OP tree (§A1.9) rather than from a constant, so a tenant's
            // CBN ORMS extensions pass and an unrelated ISO 31000 node does not.
            'risk_taxonomy_node_id' => [
                'nullable', 'integer',
                Rule::exists('risk_taxonomies', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('framework', OpEventTypeCatalogue::FRAMEWORK),
            ],

            // Server-generated on save only (addendum-2). See the class docblock.
            'reference' => ['prohibited'],
        ];
    }

    /**
     * Verbatim from spec 04 §6.1, which reuses spec 02 §5's copy so this page and the
     * workspace cannot disagree. Standard §4 puts validation in Form Requests, and a
     * message that exists only in JSX is a message a non-JS 422 or a stale tab will
     * not carry.
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

            'nature.in' => "That isn't a valid scenario nature.",

            'risk_taxonomy_node_id.exists' => "That event type isn't in your organisation's taxonomy.",
            'risk_taxonomy_node_id.integer' => "That event type isn't in your organisation's taxonomy.",

            'reference.prohibited' => 'The reference is assigned when you save.',
        ];
    }

    /**
     * Only the four fields, and `nature` filled in when the client omitted it.
     *
     * @return array<string, mixed>
     */
    public function scenarioAttributes(): array
    {
        $validated = $this->validated();

        return [
            'name' => trim((string) $validated['name']),
            'business_unit_id' => $validated['business_unit_id'] ?? null,
            'nature' => $validated['nature'] ?? Scenario::DEFAULT_NATURE,
            'risk_taxonomy_node_id' => $validated['risk_taxonomy_node_id'] ?? null,
        ];
    }
}
