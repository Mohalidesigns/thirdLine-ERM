<?php

namespace App\Models\Sm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * Scenario Modelling & Analysis Phase 2 — Frequency Driver (ADR 0027 Amendment 2 §A2.3).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $sm_scenario_id
 * @property string $driver_type
 * @property string $criterion
 * @property string $computation_role
 * @property string $value
 * @property string $data_source
 * @property int|null $sm_scorecard_id
 * @property int $sort_order
 */
class FrequencyDriver extends Model
{
    use BelongsToOrganization;

    protected $table = 'sm_frequency_drivers';

    public const COMPUTATION_ROLES = [
        'count' => 'Count / Exposed Units',
        'control_score_complement' => 'Control Efficiency (1 - Score)',
        'probability' => 'Event / Factor Probability',
    ];

    public const DEFAULT_DATA_SOURCES = [
        'hr_information' => 'HR information',
        'scorecard' => 'Scorecard',
        'expert_judgement' => 'Expert judgement',
        'internal_data' => 'Internal loss data',
        'external_data' => 'External data',
    ];

    protected $fillable = [
        'organization_id',
        'sm_scenario_id',
        'driver_type',
        'criterion',
        'computation_role',
        'value',
        'data_source',
        'sm_scorecard_id',
        'sort_order',
    ];

    protected $casts = [
        'value' => 'decimal:8',
        'sort_order' => 'integer',
    ];

    public function scenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class, 'sm_scenario_id');
    }

    /**
     * Whether this control driver takes its efficiency from the scenario's scorecard rather
     * than its own manual value. A link is stored as `sm_scorecard_id`; before a scorecard
     * exists the only record of the choice is `data_source = 'Scorecard'`, which the
     * workspace's link toggle sets (production defect SM-2026-001, Bug 5: the toggle used to
     * be dropped by validation, so every save silently became "manual" in the engine while
     * the screen kept showing "linked").
     */
    public function isLinkedToScorecard(): bool
    {
        return $this->computation_role === 'control_score_complement'
            && ($this->sm_scorecard_id !== null || $this->data_source === self::DEFAULT_DATA_SOURCES['scorecard']);
    }

    public function scorecard(): BelongsTo
    {
        return $this->belongsTo(Scorecard::class, 'sm_scorecard_id');
    }
}
