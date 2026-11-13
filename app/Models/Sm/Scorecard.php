<?php

namespace App\Models\Sm;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * Scenario Modelling & Analysis Phase 2 — COSO Control Scorecard (ADR 0027 Amendment 2 §A2.2).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $sm_scenario_id
 * @property string $control_nature
 * @property int|null $total_weight
 * @property string|null $total_weighted_score
 * @property string|null $global_score
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class Scorecard extends Model
{
    use BelongsToOrganization;

    protected $table = 'sm_scorecards';

    public const CONTROL_NATURES = [
        'mainly_manual' => [
            'label' => '1. Mainly manual controls',
            'deduction' => 0.10,
        ],
        'mixed' => [
            'label' => '2. Mix of manual and automated controls',
            'deduction' => 0.05,
        ],
        'mainly_automated' => [
            'label' => '3. Mainly automated controls',
            'deduction' => 0.00,
        ],
    ];

    protected $fillable = [
        'organization_id',
        'sm_scenario_id',
        'control_nature',
        'total_weight',
        'total_weighted_score',
        'global_score',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'total_weight' => 'integer',
        'total_weighted_score' => 'decimal:4',
        'global_score' => 'decimal:8',
    ];

    public function scenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class, 'sm_scenario_id');
    }

    public function components(): HasMany
    {
        return $this->hasMany(ScorecardComponent::class, 'sm_scorecard_id')->orderBy('sort_order');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
