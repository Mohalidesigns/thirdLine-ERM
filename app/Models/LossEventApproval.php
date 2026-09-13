<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LossEventApproval extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'loss_event_id',
        'stage',
        'action',
        'decision',
        'comments',
        'conditions',
        'cbn_notified',
        'actioned_by',
        'actioned_at',
        'days_in_stage',
    ];

    protected $casts = [
        'actioned_at' => 'datetime',
        'cbn_notified' => 'boolean',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function lossEvent()
    {
        return $this->belongsTo(LossEvent::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function actionedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'actioned_by');
    }

    public function approver()
    {
        return $this->actionedBy();
    }
}
