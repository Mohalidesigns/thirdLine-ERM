<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Pivot;

class RiskControlMapping extends Pivot
{
    use HasFactory;

    protected $table = 'risk_control_mapping';

    public $incrementing = true;

    protected $fillable = [
        'risk_id',
        'control_id',
        'organization_id',
        'mapping_rationale',
        'control_weight',
        'is_key_control',
        'created_by',
    ];

    protected $casts = [
        'is_key_control'  => 'boolean',
        'control_weight'  => 'decimal:2',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function risk()
    {
        return $this->belongsTo(Risk::class);
    }

    public function control()
    {
        return $this->belongsTo(Control::class);
    }
}
