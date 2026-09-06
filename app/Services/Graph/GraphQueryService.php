<?php

namespace App\Services\Graph;

use App\Models\GraphObject;
use App\Models\ObjectRelationshipType;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Authorization\GraphScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Reading the object graph.
 *
 * Two things are true of every method here:
 *
 *   1. TENANCY. Every query runs through GraphObject, which carries the
 *      organization global scope. There is no path through this class that
 *      reads another tenant's node.
 *
 *   2. NODE SCOPE. A user pinned to part of the org chart sees that part.
 *      The pin lives on users.scope_entity_id and names an `entities` row —
 *      the authorization boundary WP-00 installed and that GraphScope enforces
 *      for the typed tables. This class does NOT redefine it: it resolves that
 *      same entity to its object and restricts to that subtree, so the graph
 *      and the register agree about what a person can see. Changing where the
 *      boundary is stored is its own migration with its own tests, because
 *      getting it wrong fails open.
 *
 * PERFORMANCE. descendants() is a prefix match on the materialised
 * hierarchy_path, not a recursive CTE: one indexed range scan regardless of
 * depth. The benchmark in tests/Feature/Graph/GraphQueryPerformanceTest.php
 * holds it under 200ms on a 50,000-node tree.
 */
class GraphQueryService
{
    /**
     * Everything beneath an object, at any depth, hydrated.
     *
     * Use descendantIds() instead when the subtree is large and you only need
     * to know WHICH nodes are in it. The traversal is a ~20 ms indexed range
     * scan over 50,000 nodes; turning those 50,000 rows into Eloquent models is
     * roughly half a second, and no amount of query tuning changes that. The
     * benchmark therefore holds descendantIds() to the 200 ms budget and holds
     * descendants() to it for the filtered reads a screen actually performs.
     *
     * @param  list<string>  $types  restrict to these object type codes
     * @return Collection<int, GraphObject>
     */
    public function descendants(int $objectId, array $types = [], ?int $maxDepth = null, ?User $user = null): Collection
    {
        $query = $this->descendantQuery($objectId, $types, $maxDepth, $user);

        return $query === null ? collect() : $query->orderBy('hierarchy_path')->get();
    }

    /**
     * The ids of everything beneath an object — the scale path.
     *
     * No model hydration, so this stays flat as the tree grows. It is what
     * authorization filters, counts and roll-ups should use.
     *
     * @param  list<string>  $types
     * @return Collection<int, int>
     */
    public function descendantIds(int $objectId, array $types = [], ?int $maxDepth = null, ?User $user = null): Collection
    {
        $query = $this->descendantQuery($objectId, $types, $maxDepth, $user);

        return $query === null
            ? collect()
            : $query->toBase()->pluck('id')->map(fn ($id) => (int) $id);
    }

    /**
     * The subtree query, or null when the root is not visible to this caller.
     *
     * @param  list<string>  $types
     */
    private function descendantQuery(int $objectId, array $types, ?int $maxDepth, ?User $user): ?Builder
    {
        $root = $this->find($objectId, $user);

        if ($root === null) {
            return null;
        }

        $query = $this->scoped($user)
            ->where('hierarchy_path', 'like', $root->pathOrFallback().'%')
            ->where('id', '!=', $root->id);

        if ($maxDepth !== null) {
            $query->where('hierarchy_depth', '<=', (int) $root->hierarchy_depth + $maxDepth);
        }

        $this->restrictToTypes($query, $types);

        return $query;
    }

    /**
     * Every ancestor, nearest parent first.
     *
     * Read from the materialised path rather than by walking parent_id, so the
     * whole chain is one query however deep the tree is.
     *
     * @return Collection<int, GraphObject>
     */
    public function ancestors(int $objectId, ?User $user = null): Collection
    {
        $object = $this->find($objectId, $user);

        if ($object === null) {
            return collect();
        }

        $ids = array_values(array_filter(
            array_map('intval', explode('/', trim($object->pathOrFallback(), '/'))),
            fn (int $id) => $id !== 0 && $id !== $object->id
        ));

        if ($ids === []) {
            return collect();
        }

        $byId = $this->scoped($user)->whereIn('id', $ids)->get()->keyBy('id');

        // Path order is root-first; ancestors read nearest-first.
        return collect(array_reverse($ids))
            ->map(fn (int $id) => $byId->get($id))
            ->filter()
            ->values();
    }

    /**
     * Objects one or more hops away along a named relationship.
     *
     * @param  string  $direction  'out' follows the edge as declared, 'in'
     *                             follows it backwards, 'both' does either
     * @return Collection<int, GraphObject>
     */
    public function related(
        int $objectId,
        string $relationshipCode,
        string $direction = 'out',
        int $depth = 1,
        ?User $user = null,
    ): Collection {
        $frontier = [$objectId];
        $seen = [$objectId => true];
        $collected = [];

        for ($hop = 0; $hop < max(1, $depth); $hop++) {
            $next = $this->step($frontier, $relationshipCode, $direction);

            $next = array_values(array_filter($next, fn (int $id) => ! isset($seen[$id])));

            if ($next === []) {
                break;
            }

            foreach ($next as $id) {
                $seen[$id] = true;
                $collected[] = $id;
            }

            $frontier = $next;
        }

        if ($collected === []) {
            return collect();
        }

        return $this->scoped($user)->whereIn('id', $collected)->get();
    }

    /**
     * Walk a named sequence of relationships.
     *
     * traverse($id, ['supports', 'depends_on']) answers "what does the thing
     * this supports depend on" — the question a concentration-risk view asks
     * and that no single pivot table can answer.
     *
     * @param  list<string>  $path
     * @return Collection<int, GraphObject>
     */
    public function traverse(int $objectId, array $path, ?int $maxDepth = null, ?User $user = null): Collection
    {
        $frontier = [$objectId];
        $seen = [$objectId => true];
        $steps = $maxDepth === null ? $path : array_slice($path, 0, $maxDepth);

        foreach ($steps as $relationshipCode) {
            $direction = 'out';

            // 'supports:in' reads the edge backwards for this hop only.
            if (str_contains($relationshipCode, ':')) {
                [$relationshipCode, $direction] = explode(':', $relationshipCode, 2);
            }

            $frontier = array_values(array_filter(
                $this->step($frontier, $relationshipCode, $direction),
                function (int $id) use (&$seen) {
                    if (isset($seen[$id])) {
                        return false;
                    }

                    $seen[$id] = true;

                    return true;
                }
            ));

            if ($frontier === []) {
                return collect();
            }
        }

        return $this->scoped($user)->whereIn('id', $frontier)->get();
    }

    /**
     * Aggregate a measure over an object's subtree.
     *
     * $measureCode names a column on `objects` or a key in its attributes JSON.
     * Weight comes from the edge where one exists — a subsidiary contributing
     * 30% of group exposure counts as 0.3 of its own number — which is what
     * makes this a graph-derived roll-up rather than a SUM over a foreign key.
     *
     * Returns null, never zero, when there is nothing to aggregate. Zero is a
     * number a user will act on; "no data" is not.
     *
     * @param  string  $aggregation  sum|avg|max|min|count
     * @return array{value: float|int|null, contributors: int, aggregation: string, measure: string}
     */
    public function rollUp(
        int $objectId,
        string $measureCode,
        ?int $periodId = null,
        string $aggregation = 'sum',
        ?User $user = null,
    ): array {
        $root = $this->find($objectId, $user);

        if ($root === null) {
            return ['value' => null, 'contributors' => 0, 'aggregation' => $aggregation, 'measure' => $measureCode];
        }

        $values = [];

        $collect = function (GraphObject $node) use (&$values, $measureCode, $periodId, $objectId) {
            $value = $this->measureValue($node, $measureCode, $periodId);

            if ($value === null) {
                return;
            }

            $values[] = $value * $this->contributionWeight($node, $objectId);
        };

        $collect($root);

        // Chunked rather than collected: a roll-up at the top of a large group
        // walks the whole tree, and holding every node in memory to add up one
        // number each is how a report times out on the biggest customer.
        $subtree = $this->descendantQuery($objectId, [], null, $user);

        $subtree?->orderBy('id')->chunkById(1000, function (Collection $nodes) use ($collect) {
            $nodes->each($collect);
        });

        if ($values === []) {
            return [
                'value' => null,
                'contributors' => 0,
                'aggregation' => $aggregation,
                'measure' => $measureCode,
            ];
        }

        $value = match ($aggregation) {
            'avg' => array_sum($values) / count($values),
            'max' => max($values),
            'min' => min($values),
            'count' => count($values),
            default => array_sum($values),
        };

        return [
            'value' => $value,
            'contributors' => count($values),
            'aggregation' => $aggregation,
            'measure' => $measureCode,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Internals */
    /* ------------------------------------------------------------------ */

    /**
     * One hop along a relationship, as ids.
     *
     * @param  list<int>  $fromIds
     * @return list<int>
     */
    private function step(array $fromIds, string $relationshipCode, string $direction): array
    {
        if ($fromIds === []) {
            return [];
        }

        $type = ObjectRelationshipType::resolve($relationshipCode);

        if ($type === null) {
            return [];
        }

        $query = DB::table('object_relationships')->where('relationship_type_id', $type->id);

        // Tenancy: object_relationships carries organization_id, and this is
        // the one place the query builder is used directly rather than through
        // a scoped model, so the filter is applied by hand.
        $organizationId = TenantContext::organizationIdOrNull();

        if ($organizationId !== null) {
            $query->where('organization_id', $organizationId);
        }

        return match ($direction) {
            'in' => $query->whereIn('to_object_id', $fromIds)->pluck('from_object_id')->map('intval')->all(),
            'both' => $query
                ->where(fn ($q) => $q->whereIn('from_object_id', $fromIds)->orWhereIn('to_object_id', $fromIds))
                ->get(['from_object_id', 'to_object_id'])
                ->flatMap(fn ($row) => [(int) $row->from_object_id, (int) $row->to_object_id])
                ->reject(fn (int $id) => in_array($id, $fromIds, true))
                ->unique()
                ->values()
                ->all(),
            default => $query->whereIn('from_object_id', $fromIds)->pluck('to_object_id')->map('intval')->all(),
        };
    }

    /**
     * The base query: tenant-scoped by the model, node-scoped by the caller's
     * pin on the org chart.
     */
    private function scoped(?User $user = null): Builder
    {
        $user ??= auth()->user();

        $query = GraphObject::query();

        if (! GraphScope::isSubtreeLimited($user)) {
            return $query;
        }

        $rootObjectId = $this->objectIdForScopeEntity($user);

        // Pinned to a node with no counterpart in the graph: fail closed.
        // Widening to the whole organization would silently undo the pin.
        if ($rootObjectId === null) {
            return $query->whereRaw('1 = 0');
        }

        $rootPath = GraphObject::withoutGlobalScopes()->whereKey($rootObjectId)->value('hierarchy_path')
            ?: '/'.$rootObjectId.'/';

        return $query->where(function (Builder $outer) use ($rootPath, $rootObjectId) {
            // In the subtree by path, or hanging off a node that is.
            $outer->where('hierarchy_path', 'like', $rootPath.'%')
                ->orWhereIn('node_id', GraphObject::withoutGlobalScopes()
                    ->where('hierarchy_path', 'like', $rootPath.'%')
                    ->select('id'))
                ->orWhere('id', $rootObjectId);
        });
    }

    /**
     * The graph node for the entity a user is pinned to.
     *
     * A merged business unit resolves to the entity's object, so this looks the
     * mapping up rather than assuming ids line up across the two models.
     */
    private function objectIdForScopeEntity(User $user): ?int
    {
        $id = DB::table('objects')
            ->where('source_model_type', 'entity')
            ->where('source_model_id', $user->scope_entity_id)
            ->whereNull('deleted_at')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    private function find(int $objectId, ?User $user = null): ?GraphObject
    {
        return $this->scoped($user)->whereKey($objectId)->first();
    }

    /**
     * @param  list<string>  $types
     */
    private function restrictToTypes(Builder $query, array $types): void
    {
        if ($types === []) {
            return;
        }

        $query->whereIn('object_type_id', ObjectType::query()->whereIn('code', $types)->select('id'));
    }

    /**
     * A measure is either a column on `objects` or a key in its attributes bag.
     *
     * Nothing is invented: a measure the object does not carry returns null and
     * the node simply does not contribute. periodId is accepted for the
     * period-aware measure model that WP-04 introduces; until that table
     * exists, a request for a specific period returns null rather than
     * silently handing back the current value labelled as a past one.
     */
    private function measureValue(GraphObject $object, string $measureCode, ?int $periodId): int|float|null
    {
        if ($periodId !== null) {
            return null;
        }

        $value = in_array($measureCode, ['hierarchy_depth', 'sort_order', 'version'], true)
            ? $object->getAttribute($measureCode)
            : $object->customAttribute($measureCode);

        return is_numeric($value) ? $value + 0 : null;
    }

    /**
     * The weight of a node's contribution to an ancestor's roll-up.
     *
     * Taken from a 'reports_to' or 'supports' edge where one carries a weight;
     * 1.0 otherwise, which is the honest default for a containment
     * relationship that nobody has apportioned.
     */
    private function contributionWeight(GraphObject $node, int $rootId): float
    {
        if ($node->id === $rootId) {
            return 1.0;
        }

        $weight = DB::table('object_relationships as r')
            ->join('object_relationship_types as t', 't.id', '=', 'r.relationship_type_id')
            ->where('r.from_object_id', $node->id)
            ->whereIn('t.code', ['reports_to', 'supports'])
            ->where('t.has_weight', true)
            ->value('r.weight');

        return $weight === null ? 1.0 : (float) $weight;
    }
}
