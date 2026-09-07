<?php

namespace App\Models\Rcsa;

use App\Models\BusinessProcess;
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
 * One risk, as assessed in one cycle — a row of the workbook.
 *
 * IT IS A SNAPSHOT. `business_unit_name`, `process_name`, `potential_risk`,
 * `risk_driver`, `risk_category` and `existing_control` are COPIES taken when
 * the cycle was opened, not reads through `register_risk_id`. Editing a risk
 * statement in the universe in November must not rewrite the assessment the
 * Board Risk Committee signed in June. The foreign keys are kept so "show me
 * every cycle for this risk" still works; the text is kept so history cannot
 * be rewritten by a master-data edit.
 *
 * THE COMPUTED COLUMNS ARE WRITTEN BY RcsaCalculationService AND NOWHERE ELSE.
 * A controller that sets `residual_level` directly is a bug, however obvious
 * the value looks: the whole module rests on one implementation of the scoring
 * engine.
 *
 * @property-read Collection<int, RcsaActionPlan> $actionPlans
 */
class RcsaAssessmentLine extends Model
{
    use BelongsToOrganization, HasFactory, SoftDeletes;

    /**
     * The three answers an assessor gives. Everything else on the line is
     * either snapshotted from the universe or computed from these.
     *
     * @var list<string>
     */
    public const ASSESSED_FIELDS = ['inherent_likelihood', 'inherent_impact', 'control_effectiveness'];

    /**
     * Fields whose change is MATERIAL — workbook columns J, K, O, Q and S.
     *
     * Rule 7 of the process flow requires a before/after trail on these.
     * Writing them down here rather than in the service means the revision
     * recorder and the "what changed since last cycle" comparison read one
     * list.
     *
     * @var list<string>
     */
    public const MATERIAL_FIELDS = [
        'inherent_likelihood',
        'inherent_impact',
        'control_effectiveness',
        'residual_likelihood',
        'residual_impact',
        'treatment_override',
    ];

    /**
     * How long an advisory lock is honoured.
     *
     * Short on purpose. It exists to stop two people typing into the same row
     * in guided mode, not to reserve work: an assessor who closes their laptop
     * must not hold a line hostage, and the optimistic `version` check is what
     * actually prevents a lost update.
     */
    public const LOCK_MINUTES = 10;

    /* --- ORM review states (column `orm_status`) ---------------------- */

    public const ORM_PENDING = 'pending';

    public const ORM_ACCEPTED = 'accepted';

    public const ORM_FLAGGED = 'flagged';

    public const ORM_CHALLENGED = 'challenged';

    /**
     * The ORM verdicts that send a line back to the assessor.
     *
     * Both of them do. A challenge is a flag with a question attached — the
     * reviewer wants the rating changed or defended, and neither is possible
     * while the line is locked.
     *
     * @var list<string>
     */
    public const ORM_REOPENS = [self::ORM_FLAGGED, self::ORM_CHALLENGED];

    protected $table = 'rcsa_assessment_lines';

    protected $fillable = [
        'organization_id',
        'assessment_id',
        'register_risk_id',
        'business_unit_id',
        'process_id',
        'sub_process_id',
        'risk_no',
        'business_unit_name',
        'process_name',
        'sub_process_name',
        'system_names',
        'potential_risk',
        'risk_driver',
        'risk_category',
        'secondary_categories',
        'existing_control',
        'inherent_likelihood',
        'inherent_impact',
        'control_effectiveness',
        'inherent_score',
        'inherent_level',
        'ce_modifier',
        'residual_score',
        'residual_level',
        'risk_treatment',
        'appetite_status',
        'residual_likelihood',
        'residual_impact',
        'treatment_override',
        'treatment_override_reason',
        'assessor_id',
        'assessed_at',
        'assessment_rationale',
        'evidence_attachments',
        'orm_comment',
        'orm_status',
        'orm_reviewer_id',
        'orm_reviewed_at',
        'prior_cycle_line_id',
        'methodology_id',
        'row_hash',
        'version',
        'locked_by',
        'lock_expires_at',
        'locked_at',
        'sort_order',
    ];

    protected $casts = [
        'system_names' => 'array',
        'secondary_categories' => 'array',
        'evidence_attachments' => 'array',
        'inherent_likelihood' => 'integer',
        'inherent_impact' => 'integer',
        'inherent_score' => 'integer',
        'ce_modifier' => 'integer',
        'residual_score' => 'float',
        'residual_likelihood' => 'integer',
        'residual_impact' => 'integer',
        'version' => 'integer',
        'sort_order' => 'integer',
        'assessed_at' => 'datetime',
        'orm_reviewed_at' => 'datetime',
        'lock_expires_at' => 'datetime',
        'locked_at' => 'datetime',
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

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<RcsaAssessment, $this> */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(RcsaAssessment::class, 'assessment_id');
    }

    /** @return BelongsTo<RcsaRegisterRisk, $this> */
    public function registerRisk(): BelongsTo
    {
        return $this->belongsTo(RcsaRegisterRisk::class, 'register_risk_id');
    }

    /** @return BelongsTo<self, $this> */
    public function priorLine(): BelongsTo
    {
        return $this->belongsTo(self::class, 'prior_cycle_line_id');
    }

    /** @return BelongsTo<BusinessUnit, $this> */
    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    /** @return BelongsTo<BusinessProcess, $this> */
    public function process(): BelongsTo
    {
        return $this->belongsTo(BusinessProcess::class, 'process_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessor_id');
    }

    /** @return HasMany<RcsaActionPlan, $this> */
    public function actionPlans(): HasMany
    {
        return $this->hasMany(RcsaActionPlan::class, 'line_id');
    }

    /** @return HasMany<RcsaLineComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(RcsaLineComment::class, 'line_id')->oldest('created_at');
    }

    /** @return HasMany<RcsaLineRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(RcsaLineRevision::class, 'line_id')->latest('created_at');
    }

    /* ------------------------------------------------------------------ */
    /*  State */
    /* ------------------------------------------------------------------ */

    /**
     * Whether the assessor has answered all three questions.
     *
     * Not "has a residual score": in ASSESSED mode a residual can exist
     * without a control rating, and a line missing column O is not finished
     * however much of the rest is filled in.
     */
    public function isScored(): bool
    {
        foreach (self::ASSESSED_FIELDS as $field) {
            if (blank($this->{$field})) {
                return false;
            }
        }

        return true;
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    /**
     * Whether this particular line may be changed right now.
     *
     * THIS IS WHERE "RETURNING REOPENS ONLY THE FLAGGED LINES" IS ENFORCED —
     * P5's acceptance criterion, and it lives on the line rather than in a
     * controller because the assessment cannot express it. A returned
     * assessment accepts edits, so every route that consults only
     * `RcsaAssessment::acceptsEdits()` would hand back all 200 rows; what the
     * ORM actually asked for was the four they flagged. `locked_at` is set on
     * every line at submission and cleared, on return, only on the flagged
     * ones — so the lock the assessor meets is per-row.
     */
    public function acceptsEdits(): bool
    {
        if ($this->locked_at !== null) {
            return false;
        }

        $assessment = $this->relationLoaded('assessment') ? $this->assessment : $this->assessment()->first();

        return $assessment?->acceptsEdits() === true;
    }

    /**
     * Whether the ORM has sent this line back — flagged or challenged.
     */
    public function isFlaggedByOrm(): bool
    {
        return in_array((string) $this->orm_status, self::ORM_REOPENS, true);
    }

    /**
     * Whether somebody else is holding the advisory lock right now.
     *
     * An expired lock is not held. The alternative — honouring it until
     * somebody clears it — turns a closed laptop into a blocked assessment.
     */
    public function isHeldByAnother(?int $userId): bool
    {
        return $this->locked_by !== null
            && $this->locked_by !== $userId
            && $this->lock_expires_at !== null
            && $this->lock_expires_at->isFuture();
    }

    /**
     * The treatment in force: the assessor's override if they made one,
     * otherwise the calculated value.
     */
    public function effectiveTreatment(): ?string
    {
        return $this->treatment_override ?? $this->risk_treatment;
    }
}
