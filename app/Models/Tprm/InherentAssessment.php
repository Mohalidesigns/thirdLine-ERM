<?php

namespace App\Models\Tprm;

use App\Enums\Tprm\RiskTier;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One versioned run of the inherent risk questionnaire.
 *
 * Versions are never overwritten. Superseding a version clears `is_current` on
 * the old row rather than deleting it, because the history IS the audit trail:
 * "why was this vendor Moderate in March" is answerable only if March's
 * answers, weights and ruleset version all survive.
 */
class InherentAssessment extends Model
{
    use BelongsToOrganization;

    protected $table = 'tp_inherent_assessments';

    protected $fillable = [
        'organization_id', 'engagement_id', 'version', 'ruleset_version', 'answers',
        'factor_scores', 'weights', 'raw_score', 'knockouts_fired', 'resulting_tier',
        'assessed_by', 'assessed_at', 'is_current',
    ];

    protected $casts = [
        'answers' => 'array',
        'factor_scores' => 'array',
        'weights' => 'array',
        'knockouts_fired' => 'array',
        'raw_score' => 'decimal:2',
        'resulting_tier' => RiskTier::class,
        'assessed_at' => 'datetime',
        'is_current' => 'boolean',
        'version' => 'integer',
    ];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Engagement, $this> */
    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class, 'engagement_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }
}
