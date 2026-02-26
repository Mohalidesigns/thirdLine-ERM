<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class LossEventRca extends Model
{
    use HasFactory;

    protected $table = 'loss_event_rca';

    protected $fillable = [
        'loss_event_id',
        'methodology',
        'root_cause',
        'contributory_factors',
        'immediate_cause',
        'systemic_issues',
        'findings',
        'recommendations',
        'completed_by',
        'completed_at',
        'approved_by',
        'approved_at',
        'status',
    ];

    protected $casts = [
        'contributory_factors' => 'array',
        'completed_at'         => 'datetime',
        'approved_at'          => 'datetime',
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

    public function lossEvent()
    {
        return $this->belongsTo(LossEvent::class);
    }

    public function completedBy()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function remediationActions()
    {
        return $this->hasMany(RcaRemediationAction::class, 'rca_id');
    }
}
