<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RcaRemediationAction extends Model
{
    use HasFactory;

    protected $fillable = [
        'rca_id',
        'loss_event_id',
        'action_number',
        'description',
        'owner_id',
        'department',
        'priority',
        'target_date',
        'actual_close_date',
        'status',
        'completion_notes',
        'verified_by',
    ];

    protected $casts = [
        'target_date'       => 'date',
        'actual_close_date' => 'date',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function rca()
    {
        return $this->belongsTo(LossEventRca::class, 'rca_id');
    }

    public function lossEvent()
    {
        return $this->belongsTo(LossEvent::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
