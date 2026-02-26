<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IssueEscalationLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'issue_escalation_log';

    protected $fillable = [
        'issue_id',
        'escalation_level',
        'escalated_to_user_id',
        'escalated_by',
        'escalated_at',
        'reason',
        'notes',
    ];

    protected $casts = [
        'escalated_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function issue()
    {
        return $this->belongsTo(Issue::class);
    }

    public function escalatedToUser()
    {
        return $this->belongsTo(User::class, 'escalated_to_user_id');
    }

    public function escalatedBy()
    {
        return $this->belongsTo(User::class, 'escalated_by');
    }
}
