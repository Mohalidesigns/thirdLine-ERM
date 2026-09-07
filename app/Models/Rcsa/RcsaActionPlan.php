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
 * Many per line, where the workbook has one cell (defect D5). The tracking
 * register, reminders and ORM verification are P5; P3 and P4 only need the
 * rows to exist so the submission gate has something to check.
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

    protected $table = 'rcsa_action_plans';

    protected $fillable = [
        'organization_id', 'line_id', 'control_to_implement', 'owner_id', 'target_date',
        'status', 'progress_pct', 'completion_evidence', 'evidence_attachments',
        'closed_by', 'closed_at', 'verified_by', 'verified_at',
        'original_target_date', 'extension_reason', 'extension_approved_by',
    ];

    protected $casts = [
        'target_date' => 'date',
        'original_target_date' => 'date',
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
}
