<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IssueRemediationAction extends Model
{
    use HasFactory;

    protected $fillable = [
        'issue_id',
        'action_number',
        'description',
        'owner_id',
        'department',
        'priority',
        'target_date',
        'actual_close_date',
        'status',
        'completion_notes',
        'evidence_refs',
        'verified_by',
    ];

    protected $casts = [
        'evidence_refs' => 'array',
        'target_date' => 'date',
        'actual_close_date' => 'date',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function issue()
    {
        return $this->belongsTo(Issue::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function assignee()
    {
        return $this->owner();
    }

    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
