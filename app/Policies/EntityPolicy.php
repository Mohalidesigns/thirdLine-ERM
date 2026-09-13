<?php

namespace App\Policies;

use App\Models\Entity;
use App\Models\User;
use App\Support\Authorization\GraphScope;

/**
 * Scoping / entities (migration Phase 3.1).
 *
 * Permission first, then tenancy, then node scope: a user pinned to a node
 * (User::scope_entity_id) may open an entity only when it sits at or below
 * that node, resolved as a prefix match on entities.hierarchy_path — the
 * same rule GraphScope applies to everything that hangs off the graph.
 *
 * Auto-discovered by Laravel's convention (App\Models\Entity → EntityPolicy);
 * there is deliberately no Gate::policy() line for it.
 */
class EntityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('entity.view');
    }

    public function view(User $user, Entity $entity): bool
    {
        return $user->can('entity.view') && $this->withinReach($user, $entity);
    }

    public function create(User $user): bool
    {
        return $user->can('entity.create');
    }

    public function update(User $user, Entity $entity): bool
    {
        return $user->can('entity.edit') && $this->withinReach($user, $entity);
    }

    /**
     * Whether an entity can be removed at all is a permission question; whether
     * THIS one can (no sub-entities, no risks) is a lifecycle rule the service
     * answers with a message the user can act on.
     */
    public function delete(User $user, Entity $entity): bool
    {
        return $user->can('entity.delete') && $this->withinReach($user, $entity);
    }

    /* ------------------------------------------------------------------ */

    /**
     * Same organization, and inside the user's subtree when they have one.
     */
    public static function withinReach(User $user, Entity $entity): bool
    {
        if ((int) $entity->organization_id !== (int) $user->organization_id) {
            return false;
        }

        $rootPath = self::subtreePathFor($user);

        if ($rootPath === null) {
            return true;
        }

        if ($rootPath === '') {
            // Pinned to a node that no longer exists: fail closed.
            return false;
        }

        $path = $entity->hierarchy_path ?: '/'.$entity->getKey().'/';

        return str_starts_with($path, $rootPath);
    }

    /**
     * The materialised path the user is confined to: null when they see the
     * whole organization, '' when their scope node is gone.
     */
    public static function subtreePathFor(User $user): ?string
    {
        if (! GraphScope::isSubtreeLimited($user)) {
            return null;
        }

        return GraphScope::rootPathFor($user) ?? '';
    }
}
