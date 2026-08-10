<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
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
 */
class EmergingRisk extends Model
{
    use BelongsToOrganization, HasFactory, SoftDeletes;

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

    public function category()
    {
        return $this->belongsTo(RiskCategory::class, 'category_id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function convertedRisk()
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
