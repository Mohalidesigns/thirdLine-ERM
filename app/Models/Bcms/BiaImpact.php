<?php

namespace App\Models\Bcms;

use App\Enums\Bcms\ImpactCategory;
use App\Enums\Bcms\ImpactHorizon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One impact category scored at one time horizon.
 *
 * `financial_amount_minor` is minor units and is NULLABLE. A reputational
 * impact has no naira figure, and printing zero against one would be a
 * fabricated number (development standard §5).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $assessment_id
 * @property \App\Enums\Bcms\ImpactCategory $impact_category
 * @property \App\Enums\Bcms\ImpactHorizon $horizon
 * @property ?int $severity_score
 * @property ?int $financial_amount_minor
 * @property ?string $currency
 * @property ?string $narrative
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 */
class BiaImpact extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $table = 'bcms_bia_impacts';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'assessment_id', 'impact_category', 'horizon', 'severity_score',
        'financial_amount_minor', 'currency', 'narrative',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'assessment_id' => 'integer',
            'severity_score' => 'integer',
            'financial_amount_minor' => 'integer',
            'impact_category' => ImpactCategory::class,
            'horizon' => ImpactHorizon::class,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<BiaAssessment, $this> */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(BiaAssessment::class, 'assessment_id');
    }
}
