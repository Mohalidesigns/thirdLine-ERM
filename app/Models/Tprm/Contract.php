<?php

namespace App\Models\Tprm;

use App\Models\Organization;
use App\Models\Tprm\Concerns\TprmAuditable;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A contract, an amendment or a statement of work — TRD §8.6.
 *
 * THE NOTICE DATE, NOT THE EXPIRY DATE, IS THE DATE THAT MATTERS (FR-CTR-02).
 * A contract expiring in ninety days with a hundred-and-twenty-day notice
 * period has ALREADY auto-renewed, and an expiry-based reminder arrives to
 * tell somebody about a decision that is no longer theirs to make. That is the
 * single most common way an institution finds itself locked into a vendor it
 * had decided to leave, and `noticeDeadline()` is the whole answer to it.
 *
 * THE EFFECTIVE CLAUSE SET IS RESOLVED DOWN THE HIERARCHY WITH AMENDMENT
 * PRECEDENCE. An MSA silent on audit rights, amended a year later to grant
 * them, has audit rights — so a gap report reading the MSA alone would raise a
 * finding against a clause the parties agreed in writing. `effectiveClauses()`
 * walks the chain newest-first and takes the first determination it finds for
 * each clause.
 */
class Contract extends Model
{
    use BelongsToOrganization, SoftDeletes, TprmAuditable;

    protected $table = 'tp_contracts';

    /** @var list<string> */
    public const TYPES = ['msa', 'sow', 'amendment', 'nda', 'dpa', 'order_form', 'sla_schedule', 'other'];

    /**
     * The types that hang beneath another contract rather than standing alone.
     *
     * @var list<string>
     */
    public const SUBORDINATE_TYPES = ['sow', 'amendment', 'order_form', 'sla_schedule'];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_IN_NEGOTIATION = 'in_negotiation';

    public const STATUS_EXECUTED = 'executed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_TERMINATED = 'terminated';

    /** @var list<string> */
    public const RENEWAL_TYPES = ['none', 'auto', 'manual', 'evergreen'];

    protected $fillable = [
        'organization_id', 'engagement_id', 'parent_contract_id', 'contract_type',
        'reference', 'title', 'counterparty_signatory', 'internal_signatory_id',
        'effective_date', 'expiry_date', 'renewal_type', 'renewal_term_months',
        'notice_period_days_entity', 'notice_period_days_provider',
        'governing_law_country', 'dispute_forum', 'value_minor', 'currency',
        'status', 'document_id', 'created_by', 'updated_by',
    ];

    /**
     * Written by the clause analyser and the activation guard, never by a form.
     * A contract that could declare its own gap count to be zero would make
     * AC-06's gate a suggestion.
     *
     * @var list<string>
     */
    public const GUARDED_STATE = ['clause_analysis_status', 'blocking_gaps_count'];

    protected $casts = [
        'effective_date' => 'date',
        'expiry_date' => 'date',
        'renewal_term_months' => 'integer',
        'notice_period_days_entity' => 'integer',
        'notice_period_days_provider' => 'integer',
        'value_minor' => 'integer',
        'blocking_gaps_count' => 'integer',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'renewal_type' => 'none',
        'clause_analysis_status' => 'not_started',
        'blocking_gaps_count' => 0,
    ];

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

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_contract_id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_contract_id');
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    /** @return BelongsTo<User, $this> */
    public function internalSignatory(): BelongsTo
    {
        return $this->belongsTo(User::class, 'internal_signatory_id');
    }

    /** @return HasMany<ContractClause, $this> */
    public function clauses(): HasMany
    {
        return $this->hasMany(ContractClause::class, 'contract_id');
    }

    /** @return HasMany<Obligation, $this> */
    public function obligations(): HasMany
    {
        return $this->hasMany(Obligation::class, 'contract_id');
    }

    /** @return HasMany<Sla, $this> */
    public function slas(): HasMany
    {
        return $this->hasMany(Sla::class, 'contract_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Hierarchy */
    /* ------------------------------------------------------------------ */

    /**
     * This contract and every ancestor, newest first.
     *
     * Walks by parent id rather than by a recursive CTE, which SQLite and
     * MySQL spell differently. The depth is bounded below because a cycle —
     * which a badly-typed parent id could create — must not hang a request.
     *
     * @return Collection<int, Contract>
     */
    public function lineage(): Collection
    {
        /** @var Collection<int, Contract> $chain */
        $chain = collect([$this]);
        $seen = [$this->getKey() => true];
        $current = $this;

        for ($depth = 0; $depth < 10; $depth++) {
            $parent = $current->parent()->first();

            if ($parent === null || isset($seen[$parent->getKey()])) {
                break;
            }

            $chain->push($parent);
            $seen[$parent->getKey()] = true;
            $current = $parent;
        }

        return $chain;
    }

    /**
     * The root agreement this document sits under, or itself.
     */
    public function root(): self
    {
        return $this->lineage()->last();
    }

    /**
     * The whole family: the root agreement and every document beneath it, at
     * any depth.
     *
     * Gathered level by level rather than with a recursive CTE, which MySQL
     * and SQLite spell differently. Bounded at ten levels for the same reason
     * `lineage()` is — a cycle must not hang a request — and an amendment to a
     * statement of work under a master agreement is three levels, so ten is
     * generous rather than tight.
     *
     * @return Collection<int, Contract>
     */
    public function family(): Collection
    {
        $root = $this->root();
        $family = collect([$root]);
        $frontier = [$root->getKey()];

        for ($depth = 0; $depth < 10 && $frontier !== []; $depth++) {
            $children = self::query()->whereIn('parent_contract_id', $frontier)->get();

            if ($children->isEmpty()) {
                break;
            }

            $family = $family->concat($children);
            $frontier = $children->pluck('id')->all();
        }

        // Newest first: amendment precedence means the most recent
        // determination of a clause is the one that stands.
        return $family
            ->sortByDesc(fn (self $contract) => [
                $contract->effective_date->timestamp ?? 0,
                $contract->getKey(),
            ])
            ->values();
    }

    /* ------------------------------------------------------------------ */
    /*  Dates */
    /* ------------------------------------------------------------------ */

    /**
     * The last day we can serve notice — FR-CTR-02.
     *
     * Null when there is no expiry or no notice period, and that null is
     * honest rather than convenient: a contract whose notice period nobody has
     * recorded cannot be alerted on, and the register says so rather than
     * quietly treating it as zero days and alerting on the expiry.
     */
    public function noticeDeadline(): ?CarbonInterface
    {
        if ($this->expiry_date === null || $this->notice_period_days_entity === null) {
            return null;
        }

        return $this->expiry_date->copy()->subDays($this->notice_period_days_entity);
    }

    /**
     * Negative once the notice window has closed — which is the number that
     * matters, because at that point the renewal has happened whatever the
     * expiry date says.
     */
    public function daysUntilNotice(): ?int
    {
        $deadline = $this->noticeDeadline();

        return $deadline === null ? null : (int) now()->startOfDay()->diffInDays($deadline, false);
    }

    public function daysUntilExpiry(): ?int
    {
        return $this->expiry_date === null
            ? null
            : (int) now()->startOfDay()->diffInDays($this->expiry_date, false);
    }

    /**
     * Whether this contract will renew itself without anyone deciding to.
     *
     * `evergreen` counts: a contract with no end date that continues until
     * terminated is auto-renewal by another name, and the only difference is
     * that nothing ever prompts a review.
     */
    public function renewsAutomatically(): bool
    {
        return in_array($this->renewal_type, ['auto', 'evergreen'], true);
    }

    /**
     * Past the point where we could have stopped an automatic renewal.
     */
    public function noticeWindowMissed(): bool
    {
        $days = $this->daysUntilNotice();

        return $this->renewsAutomatically() && $days !== null && $days < 0 && ! $this->isEnded();
    }

    public function isExecuted(): bool
    {
        return $this->status === self::STATUS_EXECUTED;
    }

    public function isEnded(): bool
    {
        return in_array($this->status, [self::STATUS_EXPIRED, self::STATUS_TERMINATED], true);
    }

    /**
     * In force today: executed, started, and not past its expiry.
     */
    public function isInForce(): bool
    {
        if (! $this->isExecuted()) {
            return false;
        }

        if ($this->effective_date !== null && $this->effective_date->isFuture()) {
            return false;
        }

        return $this->expiry_date === null || ! $this->expiry_date->isBefore(now()->startOfDay());
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInForce(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_EXECUTED)
            ->where(fn (Builder $inner) => $inner
                ->whereNull('expiry_date')
                ->orWhereDate('expiry_date', '>=', now()->toDateString()));
    }

    /**
     * Contracts whose notice deadline falls within `$days`.
     *
     * Computed in SQL from `expiry_date - notice_period_days_entity` rather
     * than filtered in PHP, so the register and the alerting job can both page
     * through thousands without loading them.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNoticeDueWithin(Builder $query, int $days): Builder
    {
        return $query->whereNotNull('expiry_date')
            ->whereNotNull('notice_period_days_entity')
            ->whereRaw(
                self::noticeDateExpression().' BETWEEN ? AND ?',
                [now()->toDateString(), now()->addDays($days)->toDateString()]
            );
    }

    /**
     * The notice date, in SQL.
     *
     * MySQL and SQLite spell date arithmetic differently and neither
     * understands the other's. Written once here so a driver difference is a
     * single edit rather than a bug that appears only in production — which is
     * exactly how the RCSA MySQL-only SQL defects got in.
     */
    public static function noticeDateExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "date(expiry_date, '-' || notice_period_days_entity || ' days')",
            'pgsql' => "(expiry_date - notice_period_days_entity * INTERVAL '1 day')",
            default => 'DATE_SUB(expiry_date, INTERVAL notice_period_days_entity DAY)',
        };
    }
}
