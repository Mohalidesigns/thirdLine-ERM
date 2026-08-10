<?php

namespace App\Support\Authorization;

use App\Models\Entity;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Node-scoped authorization over the organizational graph.
 *
 * Tenancy answers "which organization?"; this answers "which part of it?".
 * A user pinned to a node sees that node and everything beneath it, resolved
 * as an indexed prefix match on entities.hierarchy_path rather than a
 * recursive walk.
 *
 * NOTE ON hierarchy_path: WP-00 specifies filtering on hierarchy_path, and
 * risks carries a column by that name — but it holds the parent-RISK chain
 * (risk taxonomy), not the organizational chain. Scoping on it would limit a
 * user by risk lineage rather than by the org node they belong to, which is
 * not what node-scoped authorization means. This filters on the entities graph
 * instead, which is what risks, controls, issues, loss events and KRIs all
 * actually hang off via entity_id.
 */
class GraphScope
{
    /**
     * Constrain a query to the part of the graph the user may see.
     *
     * @param  string|null  $entityColumn  the foreign key to entities on this model
     */
    public static function apply(Builder $query, ?User $user, string $entityColumn = 'entity_id'): Builder
    {
        if (! self::isSubtreeLimited($user)) {
            return $query;
        }

        $rootPath = self::rootPathFor($user);

        // Pinned to a node that no longer exists: fail closed. Widening to the
        // whole organization would silently undo the restriction.
        if ($rootPath === null) {
            return $query->whereRaw('1 = 0');
        }

        $table = $query->getModel()->getTable();
        $qualified = $table.'.'.$entityColumn;

        return $query->where(function (Builder $outer) use ($qualified, $rootPath) {
            $outer->whereIn($qualified, function ($sub) use ($rootPath) {
                $sub->select('id')
                    ->from('entities')
                    ->where('hierarchy_path', 'like', $rootPath.'%');
            });

            if (config('authorization.subtree_users_see_unassigned', true)) {
                $outer->orWhereNull($qualified);
            }
        });
    }

    /**
     * True when this user is confined to part of the graph rather than all of it.
     */
    public static function isSubtreeLimited(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if (empty($user->scope_entity_id)) {
            return false;
        }

        return ! $user->hasAnyRole(config('authorization.full_org_roles', []));
    }

    /**
     * The materialised path of the user's scope node, or null if it is gone.
     */
    public static function rootPathFor(User $user): ?string
    {
        $entity = Entity::query()->find($user->scope_entity_id);

        if (! $entity) {
            return null;
        }

        // A path is written by the Entity model on save and backfilled by
        // migration; reconstruct defensively rather than returning null, which
        // would lock the user out of their own subtree.
        return $entity->hierarchy_path ?: '/'.$entity->getKey().'/';
    }
}
