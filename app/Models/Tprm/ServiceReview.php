<?php

namespace App\Models\Tprm;

use App\Models\Tprm\Concerns\HasTprmUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A scheduled service review — FR-PRF.
 *
 * `performance_snapshot` IS SNAPSHOTTED AT THE MEETING, not linked. A minute
 * that refers to "the SLA pack" is worthless once the underlying measurements
 * have moved on, and a review held in March that a reader opens in November
 * should show what was in front of the room in March. This is the same
 * reasoning that made `ConcentrationAnalysis` immutable.
 *
 * A REVIEW THAT DID NOT HAPPEN IS OVERDUE, which is why `scheduled_for` is a
 * stored date rather than something derived from a cadence at read time. You
 * cannot be overdue against a calculation.
 */
class ServiceReview extends Model
{
    use BelongsToOrganization, HasTprmUuid;

    protected $table = 'tp_service_reviews';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_HELD = 'held';

    public const STATUS_MISSED = 'missed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'organization_id', 'engagement_id', 'cadence', 'scheduled_for',
        'agenda', 'attendees', 'owner_id', 'created_by',
    ];

    /** @var list<string> */
    public const GUARDED_STATE = ['status', 'held_on', 'minutes', 'performance_snapshot', 'completed_by'];

    protected $casts = [
        'scheduled_for' => 'date',
        'held_on' => 'date',
        'agenda' => 'array',
        'attendees' => 'array',
        'performance_snapshot' => 'array',
    ];

    protected $attributes = ['status' => self::STATUS_SCHEDULED];

    /** @return BelongsTo<Engagement, $this> */
    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class, 'engagement_id');
    }

    /** @return HasMany<ServiceReviewAction, $this> */
    public function actions(): HasMany
    {
        return $this->hasMany(ServiceReviewAction::class, 'service_review_id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_SCHEDULED
            && $this->scheduled_for->isBefore(now()->startOfDay());
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SCHEDULED)
            ->whereDate('scheduled_for', '<', now()->toDateString());
    }
}
