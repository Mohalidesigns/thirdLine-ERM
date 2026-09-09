<?php

namespace App\Models\Bcms;

use App\Models\Bcms\Concerns\BcmsAuditable;
use App\Models\Bcms\Concerns\HasBcmsUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One run of the maturity engine, dated and versioned.
 *
 * STORED, NEVER DERIVED ON READ. Same reasoning as `tp_engagements.effective_tier`
 * and the call-tree scorecard: a board pack printed in March must reprint in
 * December unchanged. `method_version` is on the row so that a scoring rule
 * changed in a later phase does not make March's number uninterpretable.
 *
 * `overall_score` is NULLABLE. A tenant with no artefacts has no maturity —
 * a rate over nothing is undefined, not one out of five.
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property ?int $programme_id
 * @property \Illuminate\Support\Carbon $assessed_at
 * @property ?int $assessed_by
 * @property string $method_version
 * @property ?string $overall_score
 * @property string $trigger
 * @property ?int $created_by
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 */
class MaturityAssessment extends Model
{
    use BcmsAuditable, BelongsToOrganization, HasBcmsUuid, HasFactory;

    protected $table = 'bcms_maturity_assessments';

    /** @var list<string> */
    protected $fillable = [
        'organization_id', 'programme_id', 'assessed_at', 'assessed_by', 'method_version',
        'overall_score', 'trigger', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'programme_id' => 'integer',
            'assessed_at' => 'datetime',
            'assessed_by' => 'integer',
            'overall_score' => 'decimal:2',
            'created_by' => 'integer',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<Programme, $this> */
    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class, 'programme_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }

    /** @return HasMany<MaturityScore, $this> */
    public function scores(): HasMany
    {
        return $this->hasMany(MaturityScore::class, 'assessment_id');
    }
}
