<?php

namespace App\Models;

use App\Models\Concerns\HasObjectIdentity;
use App\Models\Concerns\ScopedToGraph;
use App\Services\RiskScoringService;
use App\Support\MorphTypes;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * @property int|null $inherent_score never null in practice: the accessor
 *                                    derives likelihood x impact when the
 *                                    column is unset
 * @property string|null $inherent_rating likewise, banded from that score
 * @property string|null $risk_type
 */
class Risk extends Model
{
    use BelongsToOrganization, HasFactory, HasObjectIdentity, ScopedToGraph, SoftDeletes;

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
        'inherent_likelihood',
        'inherent_impact',
        'inherent_score',
        'inherent_rating',
        'residual_likelihood',
        'residual_impact',
        'residual_score',
        'residual_rating',
        'target_rating',
        'risk_velocity',
        'control_effectiveness_pct',
        'financial_exposure_ngn',
        'treatment_strategy',
        'appetite_aligned',
        'regulatory_mapping',
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
        'inherent_likelihood' => 'integer',
        'inherent_impact' => 'integer',
        'inherent_score' => 'integer',
        'residual_likelihood' => 'integer',
        'residual_impact' => 'integer',
        'residual_score' => 'integer',
        'appetite_aligned' => 'boolean',
        'regulatory_mapping' => 'array',
        'tags' => 'array',
        'metadata' => 'array',
        'control_effectiveness_pct' => 'decimal:2',
        'financial_exposure_ngn' => 'decimal:2',
        'date_identified' => 'date',
        'last_assessment_date' => 'date',
        'next_review_date' => 'date',
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

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Entity, $this> */
    public function entity(): \Illuminate\Database\Eloquent\Relations\BelongsTo
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
        if ($children->isEmpty()) {
            return null;
        }
        $totalWeight = $children->sum('roll_up_weight');
        if ($totalWeight == 0) {
            return null;
        }

        return $children->sum(fn ($c) => $c->residual_score * $c->roll_up_weight) / $totalWeight;
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<RiskCategory, $this> */
    public function category(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(RiskCategory::class, 'category_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<BusinessUnit, $this> */
    public function businessUnit(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    public function process()
    {
        return $this->belongsTo(BusinessProcess::class, 'process_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function riskOwner(): \Illuminate\Database\Eloquent\Relations\BelongsTo
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

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<RiskAssessment, $this> */
    public function assessments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(RiskAssessment::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<TreatmentPlan, $this> */
    public function treatmentPlans(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TreatmentPlan::class);
    }

    /**
     * The change history for this risk.
     *
     * Matches every spelling of the entity type that has ever been written,
     * not just the canonical alias. risk_audit_trail is append-only and
     * hash-chained — entity_type is part of each row's digest — so the historic
     * "Risk" and "App\Models\Risk" rows cannot be rewritten without re-sealing
     * the chain and destroying its tamper-evidence. Filtering on a single value
     * here is what made this relation return an empty collection while the
     * trail was in fact full: the change history screen showed nothing, which
     * on a compliance platform reads as "nothing ever happened".
     */
    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<RiskAuditTrail, $this> */
    public function auditTrail(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(RiskAuditTrail::class, 'entity_id')
            ->whereIn('entity_type', MorphTypes::spellingsFor($this->getMorphClass()));
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<Control, $this, RiskControlMapping> */
    public function controls(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        // using() is load-bearing, not decoration: without it attach()/sync()
        // write the pivot through the query builder, which fires no model
        // events, so the row would never be projected into the object graph
        // (and would never get its organization_id stamped either).
        return $this->belongsToMany(Control::class, 'risk_control_mapping')
            ->using(RiskControlMapping::class)
            ->withPivot('control_weight', 'is_key_control', 'mapping_rationale')
            ->withTimestamps();
    }

    /**
     * Step 2 of the assessment chain: the risk's root causes.
     *
     * Causes belong to the risk rather than to one assessment, so they
     * accumulate across cycles and stay answerable in aggregate ("which causes
     * recur across the register?").
     */
    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<RiskCause, $this> */
    public function causes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(RiskCause::class)
            ->orderBy('is_primary', 'desc')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function primaryCause()
    {
        return $this->hasOne(RiskCause::class)->where('is_primary', true);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<KeyRiskIndicator, $this> */
    public function kris(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        // KRIs are linked to risks via the direct FK `key_risk_indicators.risk_id`
        // (see KriController::store).
        return $this->hasMany(KeyRiskIndicator::class, 'risk_id');
    }

    /**
     * The legacy risk-to-KRI pivot.
     *
     * @deprecated Nothing writes this table and nothing reads it: KRIs are
     * associated through key_risk_indicators.risk_id. Removal is tracked in
     * docs/schema/deprecations.md. Use kris() instead.
     */
    public function kriMappings()
    {
        logger()->warning('Deprecated relationship used: risk_kri_mapping', [
            'risk_id' => $this->id,
            'replacement' => 'Risk::kris() / key_risk_indicators.risk_id',
            'caller' => self::deprecationCaller(),
        ]);

        return $this->belongsToMany(KeyRiskIndicator::class, 'risk_kri_mapping', 'risk_id', 'kri_id')
            ->using(RiskKriMapping::class)
            ->withPivot('correlation_type')
            ->withTimestamps();
    }

    /**
     * The first frame outside this class, so a deprecation warning names the
     * code that has to change rather than the model it was raised in.
     */
    protected static function deprecationCaller(): string
    {
        $frame = collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6))
            ->first(fn (array $f) => ($f['class'] ?? null) !== self::class);

        return trim(($frame['file'] ?? 'unknown').':'.($frame['line'] ?? '?'));
    }

    public function relatedRisks()
    {
        return $this->belongsToMany(self::class, 'risk_related_risks', 'risk_id', 'related_risk_id')
            ->withPivot('relationship_type', 'description')
            ->withTimestamps();
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<Control, $this, RiskControlMapping> */
    public function controlMappings(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->controls();
    }

    /**
     * Alias: controller uses keyRiskIndicators, model has kris().
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<KeyRiskIndicator, $this>
     */
    public function keyRiskIndicators(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->kris();
    }

    /**
     * Alias: controller uses auditTrails (plural), model has auditTrail().
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<RiskAuditTrail, $this>
     */
    public function auditTrails(): \Illuminate\Database\Eloquent\Relations\HasMany
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

    /**
     * The appetite statement in force for this risk's category, today.
     *
     * This was a hasOneThrough with no ordering and no date filter, so when a
     * category had more than one appetite — which is the normal case, because
     * the Board re-approves them and the old rows are kept as the historic
     * record — it returned whichever row the database happened to hand back
     * first. A risk could be reported as within appetite against a statement
     * that expired two years ago.
     *
     * Effective-dated instead: the most recent appetite whose effective_date
     * has arrived and whose expiry_date has not passed.
     */
    public function appetiteStatement()
    {
        return $this->hasOneThrough(
            RiskAppetite::class,
            RiskCategory::class,
            'id',
            'risk_category_id',
            'category_id',
            'id'
        )
            ->whereDate('risk_appetite.effective_date', '<=', now())
            ->where(function ($query) {
                $query->whereNull('risk_appetite.expiry_date')
                    ->orWhereDate('risk_appetite.expiry_date', '>=', now());
            })
            ->orderByDesc('risk_appetite.effective_date')
            ->orderByDesc('risk_appetite.id');
    }

    /* ------------------------------------------------------------------ */
    /*  Accessors */
    /* ------------------------------------------------------------------ */

    protected function inherentScore(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value ?? ($this->inherent_likelihood * $this->inherent_impact),
        );
    }

    /**
     * Falls back to deriving the rating from likelihood × impact.
     *
     * The bands used to be spelled out here as well as in
     * RiskScoringService::calculateRating(), and the two disagreed: this
     * accessor used >= 6 for Medium, the service uses >= 5. A risk scoring
     * exactly 5 was therefore Medium on any screen that read the service and
     * Low on any screen that read the model. There is now one implementation.
     */
    protected function inherentRating(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value ?: app(RiskScoringService::class)->calculateRating(
                (int) $this->inherent_likelihood * (int) $this->inherent_impact
            ),
        );
    }
}
