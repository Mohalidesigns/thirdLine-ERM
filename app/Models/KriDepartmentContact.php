<?php

namespace App\Models;

use App\Enums\KriContactRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * One person in one role for one department's KRI return (ADR 0026 section 2).
 *
 * Many people per role; one person may hold several roles in one department.
 * No soft delete - the audit row on the business unit keeps the history - and
 * no AD column: the directory hint is read when the screen is drawn.
 */
class KriDepartmentContact extends Model
{
    use BelongsToOrganization;

    protected $table = 'kri_department_contacts';

    protected $fillable = [
        'organization_id',
        'business_unit_id',
        'user_id',
        'role',
        'assigned_by',
    ];

    protected $casts = [
        'role' => KriContactRole::class,
    ];

    /** @return BelongsTo<BusinessUnit, $this> */
    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
