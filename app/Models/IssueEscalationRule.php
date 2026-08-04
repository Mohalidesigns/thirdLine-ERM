<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IssueEscalationRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'priority',
        'issue_source',
        'escalation_level',
        'escalation_to_role',
        'days_overdue_trigger',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
