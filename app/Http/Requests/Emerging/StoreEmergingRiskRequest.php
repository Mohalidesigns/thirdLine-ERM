<?php

namespace App\Http\Requests\Emerging;

use App\Http\Requests\Concerns\ValidatesConfiguredAttributes;
use App\Models\EmergingRisk;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add an entry to the horizon (migration Phase 4.6).
 *
 * The mapped rules are carried across from EmergingRiskController::validated()
 * unchanged — including the tenant filters on `category_id` and `owner_id`,
 * which were already right here and are pinned by RiskIntelligenceGateTest.
 *
 * WHAT IS NEW IS `...$this->configuredAttributeRules('EmergingRisk')`, AND IT
 * FIXES AN ORPHAN. The controller used to create the record and only then call
 * saveConfiguredAttributes(), which validates on its own — so a tenant-added
 * required field left blank threw a ValidationException AFTER
 * `EmergingRisk::create()` had already run and consumed a reference number.
 * The user was shown "this field is required", filled it in, submitted again,
 * and the register had two entries. Validating both halves in one pass, before
 * anything is written, is the shape 4.4 established and the reason Form
 * Requests exist here at all.
 */
class StoreEmergingRiskRequest extends FormRequest
{
    use ValidatesConfiguredAttributes;

    public function authorize(): bool
    {
        return $this->user()->can('create', EmergingRisk::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $orgId = TenantContext::organizationId();

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            // The tenant filter matters: without it a user could attach their
            // emerging risk to another organisation's category or user by
            // posting a foreign id.
            'category_id' => ['nullable', 'integer', Rule::exists('risk_categories', 'id')->where('organization_id', $orgId)],
            'horizon' => ['required', Rule::in(EmergingRisk::HORIZONS)],
            'velocity_score' => ['required', 'integer', 'min:1', 'max:5'],
            'proximity_score' => ['required', 'integer', 'min:1', 'max:5'],
            'potential_impact' => ['required', Rule::in(EmergingRisk::IMPACTS)],
            'status' => ['required', Rule::in(EmergingRisk::STATUSES)],
            'source' => ['nullable', 'string', 'max:160'],
            'source_reference' => ['nullable', 'string', 'max:2000'],
            'detected_at' => ['nullable', 'date'],
            'last_reviewed_at' => ['nullable', 'date'],
            'potential_response' => ['nullable', 'string', 'max:5000'],
            'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            ...$this->configuredAttributeRules('EmergingRisk'),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return $this->configuredAttributeLabels('EmergingRisk');
    }

    /**
     * Just the columns, for the model write. The configured attributes travel
     * separately, under their own key, and are stored by
     * PersistsConfiguredAttributes once the record exists.
     *
     * @return array<string, mixed>
     */
    public function columns(): array
    {
        return $this->safe()->except('configured_attributes');
    }
}
