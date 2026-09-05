<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class RegulatoryCircular extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $fillable = [
        'organization_id', 'regulator', 'circular_ref', 'title', 'date_issued',
        'effective_date', 'summary', 'affected_risk_ids', 'affected_control_ids',
        'impact_level', 'compliance_status', 'compliance_pct', 'action_required',
        'assigned_to',
    ];

    protected $casts = [
        'date_issued' => 'date',
        'effective_date' => 'date',
        'affected_risk_ids' => 'array',
        'affected_control_ids' => 'array',
        'compliance_pct' => 'decimal:2',
    ];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
