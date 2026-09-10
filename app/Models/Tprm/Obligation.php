<?php

namespace App\Models\Tprm;

use App\Enums\Tprm\ObligationStatus;
use App\Models\Tprm\Concerns\TprmAuditable;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One duty owed under a contract, a regulation or an assurance report.
 *
 * `obligor` IS THE COLUMN THAT MAKES THIS REGISTER USEFUL. Obligations run
 * both ways, and the ones an institution discovers it has breached are almost
 * always its own: the quarterly access review the SOC 2 assumed we perform,
 * the annual audit-right exercise the contract entitles us to and nobody
 * scheduled. A register holding only the vendor's duties is a register that
 * cannot answer the question a supervisor asks.
 *
 * RECURRENCE IS COMPUTED FROM `next_due_date`, NEVER FROM A COUNT OF PAST
 * OCCURRENCES. An obligation satisfied late must not have its next date pulled
 * backwards to compensate, and one satisfied early must not skip a period; the
 * next date is always the last scheduled date plus one interval, so a
 * quarterly duty stays on the quarter it was agreed on.
 */
class Obligation extends Model
{
    use BelongsToOrganization, TprmAuditable;

    protected $table = 'tp_obligations';

    /** @var list<string> */
    public const SOURCES = ['contract', 'regulation', 'policy', 'assessment', 'finding'];

    public const OBLIGOR_ENTITY = 'entity';

    public const OBLIGOR_PROVIDER = 'provider';

    /** @var list<string> */
    public const FREQUENCIES = ['one_off', 'monthly', 'quarterly', 'semi_annual', 'annual', 'on_event'];

    protected $fillable = [
        'organization_id', 'contract_id', 'engagement_id', 'source', 'source_reference',
        'title', 'description', 'obligor', 'owner_id', 'frequency', 'due_date',
        'next_due_date', 'evidence_required', 'evidence_document_id', 'citation',
        'created_by', 'updated_by',
    ];

    /**
     * Written by the obligation engine as duties are satisfied or missed. A
     * form that could set `breach_count` could clear a breach history.
     *
     * @var list<string>
     */
    public const GUARDED_STATE = ['status', 'breach_count'];

    protected $casts = [
        'status' => ObligationStatus::class,
        'due_date' => 'date',
        'next_due_date' => 'date',
        'evidence_required' => 'boolean',
        'breach_count' => 'integer',
    ];

    protected $attributes = [
        'status' => 'pending',
        'frequency' => 'one_off',
        'evidence_required' => false,
        'breach_count' => 0,
    ];

    /** @return BelongsTo<Contract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    /** @return BelongsTo<Engagement, $this> */
    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class, 'engagement_id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<Document, $this> */
    public function evidence(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'evidence_document_id');
    }

    public function isRecurring(): bool
    {
        return ! in_array($this->frequency, ['one_off', 'on_event'], true);
    }

    /**
     * The interval between occurrences, in months. Null for anything that
     * does not recur on a calendar.
     */
    public function intervalMonths(): ?int
    {
        return match ($this->frequency) {
            'monthly' => 1,
            'quarterly' => 3,
            'semi_annual' => 6,
            'annual' => 12,
            default => null,
        };
    }

    /**
     * The date after this one.
     *
     * Advanced from the SCHEDULED date rather than from today, so that a duty
     * satisfied three weeks late still falls due on its own quarter next time.
     * Rolling from the completion date instead would let an obligation drift a
     * month a year until an annual review lands in a different half.
     */
    public function nextOccurrenceAfter(?CarbonInterface $from = null): ?CarbonInterface
    {
        $interval = $this->intervalMonths();

        if ($interval === null) {
            return null;
        }

        $base = $from ?? $this->next_due_date ?? $this->due_date;

        if ($base === null) {
            return null;
        }

        $next = $base->copy()->addMonths($interval);

        // A duty whose schedule has fallen far behind — nobody touched it for
        // a year — advances to the next FUTURE occurrence rather than
        // producing a due date already in the past, which would arrive as an
        // instant breach nobody could have prevented.
        while ($next->isBefore(now()->startOfDay())) {
            $next->addMonths($interval);
        }

        return $next;
    }

    public function isOverdue(): bool
    {
        return $this->next_due_date !== null
            && $this->next_due_date->isBefore(now()->startOfDay())
            && $this->status->isOutstanding();
    }

    public function daysUntilDue(): ?int
    {
        return $this->next_due_date === null
            ? null
            : (int) now()->startOfDay()->diffInDays($this->next_due_date, false);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', [
            ObligationStatus::Pending->value,
            ObligationStatus::Due->value,
            ObligationStatus::Breached->value,
        ]);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->outstanding()
            ->whereNotNull('next_due_date')
            ->whereDate('next_due_date', '<', now()->toDateString());
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDueWithin(Builder $query, int $days): Builder
    {
        return $query->outstanding()
            ->whereNotNull('next_due_date')
            ->whereDate('next_due_date', '>=', now()->toDateString())
            ->whereDate('next_due_date', '<=', now()->addDays($days)->toDateString());
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOwedByUs(Builder $query): Builder
    {
        return $query->where('obligor', self::OBLIGOR_ENTITY);
    }
}
