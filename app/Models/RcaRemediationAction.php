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
        'action_description',
        'owner_id',
        'priority',
        'due_date',
        'completion_date',
        'status',
        'evidence_ref',
        'verified_by',
        'verified_at',
        'notes',
    ];

    protected $casts = [
        'due_date'        => 'date',
        'completion_date' => 'date',
        'verified_at'     => 'datetime',
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
