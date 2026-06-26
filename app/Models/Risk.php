<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Str;

class Risk extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'entity_id',
        'parent_risk_id',
        'risk_level',
        'hierarchy_path',
        'roll_up_weight',
        'hierarchy_depth',
        'risk_code',
        'title',
        'description',
        'category_id',
        'business_unit_id',
        'process_id',
        'risk_owner_id',
        'risk_steward_id',
        'risk_source',
        'event_type',
        'cause_description',
        'effect_description',
        'inherent_likelihood',
        'inherent_impact',
        'inherent_score',
        'inherent_rating',
        'residual_likelihood',
        'residual_impact',
        'residual_score',
        'residual_rating',
        'target_likelihood',
        'target_impact',
        'target_score',
        'target_rating',
        'risk_velocity',
        'vulnerability',
        'control_effectiveness_pct',
        'financial_exposure_ngn',
        'treatment_strategy',
        'treatment_status',
        'appetite_aligned',
        'regulatory_mapping',
        'cbn_risk_type',
        'basel_event_type',
        'date_identified',
        'identified_by',
        'last_assessment_date',
        'next_review_date',
        'status',
        'tags',
        'metadata',
        'created_by',
    ];

    protected $casts = [
        'inherent_likelihood'     => 'integer',
        'inherent_impact'         => 'integer',
        'inherent_score'          => 'integer',
        'residual_likelihood'     => 'integer',
        'residual_impact'         => 'integer',
        'residual_score'          => 'integer',
        'appetite_aligned'        => 'boolean',
        'regulatory_mapping'      => 'array',
        'tags'                    => 'array',
        'metadata'                => 'array',
        'control_effectiveness_pct' => 'decimal:2',
        'financial_exposure_ngn'    => 'decimal:2',
        'date_identified'         => 'date',
        'last_assessment_date'    => 'date',
        'next_review_date'        => 'date',
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

    public function entity()
    {
        return $this->belongsTo(Entity::class);
    }

    // ── Hierarchy Relationships ──────────────────────────────────────
    public function parentRisk()
    {
        return $this->belongsTo(self::class, 'parent_risk_id');
    }

    public function childRisks()
    {
        return $this->hasMany(self::class, 'parent_risk_id');
    }

    public function allDescendants()
    {
        return $this->childRisks()->with('allDescendants');
    }

    public function controlTests()
    {
        return $this->hasManyThrough(ControlTest::class, Control::class, 'id', 'control_id')
            ->whereIn('controls.id', function ($q) {
                $q->select('control_id')->from('risk_control_mapping')->where('risk_id', $this->id);
            });
    }

    /**
     * Calculate the roll-up score from child risks.
     */
    public function calculateRollUpScore(): ?float
    {
        $children = $this->childRisks()->whereNotNull('residual_score')->get();
        if ($children->isEmpty()) return null;
        $totalWeight = $children->sum('roll_up_weight');
        if ($totalWeight == 0) return null;
        return $children->sum(fn ($c) => $c->residual_score * $c->roll_up_weight) / $totalWeight;
    }

    public function category()
    {
        return $this->belongsTo(RiskCategory::class, 'category_id');
    }

    public function businessUnit()
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    public function process()
    {
        return $this->belongsTo(BusinessProcess::class, 'process_id');
    }

    public function riskOwner()
    {
        return $this->belongsTo(User::class, 'risk_owner_id');
    }

    public function riskSteward()
    {
        return $this->belongsTo(User::class, 'risk_steward_id');
    }

    public function identifiedBy()
    {
        return $this->belongsTo(User::class, 'identified_by');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assessments()
    {
        return $this->hasMany(RiskAssessment::class);
    }

    public function treatmentPlans()
    {
        return $this->hasMany(TreatmentPlan::class);
    }

    public function auditTrail()
    {
        return $this->hasMany(RiskAuditTrail::class, 'entity_id')
            ->where('entity_type', 'risk');
    }

    public function controls()
    {
        return $this->belongsToMany(Control::class, 'risk_control_mapping')
            ->withPivot('control_weight', 'is_key_control', 'mapping_rationale')
            ->withTimestamps();
    }

    public function kris()
    {
        // KRIs are linked to risks via the direct FK `key_risk_indicators.risk_id`
        // (see KriController::store). The legacy `risk_kri_mapping` pivot is unused.
        return $this->hasMany(KeyRiskIndicator::class, 'risk_id');
    }

    public function relatedRisks()
    {
        return $this->belongsToMany(self::class, 'risk_related_risks', 'risk_id', 'related_risk_id')
            ->withPivot('relationship_type', 'description')
            ->withTimestamps();
    }

    public function controlMappings()
    {
        return $this->controls();
    }

    /** Alias: controller uses keyRiskIndicators, model has kris() */
    public function keyRiskIndicators()
    {
        return $this->kris();
    }

    /** Alias: controller uses auditTrails (plural), model has auditTrail() */
    public function auditTrails()
    {
        return $this->auditTrail();
    }

    /** Alias: blade uses $risk->owner */
    public function owner()
    {
        return $this->riskOwner();
    }

    /** Alias: blade uses $risk->steward */
    public function steward()
    {
        return $this->riskSteward();
    }

    public function nearMisses()
    {
        return $this->hasMany(NearMiss::class, 'risk_register_id');
    }

    public function lossEvents()
    {
        return $this->hasMany(LossEvent::class, 'risk_register_id');
    }

    public function issues()
    {
        return $this->hasMany(Issue::class, 'risk_register_id');
    }

    public function appetiteStatement()
    {
        return $this->hasOneThrough(RiskAppetite::class, RiskCategory::class, 'id', 'risk_category_id', 'category_id', 'id');
    }

    /* ------------------------------------------------------------------ */
    /*  Accessors                                                          */
    /* ------------------------------------------------------------------ */

    protected function inherentScore(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value ?? ($this->inherent_likelihood * $this->inherent_impact),
        );
    }

    protected function inherentRating(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if ($value) {
                    return $value;
                }

                $score = $this->inherent_likelihood * $this->inherent_impact;

                return match (true) {
                    $score >= 20 => 'Critical',
                    $score >= 12 => 'High',
                    $score >= 6  => 'Medium',
                    default      => 'Low',
                };
            },
        );
    }
}
