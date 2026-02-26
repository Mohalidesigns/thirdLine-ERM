<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class TreatmentPlan extends Model
{
    use HasFactory, SoftDeletes;

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
        // Extended columns
        'treatment_code',
        'treatment_title',
        'treatment_description',
        'treatment_type',
        'treatment_owner_id',
        'target_completion_date',
        'actual_completion_date',
        'estimated_cost',
        'actual_cost',
        'progress_percentage',
        'implementation_notes',
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
        'actual_risk_reduction'   => 'array',
        'evidence_refs'           => 'array',
        'cost_estimate_ngn'       => 'decimal:2',
        'actual_cost_ngn'         => 'decimal:2',
        'estimated_cost'          => 'decimal:2',
        'actual_cost'             => 'decimal:2',
        'start_date'              => 'date',
        'target_date'             => 'date',
        'completion_date'         => 'date',
        'target_completion_date'  => 'date',
        'actual_completion_date'  => 'date',
        'approved_at'             => 'datetime',
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
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function risk()
    {
        return $this->belongsTo(Risk::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
