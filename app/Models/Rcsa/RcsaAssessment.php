<?php

namespace App\Models\Rcsa;

use App\Models\BusinessUnit;
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
 * One business unit's RCSA for one cycle — the thing an assessor opens and
 * works through. Step 2 of the process flow.
 *
 * `completion_pct` IS STORED, NOT COUNTED ON READ. The completion tracker
 * shows it for every unit in the bank at once, and counting scored lines
 * across every assessment on each dashboard render is a query per row.
 * RcsaAssessmentService::recomputeProgress() is the single writer, called on
 * every line save.
 *
 * @property-read Collection<int, RcsaAssessmentLine> $lines
 */
class RcsaAssessment extends Model
{
    use BelongsToOrganization, HasFactory, SoftDeletes;

    public const DRAFT = 'draft';

    public const IN_PROGRESS = 'in_progress';

    public const BU_APPROVAL = 'bu_approval';

    public const SUBMITTED = 'submitted';

    public const UNDER_REVIEW = 'under_review';

    public const VALIDATED = 'validated';

    public const RETURNED = 'returned';

    public const CLOSED = 'closed';

    /**
     * The states in which an assessor may still change a line.
     *
     * `returned` is editable — that is the whole point of returning it. The
     * states after submission are not, and P4's submission gate is what moves
     * an assessment out of them.
     *
     * @var list<string>
     */
    public const EDITABLE = [self::DRAFT, self::IN_PROGRESS, self::RETURNED];

    protected $table = 'rcsa_assessments';

    protected $fillable = [
        'organization_id',
        'cycle_id',
        'business_unit_id',
        'status',
        'assigned_to',
        'reviewer_id',
        'completion_pct',
        'submitted_by',
        'submitted_at',
        'reviewed_by',
        'reviewed_at',
        'returned_reason',
        'snapshot_path',
    ];

    protected $casts = [
        'completion_pct' => 'integer',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
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

    /** @return HasMany<RcsaAssessmentLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(RcsaAssessmentLine::class, 'assessment_id')->orderBy('sort_order');
    }

    /** @return BelongsTo<RcsaCycle, $this> */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(RcsaCycle::class, 'cycle_id');
    }

    /** @return BelongsTo<BusinessUnit, $this> */
    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Whether a line in this assessment may be changed right now.
     *
     * BOTH the assessment's own status AND the cycle's have to allow it. A
     * closed cycle freezes everything under it however an individual
     * assessment happens to be marked — otherwise a unit left `in_progress`
     * when the cycle closed would still accept edits, and its lines would
     * drift away from the figures the closed cycle reported.
     */
    public function acceptsEdits(): bool
    {
        return in_array($this->status, self::EDITABLE, true)
            && ($this->relationLoaded('cycle') ? $this->cycle : $this->cycle()->first())?->acceptsEdits() === true;
    }
}
