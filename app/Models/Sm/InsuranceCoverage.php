<?php

namespace App\Models\Sm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * Scenario Modelling & Analysis Phase 2 — Insurance Netting Coverage Row (ADR 0027 Amendment 2 §A2.5).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $sm_scenario_id
 * @property string $severity
 * @property string $transferable_amount
 * @property string $deductible_local
 * @property string $limit_local
 * @property string $deductible_group
 * @property string $limit_group
 * @property string $recovery_rate
 * @property string $probability_of_cover
 * @property string $insurer_default_haircut
 * @property string $residual_amount
 * @property string $total_haircut
 * @property string $haircut_impact
 * @property string $gross_impact
 * @property string $net_impact
 */
class InsuranceCoverage extends Model
{
    use BelongsToOrganization;

    protected $table = 'sm_insurance_coverages';

    public const SEVERITIES = [
        'typical' => 'Typical Loss Severity',
        'serious' => 'Serious Loss Severity',
        'extreme' => 'Extreme Loss Severity',
    ];

    protected $fillable = [
        'organization_id',
        'sm_scenario_id',
        'severity',
        'transferable_amount',
        'deductible_local',
        'limit_local',
        'deductible_group',
        'limit_group',
        'recovery_rate',
        'probability_of_cover',
        'insurer_default_haircut',
        'residual_amount',
        'total_haircut',
        'haircut_impact',
        'gross_impact',
        'net_impact',
    ];

    protected $casts = [
        'transferable_amount' => 'decimal:8',
        'deductible_local' => 'decimal:8',
        'limit_local' => 'decimal:8',
        'deductible_group' => 'decimal:8',
        'limit_group' => 'decimal:8',
        'recovery_rate' => 'decimal:4',
        'probability_of_cover' => 'decimal:4',
        'insurer_default_haircut' => 'decimal:4',
        'residual_amount' => 'decimal:8',
        'total_haircut' => 'decimal:4',
        'haircut_impact' => 'decimal:8',
        'gross_impact' => 'decimal:8',
        'net_impact' => 'decimal:8',
    ];

    public function scenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class, 'sm_scenario_id');
    }
}
