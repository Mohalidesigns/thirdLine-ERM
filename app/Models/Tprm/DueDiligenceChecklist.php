<?php

namespace App\Models\Tprm;

use App\Models\Tprm\Concerns\TprmAuditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * The due diligence pack for one engagement — FR-DDL-02.
 *
 * `tier_at_generation` IS RECORDED AND NEVER UPDATED. A checklist generated
 * against a High tier stays a High checklist even if the engagement is later
 * re-tiered, because "we did the due diligence the tier required" is a claim
 * about the tier in force at the time. Regenerating on re-tier would erase the
 * work already done and the reason it was scoped that way.
 */
class DueDiligenceChecklist extends Model
{
    use BelongsToOrganization, TprmAuditable;

    protected $table = 'tp_due_diligence_checklists';

    public const STATUS_OPEN = 'open';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_ABANDONED = 'abandoned';

    protected $fillable = [
        'organization_id', 'engagement_id', 'template_code', 'tier_at_generation',
        'created_by', 'updated_by',
    ];

    /** @var list<string> */
    public const GUARDED_STATE = ['status', 'completed_at', 'completed_by'];

    protected $casts = [
        'completed_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_OPEN,
    ];

    /** @return BelongsTo<Engagement, $this> */
    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class, 'engagement_id');
    }

    /** @return HasMany<DueDiligenceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(DueDiligenceItem::class, 'checklist_id');
    }

    /** @return BelongsTo<User, $this> */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * The mandatory items still open — FR-DDL-09's blockers.
     *
     * @return \Illuminate\Support\Collection<int, DueDiligenceItem>
     */
    public function blockers()
    {
        return $this->items()
            ->where('is_mandatory', true)
            ->get()
            ->reject(fn (DueDiligenceItem $item) => $item->isSettled())
            ->values();
    }

    public function canComplete(): bool
    {
        return $this->blockers()->isEmpty();
    }

    public function progress(): array
    {
        $items = $this->items()->get();

        return [
            'total' => $items->count(),
            'settled' => $items->filter(fn (DueDiligenceItem $item) => $item->isSettled())->count(),
            'mandatory' => $items->where('is_mandatory', true)->count(),
            'blocking' => $this->blockers()->count(),
            'waived' => $items->filter(fn (DueDiligenceItem $item) => $item->isWaived())->count(),
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }
}
