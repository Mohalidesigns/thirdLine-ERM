<?php

namespace App\Services\Graph;

use App\Models\GraphObject;
use App\Models\ObjectType;
use App\Support\Graph\ObjectSourceMap;
use App\Support\MorphTypes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Keeps the `objects` index in step with the typed tables.
 *
 * Called by HasObjectIdentity on every save, and by the backfill migration for
 * rows that predate the trait. Both paths run the same code, so a row created
 * in 2026 and a row created tonight produce the same graph node.
 *
 * WHAT THIS DOES NOT DO: it never writes back to the typed table (beyond the
 * resolved node_id) and it never fails a save. A graph index that cannot be
 * written is a degraded index; a domain write that fails because the index
 * could not be written is a lost risk assessment. Failures are logged and
 * swallowed, and ObjectBackfiller repairs them.
 */
class ObjectSyncService
{
    /** @var array<string, int|null> memoised "org:code" => object_types.id */
    private array $typeCache = [];

    /**
     * Mirror a model into the graph. Returns the object id, or null when the
     * model is not mappable.
     */
    public function sync(Model $model, string $source = 'ui'): ?int
    {
        $spec = $this->specFor($model);

        if ($spec === null) {
            return null;
        }

        $organizationId = $model->getAttribute('organization_id');

        if ($organizationId === null) {
            // Rule 2: a null organization_id is never a thing to paper over.
            // Nothing untenanted belongs in a tenant's graph.
            Log::warning('Skipped graph sync for a model with no organization', [
                'model' => get_class($model),
                'id' => $model->getKey(),
            ]);

            return null;
        }

        $typeId = $this->resolveTypeId($model, $spec, (int) $organizationId);

        if ($typeId === null) {
            Log::warning('Skipped graph sync: object type not registered', [
                'model' => get_class($model),
                'type_code' => $this->typeCodeFor($model, $spec),
            ]);

            return null;
        }

        $alias = MorphTypes::aliasFor(get_class($model)) ?? Str::snake(class_basename($model));

        $payload = [
            'organization_id' => (int) $organizationId,
            'object_type_id' => $typeId,
            'code' => $this->resolveCode($model, $spec),
            'name' => Str::limit((string) ($this->value($model, $spec['name']) ?? 'Unnamed'), 495, ''),
            'description' => $this->value($model, $spec['description']),
            'owner_id' => $this->value($model, $spec['owner']),
            'delegate_owner_id' => $this->value($model, $spec['delegate_owner']),
            'lifecycle_state' => $this->resolveLifecycleState($model, $spec),
            'status' => $this->value($model, $spec['status']) ?? $this->resolveLifecycleState($model, $spec),
            'effective_from' => $this->dateValue($model, $spec['effective_from']),
            'effective_to' => $this->dateValue($model, $spec['effective_to']),
            'parent_id' => $this->resolveParentObjectId($model, $spec),
            'node_id' => $this->resolveNodeId($model, $spec),
            'source_model_type' => $alias,
            'source_model_id' => (int) $model->getKey(),
        ];

        $existing = GraphObject::query()
            ->where('source_model_type', $alias)
            ->where('source_model_id', $model->getKey())
            ->withTrashed()
            ->first();

        if ($existing === null) {
            $object = new GraphObject;
            $object->forceFill($payload + [
                'version' => 1,
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]);
            $object->uuid = (string) Str::uuid();
            $object->saveQuietly();

            $this->applyHierarchy($object);
            $this->recordVersion($object, $source, 'created');

            $this->stampNodeOnSource($model, $spec, $object);

            return $object->id;
        }

        $changed = $this->differences($existing, $payload);

        if ($changed === []) {
            $this->stampNodeOnSource($model, $spec, $existing);

            return $existing->id;
        }

        $existing->forceFill($payload);
        $existing->deleted_at = null;
        $existing->version = (int) $existing->version + 1;
        $existing->updated_by = auth()->id();
        $existing->saveQuietly();

        $this->applyHierarchy($existing);
        $this->recordVersion($existing, $source, 'updated: '.implode(', ', array_keys($changed)));

        $this->stampNodeOnSource($model, $spec, $existing);

        return $existing->id;
    }

    /**
     * Soft delete the graph node when the typed record is soft deleted.
     *
     * Edges are left alone: the history of what mitigated what is the point of
     * having the graph, and a deleted risk's mitigations are exactly what an
     * examiner asks about.
     */
    public function remove(Model $model): void
    {
        $alias = MorphTypes::aliasFor(get_class($model)) ?? Str::snake(class_basename($model));

        GraphObject::query()
            ->where('source_model_type', $alias)
            ->where('source_model_id', $model->getKey())
            ->update(['deleted_at' => now()]);
    }

    /**
     * The object row for a model, if it has one.
     */
    public function objectFor(Model $model): ?GraphObject
    {
        $alias = MorphTypes::aliasFor(get_class($model)) ?? Str::snake(class_basename($model));

        return GraphObject::query()
            ->where('source_model_type', $alias)
            ->where('source_model_id', $model->getKey())
            ->first();
    }

    /* ------------------------------------------------------------------ */
    /*  Resolution */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>|null
     */
    private function specFor(Model $model): ?array
    {
        return ObjectSourceMap::for(get_class($model));
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function typeCodeFor(Model $model, array $spec): string
    {
        // A model whose type varies per row — an entity is a Group or a Branch
        // depending on its entity_type_id — answers for itself.
        if (method_exists($model, 'resolveObjectTypeCode')) {
            $code = $model->resolveObjectTypeCode();

            if (is_string($code) && $code !== '') {
                return $code;
            }
        }

        return $spec['type_code'];
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function resolveTypeId(Model $model, array $spec, int $organizationId): ?int
    {
        $code = $this->typeCodeFor($model, $spec);
        $key = $organizationId.':'.$code;

        if (! array_key_exists($key, $this->typeCache)) {
            $id = DB::table('object_types')
                ->where('code', $code)
                ->where(fn ($query) => $query->where('organization_id', $organizationId)->orWhereNull('organization_id'))
                // A tenant's own definition of a type wins over the system one.
                ->orderByRaw('CASE WHEN organization_id IS NULL THEN 1 ELSE 0 END')
                ->value('id');

            $this->typeCache[$key] = $id === null ? null : (int) $id;
        }

        return $this->typeCache[$key];
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function resolveCode(Model $model, array $spec): string
    {
        $code = $spec['code'] === null ? null : $this->value($model, $spec['code']);

        if ($code === null || trim((string) $code) === '') {
            // Tables with no reference column of their own (risk_appetite) get
            // a code derived from the primary key, which is stable and unique.
            $code = $spec['code_prefix'].'-'.$model->getKey();
        }

        return Str::limit((string) $code, 64, '');
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function resolveLifecycleState(Model $model, array $spec): ?string
    {
        if ($spec['lifecycle_state'] !== null) {
            $value = $this->value($model, $spec['lifecycle_state']);

            return $value === null ? null : (string) $value;
        }

        if ($spec['active_flag'] !== null) {
            return $this->value($model, $spec['active_flag']) ? 'active' : 'inactive';
        }

        return null;
    }

    /**
     * The org-graph node this record hangs off.
     *
     * entity_id wins over business_unit_id — entities are the richer model and
     * what the scoping UI writes. A record that resolves through its parent (a
     * control test through its control) reads the parent's already-resolved
     * node rather than repeating the lookup.
     *
     * @param  array<string, mixed>  $spec
     */
    private function resolveNodeId(Model $model, array $spec): ?int
    {
        // An org node owns itself.
        if ($spec['is_node']) {
            $own = $this->objectFor($model);

            if ($own !== null) {
                return $own->id;
            }
        }

        if ($spec['entity'] !== null && ($entityId = $this->value($model, $spec['entity'])) !== null) {
            $nodeId = $this->objectIdForSource('entity', (int) $entityId);

            if ($nodeId !== null) {
                return $nodeId;
            }
        }

        if ($spec['business_unit'] !== null && ($unitId = $this->value($model, $spec['business_unit'])) !== null) {
            $nodeId = $this->objectIdForSource('business_unit', (int) $unitId);

            if ($nodeId !== null) {
                return $nodeId;
            }
        }

        if ($spec['node_via'] !== null) {
            $parentKey = $this->value($model, $spec['node_via']['key']);

            if ($parentKey !== null) {
                $nodeId = DB::table($spec['node_via']['table'])->where('id', $parentKey)->value('node_id');

                if ($nodeId !== null) {
                    return (int) $nodeId;
                }
            }
        }

        // Already resolved on a previous save and nothing has changed: keep it
        // rather than nulling a node the record legitimately has.
        $current = $model->getAttribute('node_id');

        return $current === null ? null : (int) $current;
    }

    /**
     * Objects created before their business unit was imported would otherwise
     * be the only nodeless rows in the graph. A merged business unit resolves
     * to the entity's object, which is why this looks the id up rather than
     * assuming source_model_id and node id line up.
     */
    private function objectIdForSource(string $alias, int $sourceId): ?int
    {
        $id = DB::table('objects')
            ->where('source_model_type', $alias)
            ->where('source_model_id', $sourceId)
            ->whereNull('deleted_at')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function resolveParentObjectId(Model $model, array $spec): ?int
    {
        if ($spec['parent'] !== null) {
            $parentKey = $this->value($model, $spec['parent']);

            if ($parentKey !== null) {
                $alias = MorphTypes::aliasFor(get_class($model)) ?? Str::snake(class_basename($model));
                $parentId = $this->objectIdForSource($alias, (int) $parentKey);

                if ($parentId !== null) {
                    return $parentId;
                }
            }
        }

        // An org node with no parent of its own sits under whatever owns it —
        // a business process under its business unit. Without this a process
        // would be a root of its own and would vanish from every subtree query
        // run against the unit it plainly belongs to.
        if ($spec['is_node']) {
            foreach ([['entity', $spec['entity']], ['business_unit', $spec['business_unit']]] as [$alias, $column]) {
                if ($column === null) {
                    continue;
                }

                $ownerKey = $this->value($model, $column);

                if ($ownerKey === null) {
                    continue;
                }

                $ownerId = $this->objectIdForSource($alias, (int) $ownerKey);

                if ($ownerId !== null) {
                    return $ownerId;
                }
            }
        }

        return null;
    }

    /**
     * Write the resolved node back onto the typed row so controllers, exports
     * and the authorization layer can read one column instead of three.
     *
     * saveQuietly-equivalent by design: this is an UPDATE through the query
     * builder, so it cannot re-enter the model's saved() hook and recurse.
     *
     * @param  array<string, mixed>  $spec
     */
    private function stampNodeOnSource(Model $model, array $spec, GraphObject $object): void
    {
        $nodeId = $spec['is_node'] ? $object->id : $object->node_id;

        if ($nodeId === null) {
            return;
        }

        if (! \Illuminate\Support\Facades\Schema::hasColumn($model->getTable(), 'node_id')) {
            return;
        }

        if ((int) ($model->getAttribute('node_id') ?? 0) === (int) $nodeId) {
            return;
        }

        DB::table($model->getTable())->where($model->getKeyName(), $model->getKey())->update(['node_id' => $nodeId]);
        $model->setAttribute('node_id', $nodeId);
        $model->syncOriginalAttribute('node_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Hierarchy and versioning */
    /* ------------------------------------------------------------------ */

    /**
     * Materialise this object's path, then cascade to anything beneath it.
     *
     * Cascading inline is correct for the handful of children a single save
     * touches. Re-parenting a division with ten thousand descendants is what
     * RebuildHierarchyPaths is for.
     */
    public function applyHierarchy(GraphObject $object): void
    {
        $this->materialisePath($object, []);
    }

    /**
     * The recursive half of applyHierarchy, carrying the ancestors already
     * walked on THIS branch.
     *
     * `objects.parent_id` is not guaranteed acyclic. It mirrors parent columns
     * on the typed tables, and any of them — a `business_units.parent_id`
     * written by a seeder, an importer or a direct UPDATE — can name a row
     * that is already beneath it. Without $visited that makes this method walk
     * A -> B -> A forever, appending a segment to hierarchy_path each time,
     * until PHP runs out of memory and takes the request down with it. The
     * save that triggered it has already committed by then, so the process
     * dies AFTER the domain write rather than instead of it.
     *
     * The cycle is logged and the walk stops. Deliberately NOT repaired here:
     * this service's contract is that it never writes back to anything but the
     * index, and silently nulling a parent somebody just set hides the
     * problem. RebuildHierarchyPaths breaks the link and is one command away
     * (`graph:backfill`); RejectsParentCycles stops the cycle reaching the
     * typed table in the first place.
     *
     * @param  array<int, true>  $visited  ancestors on this branch, id => true
     */
    private function materialisePath(GraphObject $object, array $visited): void
    {
        $id = (int) $object->getKey();

        if (isset($visited[$id])) {
            Log::error('Cycle detected while materialising hierarchy paths; walk stopped', [
                'object_id' => $id,
                'parent_id' => $object->parent_id,
                'branch' => array_keys($visited),
            ]);

            return;
        }

        $visited[$id] = true;

        $parentPath = null;
        $parentDepth = -1;

        if ($object->parent_id !== null) {
            $parent = GraphObject::query()
                ->whereKey($object->parent_id)
                ->first(['id', 'hierarchy_path', 'hierarchy_depth']);

            if ($parent !== null) {
                $parentPath = $parent->pathOrFallback();
                $parentDepth = (int) $parent->hierarchy_depth;
            }
        }

        $path = ($parentPath ?? '/').$object->getKey().'/';
        $depth = $parentDepth + 1;

        // A node type owns itself; anything else keeps whatever node it
        // resolved to.
        $nodeId = $object->node_id;

        if ($nodeId === null && $object->objectType?->is_node_type) {
            $nodeId = $object->id;
        }

        if ($object->hierarchy_path !== $path || (int) $object->hierarchy_depth !== $depth || $object->node_id !== $nodeId) {
            DB::table('objects')->where('id', $object->id)->update([
                'hierarchy_path' => $path,
                'hierarchy_depth' => $depth,
                'node_id' => $nodeId,
            ]);

            $object->hierarchy_path = $path;
            $object->hierarchy_depth = $depth;
            $object->node_id = $nodeId;
        }

        $children = GraphObject::query()
            ->where('parent_id', $object->id)
            ->get();

        foreach ($children as $child) {
            $this->materialisePath($child, $visited);
        }
    }

    private function recordVersion(GraphObject $object, string $source, string $reason): void
    {
        DB::table('object_versions')->insert([
            'object_id' => $object->id,
            'version' => $object->version,
            'snapshot' => json_encode($object->only([
                'code', 'name', 'description', 'owner_id', 'delegate_owner_id',
                'lifecycle_state', 'status', 'node_id', 'parent_id',
                'effective_from', 'effective_to', 'attributes',
            ])),
            'changed_by' => auth()->id(),
            'changed_at' => now(),
            'change_reason' => Str::limit($reason, 495, ''),
            'source' => in_array($source, ['ui', 'api', 'import', 'job', 'migration'], true) ? $source : 'ui',
        ]);
    }

    /**
     * Which mirrored fields actually changed. Used to avoid writing a version
     * row on every save of an unrelated column.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function differences(GraphObject $existing, array $payload): array
    {
        $changed = [];

        foreach ($payload as $key => $value) {
            $current = $existing->getAttribute($key);

            if ($current instanceof \DateTimeInterface) {
                $current = $current->format('Y-m-d');
            }

            if ((string) $current !== (string) $value) {
                $changed[$key] = $value;
            }
        }

        if ($existing->trashed()) {
            $changed['deleted_at'] = null;
        }

        return $changed;
    }

    /* ------------------------------------------------------------------ */
    /*  Column readers */
    /* ------------------------------------------------------------------ */

    private function value(Model $model, ?string $column): mixed
    {
        if ($column === null) {
            return null;
        }

        // Only read columns that exist: the domain tables carry deprecated and
        // canonical spellings side by side, and a spec written against one
        // schema must not fatal on an install that is mid-upgrade.
        if (! array_key_exists($column, $model->getAttributes())) {
            return null;
        }

        return $model->getAttribute($column);
    }

    private function dateValue(Model $model, ?string $column): ?string
    {
        $value = $this->value($model, $column);

        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        try {
            return \Illuminate\Support\Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The lifecycle governing a type, if one is configured.
     */
    public function lifecycleFor(ObjectType $type): ?\App\Models\ObjectLifecycle
    {
        return $type->defaultLifecycle
            ?? $type->lifecycles()->orderBy('id')->first();
    }
}
