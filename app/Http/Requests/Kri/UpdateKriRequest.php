<?php

namespace App\Http\Requests\Kri;

use App\Http\Requests\Concerns\ValidatesConfiguredAttributes;
use App\Models\KeyRiskIndicator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update a KRI (migration Phase 4.1).
 *
 * `risk_id` is absent, exactly as it was: update() never accepted it, so
 * offering it would be an editable field that never saves.
 */
class UpdateKriRequest extends FormRequest
{
    use ValidatesConfiguredAttributes;

    public function authorize(): bool
    {
        $kri = $this->route('kri');

        return $kri instanceof KeyRiskIndicator && $this->user()->can('update', $kri);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $orgId = TenantContext::organizationId();

        return [
            'kri_name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'measurement_unit' => ['required', 'string', 'max:100'],
            'measurement_frequency' => ['required', Rule::in(KeyRiskIndicator::FREQUENCIES)],
            'data_source' => ['nullable', 'string', 'max:255'],
            'kri_owner_id' => ['required', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'green_threshold' => ['nullable', 'numeric'],
            'red_threshold' => ['nullable', 'numeric'],
            'direction' => ['required', Rule::in(KeyRiskIndicator::DIRECTIONS)],
            'target_value' => ['nullable', 'numeric'],
            'is_active' => ['nullable', 'boolean'],
            'formula' => ['nullable', 'string', 'max:1000'],
            ...$this->configuredAttributeRules('KeyRiskIndicator'),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return $this->configuredAttributeLabels('KeyRiskIndicator');
    }
}
