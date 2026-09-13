<?php

namespace App\Policies;

use App\Models\ObjectRelationshipType;
use App\Models\User;

/**
 * Who may define typed edges (migration Phase 6.3).
 *
 * The same shape as ObjectTypePolicy, and for the same reasons: system rows
 * are shared, a tenant's own are theirs, and the code of a system row is what
 * the platform resolves it by.
 *
 * DELETION IS NOT REFUSED FOR A TYPE WITH INSTANCES — it archives them. That
 * rule is MetadataGuard::deleteRelationshipType()'s, not this class's, because
 * it is about data, not about who is asking.
 */
class ObjectRelationshipTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('admin.metadata');
    }

    public function view(User $user, ObjectRelationshipType $type): bool
    {
        return $user->can('admin.metadata') && $this->reachable($user, $type);
    }

    public function create(User $user): bool
    {
        return $user->can('admin.metadata');
    }

    public function update(User $user, ObjectRelationshipType $type): bool
    {
        return $this->view($user, $type);
    }

    public function updateIdentity(User $user, ObjectRelationshipType $type): bool
    {
        return $this->update($user, $type) && ! $type->is_system;
    }

    public function delete(User $user, ObjectRelationshipType $type): bool
    {
        return $this->update($user, $type) && ! $type->is_system;
    }

    private function reachable(User $user, ObjectRelationshipType $type): bool
    {
        return $type->organization_id === null
            || (int) $type->organization_id === (int) $user->organization_id;
    }
}
