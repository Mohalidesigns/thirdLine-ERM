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
        'escalated_to_role',
        'escalated_to_user_id',
        'is_auto',
        'reason',
        'acknowledged',
        'acknowledged_at',
        'escalated_by',
        'escalated_at',
    ];

    protected $casts = [
        'escalated_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'is_auto' => 'boolean',
        'acknowledged' => 'boolean',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
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
