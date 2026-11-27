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

    /**
     * `count_amount_duration` is the per-event variant ADR 0027 D1's consequences reserve for
     * (Amendment 4): severity is the loss of ONE event, frequency is applied separately.
     * `staff_turnover_interval` stays the workbook's formula, × 1/λ included, so scenarios
     * built on it keep their figures; its label now says it carries frequency.
     */
    public const TEMPLATES = [
        'count_amount_duration' => [
            'label' => 'Per-event cost (Count × Amount × Duration)',
            'description' => 'Loss of a single event: count × unit amount × duration, per severity column. Frequency is not applied.',
        ],
        'staff_turnover_interval' => [
            'label' => 'Workbook staff turnover (Count × Salary × Months × Frequency interval 1/λ)',
            'description' => 'The original workbook formula. Multiplies by the frequency interval (1/λ), so the result is not a per-event loss and changes whenever frequency changes.',
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
