<?php

namespace App\Models\Sm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * Scenario Modelling & Analysis Phase 2 — Impact Criterion Row (ADR 0027 Amendment 2 §A2.4).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $sm_impact_type_id
 * @property string $label
 * @property string|null $unit
 * @property string $computation_role
 * @property string|null $data_source
 * @property string $typical_value
 * @property string $serious_value
 * @property string $extreme_value
 * @property int $sort_order
 */
class ImpactCriterion extends Model
{
    use BelongsToOrganization;

    protected $table = 'sm_impact_criteria';

    public const COMPUTATION_ROLES = [
        'count' => 'Count / Headcount',
        'unit_amount' => 'Unit Amount / Salary / Rate',
        'duration' => 'Duration (Months / Weeks)',
        'probability' => 'Probability of Loss Event (0.0 to 1.0)',
        'flat_fee' => 'Flat Fee / Legal & Court Expenses',
    ];

    protected $fillable = [
        'organization_id',
        'sm_impact_type_id',
        'label',
        'unit',
        'computation_role',
        'data_source',
        'typical_value',
        'serious_value',
        'extreme_value',
        'sort_order',
    ];

    protected $casts = [
        'typical_value' => 'decimal:8',
        'serious_value' => 'decimal:8',
        'extreme_value' => 'decimal:8',
        'sort_order' => 'integer',
    ];

    public function impactType(): BelongsTo
    {
        return $this->belongsTo(ImpactType::class, 'sm_impact_type_id');
    }
}
