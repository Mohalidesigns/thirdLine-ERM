<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IssueEscalationRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'severity',
        'days_overdue_trigger',
        'escalation_level',
        'notify_roles',
        'notify_user_ids',
        'auto_escalate',
        'is_active',
    ];

    protected $casts = [
        'notify_roles'    => 'array',
        'notify_user_ids' => 'array',
        'auto_escalate'   => 'boolean',
        'is_active'       => 'boolean',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
