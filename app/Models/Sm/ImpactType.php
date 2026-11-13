<?php

namespace App\Models\Sm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * Scenario Modelling & Analysis Phase 2 — Impact Category / Type (ADR 0027 Amendment 2 §A2.4).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $sm_scenario_id
 * @property string $name
 * @property string $aggregation_template
 * @property bool $eligible_to_transfer
 * @property int $sort_order
 * @property string $typical_total
 * @property string $serious_total
 * @property string $extreme_total
 */
class ImpactType extends Model
{
    use BelongsToOrganization;

    protected $table = 'sm_impact_types';

    public const TEMPLATES = [
        'staff_turnover_interval' => [
            'label' => 'Staff Turnover (Count × Salary × Duration × Interval)',
            'description' => 'Multiplies head count, monthly salary, duration in months, and frequency interval factor.',
        ],
        'litigation_probability' => [
            'label' => 'Litigation / Legal Action ((Count × Unit Amount + Fees) × Probability)',
            'description' => 'Sums variable and flat legal costs, scaled by probability of litigation per severity.',
        ],
    ];

    protected $fillable = [
        'organization_id',
        'sm_scenario_id',
        'name',
        'aggregation_template',
        'eligible_to_transfer',
        'sort_order',
        'typical_total',
        'serious_total',
        'extreme_total',
    ];

    protected $casts = [
        'eligible_to_transfer' => 'boolean',
        'sort_order' => 'integer',
        'typical_total' => 'decimal:8',
        'serious_total' => 'decimal:8',
        'extreme_total' => 'decimal:8',
    ];

    public function scenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class, 'sm_scenario_id');
    }

    public function criteria(): HasMany
    {
        return $this->hasMany(ImpactCriterion::class, 'sm_impact_type_id')->orderBy('sort_order');
    }
}
