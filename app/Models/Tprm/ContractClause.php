<?php

namespace App\Models\Tprm;

use App\Enums\Tprm\ClausePresence;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;
use ThirdLine\Platform\Tenancy\OrganizationScope;

/**
 * One clause's determination against one contract.
 *
 * `reviewer_status` AND `presence` ARE BOTH REQUIRED AND THE GATE READS BOTH.
 * A machine detection is a proposal: `presence` may say `present` while
 * `reviewer_status` is still `pending`, and admitting a vendor on the strength
 * of an unreviewed detection would make AC-06's gate a formality dressed as a
 * control. `isSatisfied()` is the single definition of "this clause is
 * genuinely in the contract", and everything that gates reads it.
 *
 * A WAIVER SATISFIES THE GATE WITHOUT SATISFYING THE CLAUSE, and those are
 * deliberately different questions. `isSatisfied()` says the contract contains
 * the term; `blocksActivation()` says whether the engagement may proceed. A
 * waived audit-rights gap is still a gap on every report — it has simply been
 * accepted by someone with the authority to accept it, for a stated period.
 */
class ContractClause extends Model
{
    use BelongsToOrganization;

    protected $table = 'tp_contract_clauses';

    public const DETECTED_BY_AI = 'ai';

    public const DETECTED_BY_MANUAL = 'manual';

    public const REVIEW_PENDING = 'pending';

    public const REVIEW_ACCEPTED = 'accepted';

    public const REVIEW_REJECTED = 'rejected';

    protected $fillable = [
        'organization_id', 'contract_id', 'clause_library_id', 'presence',
        'located_text', 'page_reference', 'confidence', 'detected_by',
        'reviewer_status', 'reviewer_id', 'reviewed_at', 'gap_finding_id', 'waiver_id',
    ];

    protected $casts = [
        'presence' => ClausePresence::class,
        'confidence' => 'decimal:3',
        'reviewed_at' => 'datetime',
    ];

    protected $attributes = [
        'presence' => 'absent',
        'detected_by' => self::DETECTED_BY_MANUAL,
        'reviewer_status' => self::REVIEW_PENDING,
    ];

    /** @return BelongsTo<Contract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    /** @return BelongsTo<ClauseLibraryEntry, $this> */
    public function clause(): BelongsTo
    {
        return $this->belongsTo(ClauseLibraryEntry::class, 'clause_library_id')
            // The library ships with `organization_id = null`, which the
            // tenancy scope excludes — the same trap documented on
            // `Document::documentType()`.
            ->withoutGlobalScope(OrganizationScope::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /** @return BelongsTo<Waiver, $this> */
    public function waiver(): BelongsTo
    {
        return $this->belongsTo(Waiver::class, 'waiver_id');
    }

    /**
     * The contract genuinely contains this term.
     *
     * Present AND reviewed. A `partial` never satisfies: a notification duty
     * with no timeframe where the regulation fixes one is not the clause, and
     * treating it as one is how an institution reports compliance it does not
     * have.
     */
    public function isSatisfied(): bool
    {
        return $this->presence === ClausePresence::Present
            && $this->reviewer_status === self::REVIEW_ACCEPTED;
    }

    /**
     * Not applicable is not a gap — it is a determination that this clause was
     * considered and does not bind this contract.
     */
    public function isNotApplicable(): bool
    {
        return $this->presence === ClausePresence::NotApplicable;
    }

    public function hasLiveWaiver(): bool
    {
        return $this->waiver !== null && $this->waiver->isInForce();
    }

    /**
     * Whether this row stops the engagement activating (AC-06).
     *
     * Reads the LIBRARY's blocking flag, not a copy on this row: a clause
     * promoted to blocking after a contract was analysed must gate that
     * contract too, and a denormalised copy would leave it admitted.
     */
    public function blocksActivation(): bool
    {
        if (! (bool) $this->clause?->is_blocking) {
            return false;
        }

        if ($this->isSatisfied() || $this->isNotApplicable()) {
            return false;
        }

        return ! $this->hasLiveWaiver();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAwaitingReview(Builder $query): Builder
    {
        return $query->where('reviewer_status', self::REVIEW_PENDING);
    }

    /**
     * Gaps: anything not present-and-accepted, and not marked inapplicable.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeGaps(Builder $query): Builder
    {
        return $query->where(fn (Builder $inner) => $inner
            ->whereIn('presence', [ClausePresence::Absent->value, ClausePresence::Partial->value])
            ->orWhere('reviewer_status', '!=', self::REVIEW_ACCEPTED))
            ->where('presence', '!=', ClausePresence::NotApplicable->value);
    }
}
