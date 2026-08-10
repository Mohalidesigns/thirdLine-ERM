<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Services\RiskScoringService;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class RiskAssessment extends Model
{
    use BelongsToOrganization, HasFactory;

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
        'residual_likelihood',
        'residual_impact',
        'residual_score',
        'residual_rating',
        'assessment_notes',
        'evidence_refs',
        'previous_assessment_id',
    ];

    protected $casts = [
        'control_effectiveness_data' => 'array',
        'evidence_refs' => 'array',
        'assessment_date' => 'date',
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

    public function assessor()
    {
        return $this->belongsTo(User::class, 'assessor_id');
    }

    public function reviewer()
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
