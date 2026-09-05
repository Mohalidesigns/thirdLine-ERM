<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasObjectIdentity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An entry in the emerging risk register — something on the horizon that is not
 * yet a risk in the register proper.
 *
 * Populated by users today; WP-29 horizon scanning will write into the same
 * table, which is why `source` and `source_reference` are first-class columns
 * rather than free text buried in the description.
 *
 * HasObjectIdentity added in migration Phase 4.6. The graph has declared an
 * `EmergingRisk` object type since WP-03 — with 14 column-backed attributes and
 * two relationship types pointing at it — and nothing has ever put a row in
 * `objects` for one, because the model carried no identity and
 * ObjectSourceMap/modelTypeMap did not list it. The visible consequence was
 * that PersistsConfiguredAttributes could not resolve a type for this model
 * and returned 0 before validating anything: a field a tenant added to the
 * emerging risk form through the builder rendered, accepted what was typed and
 * was silently discarded on submit — verbatim the failure that trait's own
 * docblock exists to prevent. See docs/migration/phase-4-notes/emerging.md.
 */
/**
 * @property-read RiskCategory|null $category
 * @property-read User|null $owner
 * @property-read User|null $creator
 * @property-read Risk|null $convertedRisk
 */
class EmergingRisk extends Model
{
    use BelongsToOrganization, HasFactory, HasObjectIdentity, SoftDeletes;

    protected $fillable = [
        'organization_id', 'reference', 'title', 'description', 'category_id',
        'horizon', 'velocity_score', 'proximity_score', 'potential_impact',
        'status', 'source', 'source_reference', 'detected_at', 'last_reviewed_at',
        'potential_response', 'converted_risk_id', 'owner_id', 'created_by',
    ];

    protected $casts = [
        'detected_at' => 'date',
        'last_reviewed_at' => 'date',
        'velocity_score' => 'integer',
        'proximity_score' => 'integer',
    ];

    public const HORIZONS = ['0-3m', '3-6m', '6-12m', '12m+'];

    public const STATUSES = ['monitoring', 'assessing', 'escalated', 'converted', 'closed'];

    public const IMPACTS = ['Low', 'Medium', 'High', 'Critical'];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<RiskCategory, $this> */
    public function category(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(RiskCategory::class, 'category_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function owner(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Risk, $this> */
    public function convertedRisk(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Risk::class, 'converted_risk_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    public function scopeOnRadar($query)
    {
        return $query->whereIn('status', ['monitoring', 'assessing', 'escalated']);
    }

    /* ------------------------------------------------------------------ */
    /*  Derived */
    /* ------------------------------------------------------------------ */

    /**
     * Radar position, 1-25. The product of the two axes the analyst scored —
     * not a probability, and deliberately not presented as one.
     */
    public function getRadarScoreAttribute(): int
    {
        return (int) $this->velocity_score * (int) $this->proximity_score;
    }

    public function getVelocityLabelAttribute(): string
    {
        return match ((int) $this->velocity_score) {
            1, 2 => 'slow',
            3 => 'moderate',
            default => 'fast',
        };
    }

    public function getProximityLabelAttribute(): string
    {
        return match ((int) $this->proximity_score) {
            1, 2 => 'distant',
            3 => 'approaching',
            default => 'imminent',
        };
    }
}
