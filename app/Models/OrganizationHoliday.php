<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One non-working Africa/Lagos calendar day (ADR 0026 section 3). No recurrence
 * rule: Nigerian holidays move, and a list somebody maintains beats a guess.
 */
class OrganizationHoliday extends Model
{
    use BelongsToOrganization;

    protected $table = 'organization_holidays';

    protected $fillable = [
        'organization_id',
        'holiday_date',
        'name',
        'created_by',
    ];

    protected $casts = [
        'holiday_date' => 'date',
    ];

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
