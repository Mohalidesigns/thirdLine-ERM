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

    /**
     * The states an ORM reviewer acts on — the review queue's contents.
     *
     * `bu_approval` is NOT here. An assessment waiting on its business-unit
     * head has not reached the second line yet, and putting it in the ORM
     * queue would have reviewers open work the business has not finished
     * signing off.
     *
     * @var list<string>
     */
    public const REVIEWABLE = [self::SUBMITTED, self::UNDER_REVIEW];

    /**
     * The states in which the assessment is out of the assessor's hands and
     * into somebody else's — used by the workspace to explain why a screen it
     * just let somebody fill in is now read-only.
     *
     * @var list<string>
     */
    public const AWAITING_DECISION = [self::BU_APPROVAL, self::SUBMITTED, self::UNDER_REVIEW];

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
        'escalated_at',
        'escalated_by',
        'escalation_reason',
        'snapshot_path',
    ];

    protected $casts = [
        'completion_pct' => 'integer',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'escalated_at' => 'datetime',
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

    /**
     * @return BelongsTo<\App\Models\Organization, $this>
     *
     * Needed by the submission snapshot: the shared PDF layout is branded per
     * tenant, and DocumentRenderer resolves that branding from an Organization
     * MODEL — handed an id it returns nothing and the layout fails on a
     * missing colour.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Organization::class);
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

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * The workflow history of §9.1, newest first.
     *
     * @return HasMany<RcsaAssessmentTransition, $this>
     */
    public function transitions(): HasMany
    {
        return $this->hasMany(RcsaAssessmentTransition::class, 'assessment_id')->latest('created_at');
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

    /**
     * Whether a reviewer may act on it — the queue's filter and the review
     * screen's guard.
     *
     * The CYCLE has to be open here too, for the same reason edits do: a
     * closed cycle has already reported its figures, and validating an
     * assessment inside one would change what a closed period says.
     */
    public function acceptsReview(): bool
    {
        return in_array($this->status, self::REVIEWABLE, true)
            && ($this->relationLoaded('cycle') ? $this->cycle : $this->cycle()->first())?->acceptsEdits() === true;
    }

    public function isEscalated(): bool
    {
        return $this->escalated_at !== null;
    }
}
