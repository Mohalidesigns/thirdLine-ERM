<?php

namespace App\Policies;

use App\Models\ObjectAttribute;
use App\Models\User;

/**
 * Who may shape the fields on a type (migration Phase 6.3).
 *
 * ObjectAttribute carries no organization_id and no tenancy trait: an
 * attribute belongs to its object type, and the type is what is scoped. So
 * reachability is asked of the parent, which is also the only correct place to
 * ask it — an attribute on a system type is as shared as the type is.
 *
 * The dangerous edit is the DATA TYPE OF AN ATTRIBUTE IN USE. That is not a
 * permission question and stays where it belongs, in MetadataGuard, which
 * holds a bundle import to the same rule as this screen.
 */
class ObjectAttributePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('admin.metadata');
    }

    public function view(User $user, ObjectAttribute $attribute): bool
    {
        return $user->can('admin.metadata') && $this->parentReachable($user, $attribute);
    }

    public function create(User $user): bool
    {
        return $user->can('admin.metadata');
    }

    public function update(User $user, ObjectAttribute $attribute): bool
    {
        return $this->view($user, $attribute);
    }

    public function delete(User $user, ObjectAttribute $attribute): bool
    {
        return $this->view($user, $attribute);
    }

    private function parentReachable(User $user, ObjectAttribute $attribute): bool
    {
        $type = $attribute->objectType;

        if ($type === null) {
            return false;
        }

        return $type->organization_id === null
            || (int) $type->organization_id === (int) $user->organization_id;
    }
}
