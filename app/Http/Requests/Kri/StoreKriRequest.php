<?php

namespace App\Http\Requests\Kri;

use App\Http\Requests\Concerns\ValidatesConfiguredAttributes;
use App\Models\KeyRiskIndicator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a KRI (migration Phase 4.1).
 *
 * `risk_id` and `kri_owner_id` were `exists:risks,id` / `exists:users,id`,
 * which accept ANOTHER TENANT'S id; both are tenant-bound here.
 *
 * THE `amber_threshold` FIELD IS GONE, and its absence is the point. A band set
 * needs two numbers, not three: on a higher-is-worse indicator green is
 * everything up to the green boundary, red is everything from the red boundary,
 * and amber is what lies between — it has no boundary of its own. The old form
 * collected an amber number, the controller assigned it to nothing
 * (`$amber = ...` was read and never used in either direction branch), and
 * KeyRiskIndicator::getAmberThresholdAttribute() has always returned the RED
 * edge, so the form redisplayed red's value in the amber box. Three inputs, two
 * meanings, one of them discarded.
 */
class StoreKriRequest extends FormRequest
{
    use ValidatesConfiguredAttributes;

    public function authorize(): bool
    {
        return $this->user()->can('create', KeyRiskIndicator::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $orgId = TenantContext::organizationId();

        return [
            'risk_id' => ['required', Rule::exists('risks', 'id')->where('organization_id', $orgId)],
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
