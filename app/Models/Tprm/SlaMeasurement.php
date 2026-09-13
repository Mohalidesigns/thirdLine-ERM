<?php

namespace App\Models\Tprm;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One period's measured value against one service level.
 *
 * `is_breach` IS COMPUTED ON WRITE AND THEN STORED, and the reason is the same
 * one that keeps every stored score in this module stored: renegotiating a
 * target next year must not retrospectively un-breach last year. A view that
 * recomputed the comparison against today's target would quietly rewrite the
 * service-credit history the moment somebody edited an SLA row.
 *
 * The credit columns are two, not one. `credit_claimed_minor` is what we
 * asked for and `credit_received_minor` is what arrived, and the gap between
 * them across a year is a number worth putting in front of a relationship
 * owner — it is the part of a penalty regime that quietly stops working.
 */
class SlaMeasurement extends Model
{
    use BelongsToOrganization;

    protected $table = 'tp_sla_measurements';

    protected $fillable = [
        'organization_id', 'sla_id', 'period_start', 'period_end', 'actual_value',
        'credit_claimed_minor', 'credit_received_minor', 'currency',
        'entered_by', 'evidence_document_id', 'notes',
    ];

    /**
     * Determined by the SLA's own operator and target when the measurement is
     * recorded. A form that could set these could record a breaching month as
     * compliant.
     *
     * @var list<string>
     */
    public const GUARDED_STATE = ['is_breach', 'breach_severity'];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'actual_value' => 'decimal:4',
        'is_breach' => 'boolean',
        'credit_claimed_minor' => 'integer',
        'credit_received_minor' => 'integer',
    ];

    protected $attributes = [
        'is_breach' => false,
    ];

    /** @return BelongsTo<Sla, $this> */
    public function sla(): BelongsTo
    {
        return $this->belongsTo(Sla::class, 'sla_id');
    }

    /** @return BelongsTo<User, $this> */
    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    /** @return BelongsTo<Document, $this> */
    public function evidence(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'evidence_document_id');
    }

    /**
     * Credit claimed but never received.
     *
     * Null rather than zero where nothing was claimed: "we claimed nothing"
     * and "we claimed and were paid in full" are different facts, and a
     * shortfall register that showed both as zero would hide the first.
     */
    public function creditShortfallMinor(): ?int
    {
        if ($this->credit_claimed_minor === null) {
            return null;
        }

        return max(0, $this->credit_claimed_minor - ($this->credit_received_minor ?? 0));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeBreaches(Builder $query): Builder
    {
        return $query->where('is_breach', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCreditOutstanding(Builder $query): Builder
    {
        return $query->whereNotNull('credit_claimed_minor')
            ->whereRaw('COALESCE(credit_received_minor, 0) < credit_claimed_minor');
    }
}
