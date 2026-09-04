<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Services\RiskScoringService;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property int|null $overall_score the inherent score this assessment reached
 * @property string|null $overall_rating
 * @property int|null $impact_score the aggregated impact behind it
 */
class RiskAssessment extends Model
{
    use BelongsToOrganization, HasFactory;

    /** Residual risk calculated from inherent risk and control effectiveness. */
    public const RESIDUAL_DERIVED = 'derived';

    /** Residual risk overruled by the assessor, with a justification on record. */
    public const RESIDUAL_OVERRIDE = 'override';

    /**
     * Step 9 of the assessment chain. Five responses, not four: `share` — joint
     * venture, consortium, co-insurance, syndication — is distinct from
     * `transfer`, because with sharing the risk stays partly yours and has a
     * named counterparty.
     */
    public const TREATMENT_STRATEGIES = [
        'avoid' => 'Avoid — stop or do not start the activity',
        'reduce' => 'Reduce — strengthen controls to lower likelihood or impact',
        'share' => 'Share — carry the risk jointly with a named counterparty',
        'transfer' => 'Transfer — move the financial consequence to a third party',
        'accept' => 'Accept — retain the risk within appetite, with monitoring',
    ];

    protected $fillable = [
        'organization_id',
        'risk_id',
        'assessment_date',
        'assessment_type',
        'assessor_id',
        'reviewer_id',
        'status',
        'likelihood_score',
        'impact_financial',
        'impact_operational',
        'impact_reputational',
        'impact_regulatory',
        'impact_strategic',
        'impact_score',
        'overall_score',
        'overall_rating',
        'control_effectiveness_data',
        'control_effectiveness_pct',
        'residual_likelihood',
        'residual_impact',
        'residual_score',
        'residual_rating',
        'residual_source',
        'residual_justification',
        'treatment_strategy',
        'cause_snapshot',
        'assessment_notes',
        'evidence_refs',
        'previous_assessment_id',
    ];

    protected $casts = [
        'control_effectiveness_data' => 'array',
        'cause_snapshot' => 'array',
        'evidence_refs' => 'array',
        'assessment_date' => 'date',
        'control_effectiveness_pct' => 'decimal:2',
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

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Risk, $this> */
    public function risk(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Risk::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function assessor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'assessor_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function reviewer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function previousAssessment()
    {
        return $this->belongsTo(self::class, 'previous_assessment_id');
    }

    /**
     * Steps 6 and 7: the controls considered in this assessment, with the
     * effectiveness they were rated at when it was performed.
     */
    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<RiskAssessmentControl, $this> */
    public function assessedControls(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(RiskAssessmentControl::class, 'risk_assessment_id');
    }

    /* ------------------------------------------------------------------ */
    /*  The chain */
    /* ------------------------------------------------------------------ */

    /**
     * Whether residual risk on this assessment was calculated from control
     * effectiveness or overruled by the assessor.
     *
     * A reviewer's first question about a residual score is whether it is
     * arithmetic or judgement, and until WP-10a nothing on the record could
     * answer it.
     */
    public function residualWasOverridden(): bool
    {
        return $this->residual_source === self::RESIDUAL_OVERRIDE;
    }

    /**
     * The causes this assessment reasoned about, as frozen at submission.
     *
     * Falls back to the risk's current causes for assessments recorded before
     * WP-10a, which have no snapshot — clearly the best available answer, and
     * flagged as such by `cause_snapshot` being null.
     */
    public function causesConsidered(): \Illuminate\Support\Collection
    {
        if (! empty($this->cause_snapshot)) {
            return collect($this->cause_snapshot);
        }

        return $this->risk?->causes->map->toSnapshot() ?? collect();
    }

    /* ------------------------------------------------------------------ */
    /*  Accessors */
    /* ------------------------------------------------------------------ */

    protected function impactScore(): Attribute
    {
        return Attribute::make(
            // Delegates to the service rather than re-implementing the
            // aggregation, so an organization that configures `weighted` or
            // `worst_two` gets the same answer from the model and the service.
            get: fn ($value) => $value ?? app(RiskScoringService::class)->calculateImpact([
                'financial' => $this->impact_financial,
                'operational' => $this->impact_operational,
                'reputational' => $this->impact_reputational,
                'regulatory' => $this->impact_regulatory,
                'strategic' => $this->impact_strategic,
            ], $this->organization_id),
        );
    }

    protected function overallScore(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value ?? ($this->likelihood_score * $this->impact_score),
        );
    }

    // Bridge legacy `inherent_*` field names used by some views to the
    // canonical `overall_*` columns that the store flow populates.
    public function getInherentScoreAttribute(): ?int
    {
        return $this->attributes['overall_score'] ?? null;
    }

    protected function inherentRating(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value ?? $this->attributes['overall_rating'] ?? null,
        );
    }

    // Bridge the short field names used by the assessment views to the
    // canonical columns (`likelihood_score`, `impact_score`, `overall_rating`).
    public function getLikelihoodAttribute(): ?int
    {
        return isset($this->attributes['likelihood_score'])
            ? (int) $this->attributes['likelihood_score']
            : null;
    }

    public function getMaxImpactAttribute(): ?int
    {
        return $this->impact_score !== null ? (int) $this->impact_score : null;
    }

    public function getRatingAttribute(): ?string
    {
        return $this->attributes['overall_rating'] ?? null;
    }
}
