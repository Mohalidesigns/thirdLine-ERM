<?php

namespace App\Models\Sm;

use App\Models\BusinessUnit;
use App\Models\Issue;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * Scenario Modelling & Analysis Phase 3 — Scenario Action (ADR 0027 Amendment 3 §A3.3).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $sm_scenario_id
 * @property string $kind
 * @property string|null $time_horizon
 * @property string $risk_area
 * @property string|null $current_position
 * @property string|null $identified_gap
 * @property string $action_plan
 * @property int|null $business_unit_id
 * @property string|null $department
 * @property int|null $owner_id
 * @property string|null $due_date
 * @property string $status
 * @property \Carbon\Carbon|null $completed_at
 * @property string|null $proof_of_closure
 * @property int|null $issue_id
 * @property int $sort_order
 */
class ScenarioAction extends Model
{
    use BelongsToOrganization;

    protected $table = 'sm_scenario_actions';

    public const KIND_GAP_REMEDIATION = 'gap_remediation';

    public const KIND_IMMEDIATE = 'immediate';

    public const KINDS = [
        self::KIND_GAP_REMEDIATION,
        self::KIND_IMMEDIATE,
    ];

    public const HORIZON_TODAY = 'today';

    public const HORIZON_WITHIN_7_DAYS = 'within_7_days';

    public const HORIZON_WITHIN_30_DAYS = 'within_30_days';

    public const HORIZON_CUSTOM = 'custom';

    public const HORIZONS = [
        self::HORIZON_TODAY,
        self::HORIZON_WITHIN_7_DAYS,
        self::HORIZON_WITHIN_30_DAYS,
        self::HORIZON_CUSTOM,
    ];

    public const HORIZON_LABELS = [
        self::HORIZON_TODAY => 'Today (Immediate)',
        self::HORIZON_WITHIN_7_DAYS => 'Within 7 Days',
        self::HORIZON_WITHIN_30_DAYS => 'Within 30 Days',
        self::HORIZON_CUSTOM => 'Custom Target Date',
    ];

    public const STATUS_NOT_STARTED = 'not_started';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_OVERDUE = 'overdue';

    public const STATUSES = [
        self::STATUS_NOT_STARTED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
        self::STATUS_OVERDUE,
    ];

    public const STATUS_LABELS = [
        self::STATUS_NOT_STARTED => 'Not Started',
        self::STATUS_IN_PROGRESS => 'In Progress',
        self::STATUS_COMPLETED => 'Completed',
        self::STATUS_OVERDUE => 'Overdue',
    ];

    protected $fillable = [
        'organization_id',
        'sm_scenario_id',
        'kind',
        'time_horizon',
        'risk_area',
        'current_position',
        'identified_gap',
        'action_plan',
        'business_unit_id',
        'department',
        'owner_id',
        'due_date',
        'status',
        'completed_at',
        'proof_of_closure',
        'issue_id',
        'sort_order',
    ];

    protected $casts = [
        'due_date' => 'date:Y-m-d',
        'completed_at' => 'datetime',
        'sort_order' => 'integer',
    ];

    public function scenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class, 'sm_scenario_id');
    }

    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class, 'business_unit_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class, 'issue_id');
    }
}
