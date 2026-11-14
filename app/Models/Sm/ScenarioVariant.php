<?php

namespace App\Models\Sm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * Scenario Modelling & Analysis Phase 3 — Scenario Variant (ADR 0027 Amendment 3 §A3.2).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $sm_scenario_id
 * @property string $tier
 * @property string $name
 * @property string|null $narrative
 * @property bool $is_cumulative
 * @property array|null $triggers
 * @property array|null $operational_impacts
 * @property array|null $responses
 * @property array|null $response_owners
 * @property int $sort_order
 */
class ScenarioVariant extends Model
{
    use BelongsToOrganization;

    protected $table = 'sm_scenario_variants';

    public const TIER_1 = 'tier_1';

    public const TIER_2 = 'tier_2';

    public const TIER_3 = 'tier_3';

    public const TIERS = [
        self::TIER_1,
        self::TIER_2,
        self::TIER_3,
    ];

    public const TIER_LABELS = [
        self::TIER_1 => 'Tier 1 (Low Risk)',
        self::TIER_2 => 'Tier 2 (Medium Risk)',
        self::TIER_3 => 'Tier 3 (High Risk)',
    ];

    public const DEFAULT_TIER_NAMES = [
        self::TIER_1 => 'Scenario 1 (Low Risk / Contained)',
        self::TIER_2 => 'Scenario 2 (Medium Risk / BCP Activation)',
        self::TIER_3 => 'Scenario 3 (High Risk / Crisis Management)',
    ];

    protected $fillable = [
        'organization_id',
        'sm_scenario_id',
        'tier',
        'name',
        'narrative',
        'is_cumulative',
        'triggers',
        'operational_impacts',
        'responses',
        'response_owners',
        'sort_order',
    ];

    protected $casts = [
        'is_cumulative' => 'boolean',
        'triggers' => 'array',
        'operational_impacts' => 'array',
        'responses' => 'array',
        'response_owners' => 'array',
        'sort_order' => 'integer',
    ];

    public function scenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class, 'sm_scenario_id');
    }
}
