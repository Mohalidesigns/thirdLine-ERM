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
        'organization_id',
        'methodology',
        'root_cause_category',
        'root_cause_description',
        'contributing_factors_text',
        'analysis_details',
        'recommendations',
        'lessons_learned',
        'status',
        'rca_status',
        'performed_by',
        'analysis_date',
        'completed_by',
        'completed_at',
        'approved_by',
        'approved_at',
        // 5-Whys fields
        'why_1_question', 'why_1_answer',
        'why_2_question', 'why_2_answer',
        'why_3_question', 'why_3_answer',
        'why_4_question', 'why_4_answer',
        'why_5_question', 'why_5_answer',
        'root_cause_statement',
        'contributory_factors',
    ];

    protected $casts = [
        'contributory_factors' => 'array',
        'analysis_date'        => 'datetime',
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
