<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasObjectIdentity;
use App\Models\Concerns\ScopedToGraph;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class KeyRiskIndicator extends Model
{
    use BelongsToOrganization, HasFactory, HasObjectIdentity, ScopedToGraph, SoftDeletes;

    protected $table = 'key_risk_indicators';

    protected $fillable = [
        // Original migration columns
        'organization_id',
        'kri_code',
        'name',
        'description',
        'metric_formula',
        'data_source',
        'measurement_frequency',
        'unit_of_measure',
        'baseline_value',
        'green_threshold_min',
        'green_threshold_max',
        'amber_threshold_min',
        'amber_threshold_max',
        'red_threshold_min',
        'red_threshold_max',
        'threshold_direction',
        'current_value',
        'current_status',
        'trend_direction',
        'owner_id',
        'is_automated',
        'automation_config',
        'last_measurement_at',
        'created_by',
        // Alignment migration columns
        'risk_id',
        'is_active',
        'kri_name',
        'measurement_unit',
        'direction',
        'target_value',
        'kri_owner_id',
        'last_measurement_date',
    ];

    protected $casts = [
        'automation_config' => 'array',
        'is_automated' => 'boolean',
        'last_measurement_at' => 'datetime',
        'last_measurement_date' => 'date',
        'is_active' => 'boolean',
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

    public function entity()
    {
        return $this->belongsTo(Entity::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function measurements()
    {
        return $this->hasMany(KriMeasurement::class, 'kri_id');
    }

    public function risks()
    {
        return $this->belongsToMany(Risk::class, 'risk_kri_mapping', 'kri_id', 'risk_id')
            ->withTimestamps();
    }

    /**
     * Single risk relationship for backward compatibility.
     * The KRI model supports both:
     * - belongsToMany(Risk) via risk_kri_mapping table (correct, many-to-many)
     * - belongsTo(Risk, 'risk_id') for direct single assignment (kept for backward compatibility)
     * Controllers may still use this single relationship; prefer risks() for new code.
     */
    public function risk()
    {
        return $this->belongsTo(Risk::class, 'risk_id');
    }

    public function kriOwner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function latestMeasurement()
    {
        return $this->hasOne(KriMeasurement::class, 'kri_id')->latestOfMany('measurement_date');
    }

    /* ------------------------------------------------------------------ */
    /*  Form-facing accessors */
    /* ------------------------------------------------------------------ */

    /**
     * WP-05 TASK 2 — the single threshold values the form posts.
     *
     * The table stores a min and a max per band; the form collects one number
     * per band and KriController fans it out according to `direction`. These
     * accessors fold it back, so the edit form is prefilled with the number
     * the user originally typed rather than blank.
     *
     * Which end holds it depends on the direction, which is the whole reason
     * the controller has to fan it out in the first place: on a
     * higher-is-worse indicator green is a ceiling, on a lower-is-worse one it
     * is a floor.
     */
    public function getGreenThresholdAttribute(): ?float
    {
        return $this->thresholdEdge('green');
    }

    public function getAmberThresholdAttribute(): ?float
    {
        // Amber's outer edge is the red boundary at both directions; its inner
        // edge is green's. The form's single "amber" number is the red one.
        return $this->thresholdEdge('red');
    }

    public function getRedThresholdAttribute(): ?float
    {
        return $this->thresholdEdge('red');
    }

    private function thresholdEdge(string $band): ?float
    {
        $column = $this->threshold_direction === 'lower_worse'
            ? "{$band}_threshold_min"
            : "{$band}_threshold_max";

        // red_threshold on a higher-is-worse indicator is the point at which
        // red STARTS, which is its minimum, not its maximum.
        if ($band === 'red') {
            $column = $this->threshold_direction === 'lower_worse'
                ? 'red_threshold_max'
                : 'red_threshold_min';
        }

        $value = $this->getAttributes()[$column] ?? null;

        return $value === null ? null : (float) $value;
    }

    /**
     * `formula` is what both the create and edit forms post; metric_formula is
     * the column. KriController maps one onto the other on the way in — this
     * maps it back on the way out.
     */
    public function getFormulaAttribute(): ?string
    {
        return $this->getAttributes()['metric_formula'] ?? null;
    }
}
