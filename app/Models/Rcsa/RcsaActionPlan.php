<?php

namespace App\Models\Rcsa;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * Workbook columns U, V and W — what will be done about a risk above appetite.
 *
 * Many per line, where the workbook has one cell (defect D5).
 *
 * P5 turned it into the tracking register of §9.3 — the feature the plan says
 * is where most RCSA implementations quietly fail, because the assessment gets
 * done and the remediation never does. Three things make it a register rather
 * than a text column: `status` is swept by a scheduled command instead of
 * being set by whoever remembers, an extension is a REQUEST with an approver
 * rather than an edit to the date, and closure is the owner's claim while
 * `verified_at` is the second line's acceptance of it.
 */
class RcsaActionPlan extends Model
{
    use BelongsToOrganization, HasFactory, SoftDeletes;

    public const OPEN = 'open';

    public const IN_PROGRESS = 'in_progress';

    public const COMPLETED = 'completed';

    public const OVERDUE = 'overdue';

    public const CLOSED = 'closed';

    /** @var list<string> */
    public const STATUSES = [self::OPEN, self::IN_PROGRESS, self::COMPLETED, self::OVERDUE, self::CLOSED];

    /**
     * The statuses that mean nobody is working on this any more.
     *
     * `completed` is in here and `overdue` is not, which is the whole point of
     * the distinction: completed is the owner saying the control is in place,
     * overdue is the register saying the date went past while it was not.
     *
     * @var list<string>
     */
    public const SETTLED = [self::COMPLETED, self::CLOSED];

    /**
     * When the owner is reminded, in days before the target date (§9.3).
     *
     * T-0 is the last of them; `overdue` is not a reminder milestone but a
     * status change, announced once by the sweep at the moment it happens. A
     * daily "still overdue" mail is how a register teaches its owners to filter
     * it into a folder they never open.
     *
     * @var list<int>
     */
    public const REMINDER_DAYS = [14, 7, 0];

    protected $table = 'rcsa_action_plans';

    protected $fillable = [
        'organization_id', 'line_id', 'control_to_implement', 'owner_id', 'target_date',
        'status', 'progress_pct', 'completion_evidence', 'evidence_attachments',
        'closed_by', 'closed_at', 'verified_by', 'verified_at',
        'original_target_date', 'proposed_target_date', 'extension_reason',
        'extension_requested_by', 'extension_requested_at', 'extension_approved_by',
    ];

    protected $casts = [
        'target_date' => 'date',
        'original_target_date' => 'date',
        'proposed_target_date' => 'date',
        'extension_requested_at' => 'datetime',
        'evidence_attachments' => 'array',
        'progress_pct' => 'integer',
        'closed_at' => 'datetime',
        'verified_at' => 'datetime',
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

    /** @return BelongsTo<RcsaAssessmentLine, $this> */
    public function line(): BelongsTo
    {
        return $this->belongsTo(RcsaAssessmentLine::class, 'line_id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<User, $this> */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /** @return BelongsTo<User, $this> */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** @return BelongsTo<User, $this> */
    public function extensionApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'extension_approved_by');
    }

    /**
     * Whether the plan is complete enough to satisfy the submission gate.
     *
     * All three of the workbook's columns, and a date that has not already
     * passed — a plan due last month is not a plan.
     */
    public function isComplete(): bool
    {
        return filled($this->control_to_implement)
            && $this->owner_id !== null
            && $this->target_date !== null;
    }

    /**
     * Whether the date has gone past on work nobody has finished.
     *
     * Computed, not read off `status`. The status is what the nightly sweep
     * writes and what every filter and export reads; this is the truth the
     * sweep compares against, and a screen rendered at 14:00 on a plan that
     * came due at midnight should say overdue without waiting for the job.
     */
    public function isOverdue(): bool
    {
        return $this->target_date !== null
            && $this->target_date->isPast()
            && ! in_array($this->status, self::SETTLED, true);
    }

    /**
     * Days from today to the target date — negative once it has passed.
     *
     * Carbon 3 defaults $absolute to FALSE where Carbon 2 defaulted it to
     * true, and `diffInDays` on a past date therefore returns a negative
     * float. That flipped sign is what made CheckOverdueTreatments report
     * "-14.39 days overdue" and never escalate; the direction is chosen here
     * once so nothing downstream has to think about it.
     */
    public function daysUntilDue(): ?int
    {
        return $this->target_date === null
            ? null
            : (int) round(now()->startOfDay()->diffInDays($this->target_date->startOfDay(), false));
    }

    /**
     * Whether an extension has been asked for and not yet decided.
     */
    public function hasPendingExtension(): bool
    {
        return filled($this->extension_reason) && $this->extension_approved_by === null;
    }
}
