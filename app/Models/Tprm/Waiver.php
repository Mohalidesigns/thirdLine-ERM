<?php

namespace App\Models\Tprm;

use App\Models\Organization;
use App\Models\Tprm\Concerns\TprmAuditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One override register for the whole module.
 *
 * A tier override, a blocking-clause waiver, a due-diligence waiver and an
 * access exception are the same act: somebody with the standing to do so has
 * decided to accept a gap the model says should not be accepted. The TRD had
 * them as four separate escape hatches, each of which would have grown its own
 * approver column, its own expiry and its own half-built report — and a risk
 * committee reading four reports is a risk committee that reads none.
 *
 * `expires_at` is what makes this a register rather than a graveyard. A waiver
 * with no end date is a permanent exception whose rationale was written by
 * somebody who has since left, and `expiringWithin()` is the query the
 * committee pack is built from.
 */
class Waiver extends Model
{
    use BelongsToOrganization, TprmAuditable;

    protected $table = 'tp_waivers';

    public const TYPE_TIER_OVERRIDE = 'tier_override';

    public const TYPE_BLOCKING_CLAUSE = 'blocking_clause';

    public const TYPE_DUE_DILIGENCE_ITEM = 'due_diligence_item';

    public const TYPE_ACCESS_EXCEPTION = 'access_exception';

    public const TYPE_OFFBOARDING_ITEM = 'offboarding_item';

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_REVOKED = 'revoked';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_TIER_OVERRIDE,
        self::TYPE_BLOCKING_CLAUSE,
        self::TYPE_DUE_DILIGENCE_ITEM,
        self::TYPE_ACCESS_EXCEPTION,
        self::TYPE_OFFBOARDING_ITEM,
    ];

    protected $fillable = [
        'organization_id', 'waivable_type', 'waivable_id', 'engagement_id',
        'rationale', 'compensating_controls',
        'requested_by', 'requested_at', 'approver_id', 'approver_role', 'approved_at',
        'expires_at', 'status', 'revoked_at', 'revocation_reason',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'revoked_at' => 'datetime',
        'expires_at' => 'date',
    ];

    /** @param  Builder<self>  $query */
    public function scopeInForce(Builder $query): void
    {
        $query->where('status', self::STATUS_APPROVED)
            ->where(function (Builder $q) {
                $q->whereNull('expires_at')->orWhereDate('expires_at', '>=', now()->toDateString());
            });
    }

    /** @param  Builder<self>  $query */
    public function scopeExpiringWithin(Builder $query, int $days): void
    {
        $query->inForce()
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now()->toDateString(), now()->addDays($days)->toDateString()]);
    }

    public function isInForce(): bool
    {
        return $this->status === self::STATUS_APPROVED
            && ($this->expires_at === null || ! $this->expires_at->isPast());
    }

    public function label(): string
    {
        return match ($this->waivable_type) {
            self::TYPE_TIER_OVERRIDE => 'Tier override',
            self::TYPE_BLOCKING_CLAUSE => 'Blocking clause waived',
            self::TYPE_DUE_DILIGENCE_ITEM => 'Due diligence item waived',
            self::TYPE_ACCESS_EXCEPTION => 'Access exception',
            self::TYPE_OFFBOARDING_ITEM => 'Offboarding item excepted',
            default => ucwords(str_replace('_', ' ', (string) $this->waivable_type)),
        };
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Engagement, $this> */
    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class, 'engagement_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
