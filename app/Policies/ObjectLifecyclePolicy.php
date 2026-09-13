<?php

namespace App\Policies;

use App\Models\ObjectLifecycle;
use App\Models\User;

/**
 * Who may edit a state machine (migration Phase 6.3).
 *
 * A SEEDED LIFECYCLE IS NEVER EDITED IN PLACE. Its states are what the domain
 * tables' existing strings conform to, so changing one would silently
 * invalidate live rows; saving over a system lifecycle CLONES it into the
 * tenant's own namespace instead. That behaviour is the controller's, kept
 * from LifecycleBuilder — this class only says that `update` on a system row
 * is allowed, because the clone is the outcome the user wants and gets.
 *
 * Deletion of a system row is refused outright, and MetadataGuard decides
 * whether a tenant's own lifecycle is deletable (it is not, if a type still
 * defaults to it).
 */
class ObjectLifecyclePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('admin.metadata');
    }

    public function view(User $user, ObjectLifecycle $lifecycle): bool
    {
        return $user->can('admin.metadata') && $this->reachable($user, $lifecycle);
    }

    public function create(User $user): bool
    {
        return $user->can('admin.metadata');
    }

    public function update(User $user, ObjectLifecycle $lifecycle): bool
    {
        return $this->view($user, $lifecycle);
    }

    public function delete(User $user, ObjectLifecycle $lifecycle): bool
    {
        return $this->update($user, $lifecycle) && ! $lifecycle->is_system;
    }

    private function reachable(User $user, ObjectLifecycle $lifecycle): bool
    {
        return $lifecycle->organization_id === null
            || (int) $lifecycle->organization_id === (int) $user->organization_id;
    }
}
