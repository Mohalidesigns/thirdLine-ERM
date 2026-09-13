<?php

namespace App\Models\Tprm;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One due diligence item — FR-DDL-08.
 *
 * "NO SILENT SKIPPING." An item is settled in exactly two ways: it is done
 * with evidence attached, or it is waived by a named approver with a reason
 * and an expiry. There is no third state, and in particular there is no way to
 * mark an item complete because somebody looked at it and formed a view.
 *
 * A LAPSED WAIVER REOPENS THE ITEM, the same way a lapsed risk acceptance
 * reopens a finding. A waiver without an expiry would be a skip with extra
 * paperwork, and one that outlived its expiry silently would be worse — it
 * would look like a decision somebody was still standing behind.
 */
class DueDiligenceItem extends Model
{
    use BelongsToOrganization;

    protected $table = 'tp_due_diligence_items';

    public const STATUS_OPEN = 'open';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_WAIVED = 'waived';

    public const STATUS_NOT_APPLICABLE = 'not_applicable';

    protected $fillable = [
        'organization_id', 'checklist_id', 'code', 'title', 'category', 'item_type',
        'is_mandatory', 'owner_id', 'due_date', 'created_by', 'updated_by',
    ];

    /**
     * Settlement is written by the service that enforces FR-DDL-08. A form
     * that could set `status` could mark a mandatory item complete with
     * nothing behind it, which is the exact thing the requirement forbids.
     *
     * @var list<string>
     */
    public const GUARDED_STATE = [
        'status', 'evidence_document_id', 'waiver_reason', 'waiver_approver_id',
        'waiver_expires_at', 'completed_at',
    ];

    protected $casts = [
        'is_mandatory' => 'boolean',
        'due_date' => 'date',
        'waiver_expires_at' => 'date',
        'completed_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_OPEN,
        'is_mandatory' => false,
    ];

    /** @return BelongsTo<DueDiligenceChecklist, $this> */
    public function checklist(): BelongsTo
    {
        return $this->belongsTo(DueDiligenceChecklist::class, 'checklist_id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<User, $this> */
    public function waiverApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waiver_approver_id');
    }

    /** @return BelongsTo<Document, $this> */
    public function evidence(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'evidence_document_id');
    }

    public function isWaived(): bool
    {
        return $this->status === self::STATUS_WAIVED
            && $this->waiver_expires_at !== null
            && ! $this->waiver_expires_at->isBefore(now()->startOfDay());
    }

    public function waiverHasLapsed(): bool
    {
        return $this->status === self::STATUS_WAIVED
            && $this->waiver_expires_at !== null
            && $this->waiver_expires_at->isBefore(now()->startOfDay());
    }

    /**
     * Whether this item no longer blocks.
     *
     * Complete, not applicable, or waived-and-still-in-force. A lapsed waiver
     * blocks again, which is the whole reason the expiry is mandatory.
     */
    public function isSettled(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETE, self::STATUS_NOT_APPLICABLE], true)
            || $this->isWaived();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeBlocking(Builder $query): Builder
    {
        return $query->where('is_mandatory', true)
            ->whereNotIn('status', [self::STATUS_COMPLETE, self::STATUS_NOT_APPLICABLE]);
    }
}
