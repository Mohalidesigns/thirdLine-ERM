<?php

namespace App\Http\Requests\Admin;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The scoring matrix (migration Phase 6.2).
 *
 * Kept as its own request because saving it does more than store settings: it
 * resizes the organisation's default ScoringProfile, which re-rates the whole
 * register. WP-05 made that write-through; before it, a user could set a 4×4
 * matrix, see a success message, and watch every screen carry on rendering
 * five columns.
 *
 * The bounds are the ones WP-05 argued for: a matrix below 3×3 cannot
 * distinguish anything and one above 10×10 is unreadable.
 */
class RiskSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('updateSettings', $this->organization());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'scoring_methodology' => ['required', 'string', 'max:100'],
            'probability_scale' => ['required', 'integer', 'min:3', 'max:10'],
            'impact_scale' => ['required', 'integer', 'min:3', 'max:10'],
            'calculation_method' => ['required', 'string', 'in:max,weighted,average,worst_two'],
            'review_frequency' => ['required', 'string', 'max:100'],
        ];
    }

    public function organization(): Organization
    {
        return Organization::findOrFail(TenantContext::organizationId());
    }
}
