<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Str;

class RiskAppetite extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'risk_appetite';

    protected $fillable = [
        'organization_id',
        'risk_category_id',
        'appetite_type',
        'metric_name',
        'metric_unit',
        'max_tolerance',
        'target_min',
        'target_max',
        'current_position',
        'breach_status',
        'effective_from',
        'effective_to',
        'approved_by',
        'approved_at',
        'status',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'max_tolerance'    => 'decimal:4',
        'target_min'       => 'decimal:4',
        'target_max'       => 'decimal:4',
        'current_position' => 'decimal:4',
        'effective_from'   => 'date',
        'effective_to'     => 'date',
        'approved_at'      => 'datetime',
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
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function riskCategory()
    {
        return $this->belongsTo(RiskCategory::class);
    }

    public function category()
    {
        return $this->riskCategory();
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /* ------------------------------------------------------------------ */
    /*  Accessors                                                          */
    /* ------------------------------------------------------------------ */

    protected function breachStatus(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if ($value) {
                    return $value;
                }

                if (is_null($this->current_position) || is_null($this->max_tolerance)) {
                    return 'unknown';
                }

                return $this->current_position > $this->max_tolerance ? 'breached' : 'within_tolerance';
            },
        );
    }
}
