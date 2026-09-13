<?php

namespace App\Models;

use App\Models\Concerns\ProjectsGraphEdge;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LossEventControl extends Model
{
    use HasFactory, ProjectsGraphEdge;

    protected $fillable = [
        'loss_event_id',
        'control_id',
        'failure_description',
        'failure_type',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function lossEvent()
    {
        return $this->belongsTo(LossEvent::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Control, $this> */
    public function control(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Control::class);
    }
}
