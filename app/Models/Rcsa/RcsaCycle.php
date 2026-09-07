<?php

namespace App\Models\Rcsa;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * An RCSA exercise — "RCSA 2026 H1". Step 1 of the process flow.
 *
 * A cycle PINS ITS METHODOLOGY, and that pin is the reason the methodology is
 * versioned at all: when the bank re-cuts its bands in 2027, this cycle's
 * closed assessments must still render, and still reconcile, under the scales
 * they were assessed with.
 *
 * OPENING IS THE MOMENT THE UNIVERSE IS COPIED. Before it, a cycle is a plan;
 * after it, every in-scope business unit has an assessment full of lines that
 * are a snapshot rather than a view. That is a one-way door — see
 * RcsaCycleService::open() for why re-opening does not re-provision.
 *
 * @property-read Collection<int, RcsaAssessment> $assessments
 */
class RcsaCycle extends Model
{
    use BelongsToOrganization, HasFactory, SoftDeletes;

    public const DRAFT = 'draft';

    public const OPEN = 'open';

    public const IN_REVIEW = 'in_review';

    public const CLOSED = 'closed';

    /** @var list<string> */
    public const STATUSES = [self::DRAFT, self::OPEN, self::IN_REVIEW, self::CLOSED];

    protected $table = 'rcsa_cycles';

    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'period_start',
        'period_end',
        'due_date',
        'methodology_id',
        'status',
        'opened_by',
        'opened_at',
        'closed_by',
        'closed_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'due_date' => 'date',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /** @return HasMany<RcsaAssessment, $this> */
    public function assessments(): HasMany
    {
        return $this->hasMany(RcsaAssessment::class, 'cycle_id');
    }

    /** @return BelongsTo<RcsaMethodology, $this> */
    public function methodology(): BelongsTo
    {
        return $this->belongsTo(RcsaMethodology::class, 'methodology_id');
    }

    /** @return BelongsTo<User, $this> */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    /**
     * Whether assessors may still change lines.
     *
     * `in_review` is deliberately NOT editable: the assessments have been
     * submitted and the second line is looking at them. Reopening a line is
     * P5's "return for rework", which is an act with an author and a reason,
     * not a side effect of the cycle's status.
     */
    public function acceptsEdits(): bool
    {
        return $this->status === self::OPEN;
    }
}
