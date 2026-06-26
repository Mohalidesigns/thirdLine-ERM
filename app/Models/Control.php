<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Control extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'control_code',
        'name',
        'description',
        'control_type',
        'control_nature',
        'frequency',
        'automation_level',
        'owner_id',
        'business_unit_id',
        'effectiveness_rating',
        'effectiveness_pct',
        'last_test_date',
        'next_test_due',
        'status',
        'metadata',
        'created_by',
        'last_test_result',
        'tests_passed_count',
        'tests_failed_count',
        'total_tests_count',
    ];

    protected $casts = [
        'metadata'          => 'array',
        'effectiveness_pct' => 'decimal:2',
        'last_test_date'    => 'date',
        'next_test_due'     => 'date',
    ];

    // Standard % values for each effectiveness rating, used when
    // `effectiveness_pct` is not explicitly set on the control.
    public const EFFECTIVENESS_PERCENT_MAP = [
        'effective'            => 100,
        'partially_effective'  => 50,
        'ineffective'          => 0,
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

    /**
     * Numeric effectiveness % — uses the explicit `effectiveness_pct` column
     * when set, otherwise falls back to the standardized mapping from the
     * `effectiveness_rating` string.
     */
    public function getEffectivenessPercentAttribute(): int
    {
        if (($this->attributes['effectiveness_pct'] ?? null) !== null) {
            return (int) round((float) $this->attributes['effectiveness_pct']);
        }
        $rating = strtolower((string) ($this->attributes['effectiveness_rating'] ?? ''));
        return self::EFFECTIVENESS_PERCENT_MAP[$rating] ?? 0;
    }

    public function getEffectivenessLabelAttribute(): string
    {
        $rating = strtolower((string) ($this->attributes['effectiveness_rating'] ?? ''));
        return match ($rating) {
            'effective'           => 'Effective',
            'partially_effective' => 'Partially Effective',
            'ineffective'         => 'Ineffective',
            default               => 'Not Rated',
        };
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
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

    public function businessUnit()
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    public function risks()
    {
        return $this->belongsToMany(Risk::class, 'risk_control_mapping')
            ->withPivot('control_weight', 'is_key_control', 'mapping_rationale')
            ->withTimestamps();
    }

    public function riskMappings()
    {
        return $this->risks();
    }

    public function controlOwner()
    {
        return $this->owner();
    }

    public function nearMisses()
    {
        return $this->hasMany(NearMiss::class, 'linked_control_id');
    }

    public function lossEventControls()
    {
        return $this->hasMany(LossEventControl::class);
    }

    public function failedInEvents()
    {
        return $this->hasManyThrough(LossEvent::class, LossEventControl::class, 'control_id', 'id', 'id', 'loss_event_id');
    }

    // ── Control Testing ──────────────────────────────────────────────
    public function tests()
    {
        return $this->hasMany(ControlTest::class);
    }

    public function latestTest()
    {
        return $this->hasOne(ControlTest::class)->latest('completed_date');
    }

    public function updateTestStats(): void
    {
        $total   = $this->tests()->where('status', 'completed')->count();
        $passed  = $this->tests()->where('status', 'completed')->where('result', 'effective')->count();
        $failed  = $this->tests()->where('status', 'completed')->where('result', 'ineffective')->count();
        $latest  = $this->tests()->where('status', 'completed')->latest('completed_date')->first();

        $this->update([
            'total_tests_count'  => $total,
            'tests_passed_count' => $passed,
            'tests_failed_count' => $failed,
            'last_test_result'   => $latest?->result,
            'last_test_date'     => $latest?->completed_date,
        ]);
    }
}
