<?php

namespace App\Models\Tprm;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * What has to happen before a relationship is over — FR-EXT-03.
 */
class OffboardingChecklist extends Model
{
    use BelongsToOrganization;

    protected $table = 'tp_offboarding_checklists';

    public const STATUS_OPEN = 'open';

    public const STATUS_COMPLETE = 'complete';

    protected $fillable = ['organization_id', 'engagement_id', 'created_by'];

    /** @var list<string> */
    public const GUARDED_STATE = ['status', 'completed_at', 'completed_by'];

    protected $casts = ['completed_at' => 'datetime'];

    protected $attributes = ['status' => self::STATUS_OPEN];

    /** @return BelongsTo<Engagement, $this> */
    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class, 'engagement_id');
    }

    /** @return HasMany<OffboardingItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OffboardingItem::class, 'checklist_id');
    }

    /** @return BelongsTo<User, $this> */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * The items still standing between this engagement and being over.
     *
     * ONLY MANDATORY ONES BLOCK. An optional item left open is a decision
     * somebody made — customer communication where no customer was affected —
     * and treating it as a blocker would teach people to tick things that did
     * not happen.
     *
     * @return \Illuminate\Support\Collection<int, OffboardingItem>
     */
    public function blockingItems()
    {
        return $this->items()
            ->where('is_mandatory', true)
            ->whereNotIn('status', [OffboardingItem::STATUS_COMPLETE, OffboardingItem::STATUS_EXCEPTED])
            ->get();
    }

    public function isComplete(): bool
    {
        return $this->blockingItems()->isEmpty();
    }
}
