<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasObjectIdentity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class TreatmentPlan extends Model
{
    use BelongsToOrganization, HasFactory, HasObjectIdentity, SoftDeletes;

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

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function risk()
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
