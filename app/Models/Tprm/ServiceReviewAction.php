<?php

namespace App\Models\Tprm;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * Something the meeting decided somebody would do.
 *
 * `finding_id` POINTS RATHER THAN DUPLICATES. Where an action is serious
 * enough to be tracked as a finding it becomes one, and this row links to it
 * — because the finding machinery already has severity, SLA, verification and
 * a residual-score consequence, and a parallel action tracker would grow a
 * worse version of all four.
 */
class ServiceReviewAction extends Model
{
    use BelongsToOrganization;

    protected $table = 'tp_service_review_actions';

    public const STATUS_OPEN = 'open';

    public const STATUS_COMPLETE = 'complete';

    protected $fillable = [
        'organization_id', 'service_review_id', 'description', 'owner_id', 'due_date', 'finding_id',
    ];

    /** @var list<string> */
    public const GUARDED_STATE = ['status', 'completed_at'];

    protected $casts = [
        'due_date' => 'date',
        'completed_at' => 'datetime',
    ];

    protected $attributes = ['status' => self::STATUS_OPEN];

    /** @return BelongsTo<ServiceReview, $this> */
    public function review(): BelongsTo
    {
        return $this->belongsTo(ServiceReview::class, 'service_review_id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<Finding, $this> */
    public function finding(): BelongsTo
    {
        return $this->belongsTo(Finding::class, 'finding_id');
    }

    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_OPEN
            && $this->due_date !== null
            && $this->due_date->isBefore(now()->startOfDay());
    }
}
