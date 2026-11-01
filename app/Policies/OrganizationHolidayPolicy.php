<?php

namespace App\Policies;

use App\Models\OrganizationHoliday;
use App\Models\User;

/**
 * Who may maintain the organisation's holiday list (ADR 0026 section 14). The
 * list moves the first working day of every month and every return's due date.
 */
class OrganizationHolidayPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('holiday.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('holiday.manage');
    }

    public function delete(User $user, OrganizationHoliday $holiday): bool
    {
        return $user->can('holiday.manage')
            && (int) $holiday->organization_id === (int) $user->organization_id;
    }
}
