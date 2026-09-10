<?php

namespace App\Policies;

use App\Models\RegulatoryDeadline;
use App\Models\User;

/**
 * Who may maintain the filing calendar and file against it (Phase 5.3).
 *
 * FILING IS NOT SCHEDULING. `regulatory.file` is separate from
 * `regulatory.manage` in the seeded set and always has been: recording that a
 * return was submitted to the CBN on a given date is a statement to a
 * supervisor, while adding a deadline to the calendar is administration. The
 * routes already carried the distinction; nothing enforced it beyond the
 * middleware until this policy.
 *
 * Reach is the tenant. Gate::before grants super-admin every ability first.
 */
class RegulatoryDeadlinePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('regulatory.view');
    }

    public function view(User $user, RegulatoryDeadline $deadline): bool
    {
        return $user->can('regulatory.view') && $this->sameTenant($user, $deadline);
    }

    public function create(User $user): bool
    {
        return $user->can('regulatory.manage');
    }

    public function update(User $user, RegulatoryDeadline $deadline): bool
    {
        return $user->can('regulatory.manage') && $this->sameTenant($user, $deadline);
    }

    /** Record a filing against the deadline. */
    public function file(User $user, RegulatoryDeadline $deadline): bool
    {
        return $user->can('regulatory.file') && $this->sameTenant($user, $deadline);
    }

    private function sameTenant(User $user, RegulatoryDeadline $deadline): bool
    {
        return (int) $deadline->organization_id === (int) $user->organization_id;
    }
}
