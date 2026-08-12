<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The definition of a number: what it is, what it is measured in, which way is
 * good, and how it rolls up.
 *
 * A measure is not a value. `measures` holds one row for "residual risk score"
 * for the whole organisation; `measure_values` holds one row per risk per
 * period. That separation is what makes a trend query a single index scan
 * rather than a walk over per-object configuration.
 */
class Measure extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'description',
        'object_type_id',
        'measure_kind',
        'unit_id',
        'aggregation',
        'polarity',
        'decimal_places',
        'formula',
        'is_derived',
        'source',
        'frequency',
        'owner_id',
        'is_active',
    ];

    protected $casts = [
        'decimal_places' => 'integer',
        'is_derived' => 'boolean',
        'is_active' => 'boolean',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function objectType()
    {
        return $this->belongsTo(ObjectType::class, 'object_type_id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function values()
    {
        return $this->hasMany(MeasureValue::class);
    }

    public function thresholds()
    {
        return $this->hasMany(MeasureThreshold::class);
    }

    public function breaches()
    {
        return $this->hasMany(MeasureBreach::class);
    }

    /**
     * The KRI this measure was migrated from, where there is one.
     *
     * key_risk_indicators stays as the facade for one release (WP-04 TASK 3),
     * matched on kri_code == measures.code.
     */
    /**
     * The facade KRI this measure records for, matched on code.
     *
     * The tenant constraint is KeyRiskIndicator's own global organization
     * scope, NOT a whereColumn against `measures`: a relation is resolved by
     * its own query (`... where kri_code in (?)`), which never has the parent
     * table in scope, so referencing measures.organization_id here fails as
     * an unknown column on every load, eager or lazy.
     */
    public function keyRiskIndicator()
    {
        return $this->hasOne(KeyRiskIndicator::class, 'kri_code', 'code');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    public function scopeOfKind(Builder $query, string $kind): Builder
    {
        return $query->where('measure_kind', $kind);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    public function isMonetary(): bool
    {
        return $this->unit?->isMonetary() ?? false;
    }

    /**
     * Whether a higher value is worse. `target_band` measures have no single
     * direction, so they answer false and rely on explicit band bounds.
     */
    public function higherIsWorse(): bool
    {
        return $this->polarity === 'lower_better';
    }

    public function format(float|string|null $value): string
    {
        if ($value === null) {
            return '—';
        }

        return number_format((float) $value, $this->decimal_places)
            .($this->unit?->symbol ? ' '.$this->unit->symbol : '');
    }
}
