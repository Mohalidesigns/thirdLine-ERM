<?php

namespace App\Policies;

use App\Models\KriDepartmentContact;
use App\Models\User;

/**
 * Who may choose each department's KRI contacts (ADR 0026 sections 2 and 14).
 * Configuring a contact grants nothing by itself: a champion still needs
 * `kri.submit_return` from a role.
 */
class KriDepartmentContactPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('kri.manage_department_contacts');
    }

    public function create(User $user): bool
    {
        return $user->can('kri.manage_department_contacts');
    }

    public function delete(User $user, KriDepartmentContact $contact): bool
    {
        return $user->can('kri.manage_department_contacts')
            && (int) $contact->organization_id === (int) $user->organization_id;
    }
}
