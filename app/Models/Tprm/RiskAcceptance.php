<?php

namespace App\Models\Tprm;

use App\Enums\Tprm\FindingSeverity;
use App\Models\Tprm\Concerns\TprmAuditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A decision not to remediate a finding, for a stated period — FR-FND-04.
 *
 * `expires_at` IS MANDATORY IN PRACTICE and a scheduled job reopens the
 * finding when it passes. A risk acceptance with no expiry is not an
 * acceptance, it is a deletion with a paper trail: nobody revisits it, the
 * finding leaves the board pack, and two years later the institution is
 * carrying a risk that no living person decided to keep. The column is
 * nullable only so a draft can exist before approval.
 *
 * THE APPROVER LEVEL RISES WITH THE SEVERITY. Accepting a Critical finding is
 * a decision about whether the institution tolerates a serious control failure
 * in a third party, and `requiredPermissionFor()` puts that with the risk
 * function rather than with whoever opened the record.
 */
class RiskAcceptance extends Model
{
    use BelongsToOrganization, TprmAuditable;

    protected $table = 'tp_risk_acceptances';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'organization_id', 'finding_id', 'justification', 'compensating_controls',
        'residual_impact', 'approver_id', 'approver_role', 'approved_at',
        'expires_at', 'review_notes', 'created_by',
    ];

    /** @var list<string> */
    public const GUARDED_STATE = ['status'];

    protected $casts = [
        'approved_at' => 'datetime',
        'expires_at' => 'date',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    /** @return BelongsTo<Finding, $this> */
    public function finding(): BelongsTo
    {
        return $this->belongsTo(Finding::class, 'finding_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function isInForce(): bool
    {
        return $this->status === self::STATUS_APPROVED
            && $this->expires_at !== null
            && ! $this->expires_at->isBefore(now()->startOfDay());
    }

    public function hasExpired(): bool
    {
        return $this->status === self::STATUS_APPROVED
            && $this->expires_at !== null
            && $this->expires_at->isBefore(now()->startOfDay());
    }

    public function daysRemaining(): ?int
    {
        return $this->expires_at === null
            ? null
            : (int) now()->startOfDay()->diffInDays($this->expires_at, false);
    }

    /**
     * Who may approve an acceptance at this severity — FR-FND-04.
     *
     * A Critical acceptance requires `tprm.finding.accept_risk`, which sits
     * with the CRO or the risk committee. Lower severities are the programme's
     * to decide. The distinction is the whole control: a register in which any
     * analyst can accept a Critical finding is a register with no Critical
     * findings in it.
     */
    public static function requiredPermissionFor(FindingSeverity $severity): string
    {
        return match ($severity) {
            FindingSeverity::Critical, FindingSeverity::High => 'tprm.finding.accept_risk',
            default => 'tprm.finding.manage',
        };
    }

    /**
     * The longest an acceptance may run at this severity.
     *
     * A Critical risk accepted for three years is not a decision, it is a
     * decision avoided. The shorter the period, the sooner somebody has to
     * look at it again and say the same thing out loud.
     */
    public static function maximumMonthsFor(FindingSeverity $severity): int
    {
        return match ($severity) {
            FindingSeverity::Critical => 6,
            FindingSeverity::High => 12,
            default => 24,
        };
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInForce(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED)
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '>=', now()->toDateString());
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLapsed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED)
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<', now()->toDateString());
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        return $query->inForce()
            ->whereDate('expires_at', '<=', now()->addDays($days)->toDateString());
    }
}
