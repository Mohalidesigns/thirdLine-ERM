<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KriMeasurement extends Model
{
    use HasFactory;

    protected $fillable = [
        'kri_id',
        'measurement_date',
        'value',
        'status',
        'entered_by',
        'data_source',
        'notes',
    ];

    protected $casts = [
        'measurement_date' => 'date',
        'value'            => 'decimal:4',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function kri()
    {
        return $this->belongsTo(KeyRiskIndicator::class, 'kri_id');
    }

    public function enteredBy()
    {
        return $this->belongsTo(User::class, 'entered_by');
    }
}
