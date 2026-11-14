<?php

namespace App\Models\Sm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * Scenario Modelling & Analysis Phase 2 — COSO Control Scorecard Component (ADR 0027 Amendment 2 §A2.2).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $sm_scorecard_id
 * @property string $component_code
 * @property int $efficiency_level
 * @property int $weighting_factor
 * @property int $score
 * @property string $level_score
 * @property string $adjusted_score
 * @property int $weighting_score
 * @property string $weighted_score
 * @property string|null $notes
 * @property int $sort_order
 */
class ScorecardComponent extends Model
{
    use BelongsToOrganization;

    protected $table = 'sm_scorecard_components';

    public const COMPONENTS = [
        'control_environment' => 'I. Control environment',
        'risk_assessment' => 'II. Risk assessment',
        'control_activities' => 'III. Control activities',
        'information_communication' => 'IV. Information and Communication',
        'monitoring' => 'V. Monitoring',
    ];

    public const LEVEL_SCORES = [
        0 => 0.00,
        1 => 0.20,
        2 => 0.40,
        3 => 0.55,
        4 => 0.70,
        5 => 0.90,
    ];

    protected $fillable = [
        'organization_id',
        'sm_scorecard_id',
        'component_code',
        'efficiency_level',
        'weighting_factor',
        'score',
        'level_score',
        'adjusted_score',
        'weighting_score',
        'weighted_score',
        'notes',
        'sort_order',
    ];

    protected $casts = [
        'efficiency_level' => 'integer',
        'weighting_factor' => 'integer',
        'score' => 'integer',
        'level_score' => 'decimal:2',
        'adjusted_score' => 'decimal:2',
        'weighting_score' => 'integer',
        'weighted_score' => 'decimal:4',
        'sort_order' => 'integer',
    ];

    public function scorecard(): BelongsTo
    {
        return $this->belongsTo(Scorecard::class, 'sm_scorecard_id');
    }
}
