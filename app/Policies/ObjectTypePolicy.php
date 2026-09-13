<?php

namespace App\Policies;

use App\Models\ObjectType;
use App\Models\User;

/**
 * Who may shape the object registry (migration Phase 6.3).
 *
 * `admin.metadata` is the permission the builder routes carry. What this adds
 * is the two questions the Livewire components asked inline, in prose, and in
 * different words each time.
 *
 * A SYSTEM TYPE IS EDITABLE BUT NOT DELETABLE, and only cosmetically. The
 * seeded registry is resolved BY CODE from a dozen places in the platform, so
 * renaming Risk's code would break all of them at once and only at runtime.
 * `updateIdentity()` is the ability the code, category and node flag are
 * gated on; `update()` covers the presentation fields a tenant legitimately
 * wants to change — their own icon, colour and reference prefix.
 *
 * A TENANT MAY NOT EDIT ANOTHER TENANT'S TYPE, which the global scope already
 * guarantees by making it invisible. This is the belt to that brace.
 */
class ObjectTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('admin.metadata');
    }

    public function view(User $user, ObjectType $type): bool
    {
        return $user->can('admin.metadata') && $this->reachable($user, $type);
    }

    public function create(User $user): bool
    {
        return $user->can('admin.metadata');
    }

    public function update(User $user, ObjectType $type): bool
    {
        return $this->view($user, $type);
    }

    /**
     * Change the code, the category or the node flag — the fields the rest of
     * the platform resolves this type by.
     */
    public function updateIdentity(User $user, ObjectType $type): bool
    {
        return $this->update($user, $type) && ! $type->is_system;
    }

    public function delete(User $user, ObjectType $type): bool
    {
        return $this->update($user, $type) && ! $type->is_system;
    }

    /**
     * A row this tenant can see: their own, or a seeded system row.
     *
     * The seeded rows carry a NULL organization_id and belong to everybody —
     * that is what `$tenantIncludesGlobal` on the model means.
     */
    private function reachable(User $user, ObjectType $type): bool
    {
        return $type->organization_id === null
            || (int) $type->organization_id === (int) $user->organization_id;
    }
}
