<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LossEventControl extends Model
{
    use HasFactory;

    protected $fillable = [
        'loss_event_id',
        'control_id',
        'failure_description',
        'failure_type',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function lossEvent()
    {
        return $this->belongsTo(LossEvent::class);
    }

    public function control()
    {
        return $this->belongsTo(Control::class);
    }
}
