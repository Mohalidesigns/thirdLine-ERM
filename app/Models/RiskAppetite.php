<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasObjectIdentity;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class RiskAppetite extends Model
{
    use BelongsToOrganization, HasFactory, HasObjectIdentity, SoftDeletes;

    protected $table = 'risk_appetite';

    protected $fillable = [
        'organization_id',
        'risk_category_id',
        'appetite_level',
        'appetite_type',
        'appetite_statement',
        'tolerance_metric',
        'max_tolerance',
        'capacity',
        'target_min',
        'target_max',
        'current_position',
        'unit_of_measure',
        'effective_date',
        'expiry_date',
        'approved_by',
        'approved_date',
    ];

    protected $casts = [
        'max_tolerance' => 'decimal:4',
        'capacity' => 'decimal:4',
        'target_min' => 'decimal:4',
        'target_max' => 'decimal:4',
        'current_position' => 'decimal:4',
        'effective_date' => 'date',
        'expiry_date' => 'date',
        'approved_date' => 'date',
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
    /*  Accessors */
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
