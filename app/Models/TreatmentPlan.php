<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasObjectIdentity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * The columns 2026_02_22_200038_align_schema_with_controllers adds through its
 * own addColumns() helper. Larastan reads schema from Schema::create/table
 * calls it can see statically, so a column added in a loop is invisible to it
 * and every read of one is reported as an undefined property. These are
 * declarations of columns that exist, not overrides of anything inferred.
 *
 * The deprecated 200038 DUPLICATES (treatment_title, treatment_type, …) are
 * deliberately absent: they are served by the read-only accessors below and
 * go away with the columns in Migration B.
 *
 * @property string|null $treatment_code
 * @property string|null $milestones
 * @property string|null $success_criteria
 * @property int|null $expected_residual_likelihood
 * @property int|null $expected_residual_impact
 * @property int|null $approved_by
 * @property \Illuminate\Support\Carbon|null $approved_at
 * @property string|null $rejection_reason
 * @property int|null $updated_by
 */
class TreatmentPlan extends Model
{
    use BelongsToOrganization, HasFactory, HasObjectIdentity, SoftDeletes;

    /**
     * The four response strategies, from TreatmentPlanController's inline
     * `in:mitigate,transfer,avoid,accept` rule (migration Phase 3.5).
     *
     * @var list<string>
     */
    public const STRATEGIES = ['mitigate', 'transfer', 'avoid', 'accept'];

    /** @var list<string> */
    public const PRIORITIES = ['critical', 'high', 'medium', 'low'];

    /**
     * What the edit form may set `status` to. NOT the full set the column
     * holds: 'draft', 'approved', 'rejected' and 'on_hold' are written by the
     * approval path (TreatmentPlanBinding) and by data older than it, and were
     * absent from the controller's inline rule — offering them on the edit
     * form would let an owner approve their own plan by choosing a value.
     *
     * @var list<string>
     */
    public const EDITABLE_STATUSES = [
        'not_started', 'in_progress', 'completed', 'overdue', 'cancelled', 'pending_review',
    ];

    /**
     * Statuses the dashboard counts as ACTIVE. Both spellings of in-progress
     * are here because both are in the data: the column was a free string
     * before it was constrained, and the Blade dashboard counted both.
     *
     * @var list<string>
     */
    public const ACTIVE_STATUSES = ['in_progress', 'in-progress', 'open', 'not_started'];

    /**
     * Statuses that can run overdue — ACTIVE_STATUSES minus 'not_started': a
     * plan nobody has started is not late, it is unstarted. Carried from the
     * dashboard's own two different status lists, which is why they are two
     * constants and not one.
     *
     * @var list<string>
     */
    public const RUNNING_STATUSES = ['in_progress', 'in-progress', 'open'];

    protected $fillable = [
        'organization_id',
        'risk_id',
        // Original columns
        'strategy',
        'action_title',
        'action_description',
        'owner_id',
        'target_date',
        'priority',
        'status',
        'progress_pct',
        'progress_notes',
        'cost_estimate_ngn',
        'actual_cost_ngn',
        'expected_risk_reduction',
        'actual_risk_reduction',
        'completion_date',
        'evidence_refs',
        'dependencies',
        'created_by',
        // Extended columns with no canonical counterpart. The 200038
        // duplicates (treatment_title, treatment_type, estimated_cost, …) are
        // deliberately absent — they are no longer written. See
        // docs/schema/canonical-columns.md.
        'treatment_code',
        'milestones',
        'success_criteria',
        'expected_residual_likelihood',
        'expected_residual_impact',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'updated_by',
    ];

    protected $casts = [
        'expected_risk_reduction' => 'array',
        'actual_risk_reduction' => 'array',
        'evidence_refs' => 'array',
        'cost_estimate_ngn' => 'decimal:2',
        'actual_cost_ngn' => 'decimal:2',
        'start_date' => 'date',
        'target_date' => 'date',
        'completion_date' => 'date',
        'approved_at' => 'datetime',
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

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Organization, $this> */
    public function organization(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Risk, $this> */
    public function risk(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Risk::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function owner(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Accessors */
    /* */
    /*  These used to coalesce the original (action_*) and the duplicate */
    /*  (treatment_*) column of each pair. Now that only the canonical */
    /*  column is written, they read it directly. */
    /* ------------------------------------------------------------------ */

    public function getTitleAttribute(): ?string
    {
        return $this->action_title;
    }

    public function getDescriptionAttribute(): ?string
    {
        return $this->action_description;
    }

    public function getProgressAttribute(): int
    {
        return (int) ($this->progress_pct ?? 0);
    }

    public function getCostEstimateAttribute(): float
    {
        return (float) ($this->cost_estimate_ngn ?? 0);
    }

    /* ------------------------------------------------------------------ */
    /*  Deprecated-column bridges (WP-01 TASK 1) — read-only, removed with */
    /*  the columns in Migration B. */
    /* ------------------------------------------------------------------ */

    public function getTreatmentTitleAttribute(): ?string
    {
        return $this->action_title;
    }

    public function getTreatmentDescriptionAttribute(): ?string
    {
        return $this->action_description;
    }

    public function getTreatmentTypeAttribute(): ?string
    {
        return $this->strategy;
    }

    public function getTreatmentOwnerIdAttribute(): ?int
    {
        return $this->owner_id;
    }

    public function getTargetCompletionDateAttribute()
    {
        return $this->target_date;
    }

    public function getActualCompletionDateAttribute()
    {
        return $this->completion_date;
    }

    public function getEstimatedCostAttribute(): ?string
    {
        return $this->cost_estimate_ngn;
    }

    public function getActualCostAttribute(): ?string
    {
        return $this->actual_cost_ngn;
    }

    public function getProgressPercentageAttribute(): int
    {
        return (int) ($this->progress_pct ?? 0);
    }

    public function getImplementationNotesAttribute(): ?string
    {
        return $this->progress_notes;
    }
}
