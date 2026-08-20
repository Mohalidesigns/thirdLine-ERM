<?php

namespace App\Models\Concerns;

use App\Models\GraphObject;
use App\Models\ObjectRelationship;
use App\Models\ObjectRelationshipType;
use App\Services\Graph\ObjectSyncService;
use App\Support\MorphTypes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Gives a typed model an identity in the object graph.
 *
 * On save the model is mirrored into `objects`; on soft delete the mirror is
 * soft deleted with it. The typed table remains the source of truth for its own
 * columns — this only maintains the index that makes the graph traversable.
 *
 * A model using this trait may declare:
 *
 *   protected static string $objectTypeCode = 'Risk';
 *
 * but does not have to: ObjectSourceMap already knows the type and the column
 * mapping for every model listed in WP-03 TASK 3. The property exists for
 * models whose type is not one-to-one with their class, and
 * resolveObjectTypeCode() exists for models whose type varies per ROW —
 * Entity is the only one today.
 *
 * SYNC NEVER FAILS A SAVE. A degraded graph index is recoverable by re-running
 * ObjectBackfiller; a risk assessment lost because its index could not be
 * written is not.
 */
trait HasObjectIdentity
{
    public static function bootHasObjectIdentity(): void
    {
        static::saved(function (Model $model): void {
            $model->syncObjectIdentity();
        });

        static::deleted(function (Model $model): void {
            try {
                app(ObjectSyncService::class)->remove($model);
            } catch (\Throwable $exception) {
                Log::error('Graph node removal failed', [
                    'model' => get_class($model),
                    'id' => $model->getKey(),
                    'message' => $exception->getMessage(),
                ]);
            }
        });

        // Restoring is a save on a soft-deleting model, but the saved() hook
        // above fires before deleted_at is cleared on some paths; syncing again
        // here is idempotent and guarantees the node comes back.
        if (method_exists(static::class, 'restored')) {
            static::restored(function (Model $model): void {
                $model->syncObjectIdentity();
            });
        }
    }

    /**
     * Mirror this record into the graph now.
     *
     * @return int|null the object id, or null when the model is not mappable
     */
    public function syncObjectIdentity(string $source = 'ui'): ?int
    {
        try {
            return app(ObjectSyncService::class)->sync($this, $source);
        } catch (\Throwable $exception) {
            Log::error('Graph sync failed', [
                'model' => get_class($this),
                'id' => $this->getKey(),
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Relations */
    /* ------------------------------------------------------------------ */

    /**
     * This record's node in the object graph.
     */
    public function object(): HasOne
    {
        // Constrained by source_model_type as well as the key, so the
        // (source_model_type, source_model_id) index is the one used and a
        // risk cannot pick up a control's node by id collision.
        return $this->hasOne(GraphObject::class, 'source_model_id', $this->getKeyName())
            ->where('source_model_type', $this->objectMorphAlias());
    }

    /**
     * The org-graph node this record hangs off.
     *
     * Present only on tables that carry node_id. Readers should prefer this
     * over entity_id / business_unit_id; those stay for one release as the
     * fallback while the merge review queue is worked through.
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(GraphObject::class, 'node_id');
    }

    /**
     * The node id, falling back to the legacy columns for one release.
     *
     * A row created before the unification migration, or by code that has not
     * been moved to node_id yet, still resolves — through entity_id first,
     * because entities are the model the scoping UI writes.
     */
    public function resolvedNodeId(): ?int
    {
        if ($this->getAttribute('node_id') !== null) {
            return (int) $this->getAttribute('node_id');
        }

        foreach (['entity' => 'entity_id', 'business_unit' => 'business_unit_id'] as $alias => $column) {
            $legacyId = $this->getAttribute($column);

            if ($legacyId === null) {
                continue;
            }

            $objectId = GraphObject::query()
                ->where('source_model_type', $alias)
                ->where('source_model_id', $legacyId)
                ->value('id');

            if ($objectId !== null) {
                return (int) $objectId;
            }
        }

        return null;
    }

    /* ------------------------------------------------------------------ */
    /*  Edges */
    /* ------------------------------------------------------------------ */

    /**
     * Assert a typed relationship from this record to another.
     *
     * Idempotent: the same edge asserted twice updates the first rather than
     * failing on the uniqueness constraint, which is what an importer replaying
     * a file needs.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function relate(string $relationshipCode, Model|GraphObject $target, array $attributes = [], float $weight = 1.0): ?ObjectRelationship
    {
        $from = $this->graphObject();
        $to = $target instanceof GraphObject ? $target : $this->graphObjectFor($target);
        $type = ObjectRelationshipType::resolve($relationshipCode);

        if ($from === null || $to === null || $type === null) {
            Log::warning('Could not create graph relationship', [
                'code' => $relationshipCode,
                'from' => $from?->id,
                'to' => $to?->id,
                'type_found' => $type !== null,
            ]);

            return null;
        }

        if (! $type->permits((int) $from->object_type_id, (int) $to->object_type_id)) {
            Log::warning('Refused a graph relationship the type does not permit', [
                'code' => $relationshipCode,
                'from_type_id' => $from->object_type_id,
                'to_type_id' => $to->object_type_id,
            ]);

            return null;
        }

        return ObjectRelationship::updateOrCreate(
            [
                'relationship_type_id' => $type->id,
                'from_object_id' => $from->id,
                'to_object_id' => $to->id,
            ],
            [
                'organization_id' => $from->organization_id,
                'weight' => $type->has_weight ? $weight : 1.0,
                'attributes' => $attributes === [] ? null : $attributes,
                'created_by' => auth()->id(),
            ]
        );
    }

    /**
     * Retract a typed relationship this record asserted.
     *
     * The inverse of relate(), and deliberately NOT the inverse of deleting
     * the record itself: ObjectSyncService::remove() leaves edges standing
     * when a node is soft deleted, because "what mitigated this risk" is
     * exactly the question an examiner asks about a retired risk. This is for
     * the other case — somebody explicitly unlinked two things, so the claim
     * that they are linked is withdrawn.
     *
     * Neither endpoint is created on demand here: there is nothing to retract
     * from a node that was never written.
     *
     * @return int edges removed
     */
    public function unrelate(string $relationshipCode, Model|GraphObject $target): int
    {
        $sync = app(ObjectSyncService::class);

        $from = $sync->objectFor($this);
        $to = $target instanceof GraphObject ? $target : $sync->objectFor($target);
        $type = ObjectRelationshipType::resolve($relationshipCode);

        if ($from === null || $to === null || $type === null) {
            return 0;
        }

        return ObjectRelationship::query()
            ->where('relationship_type_id', $type->id)
            ->where('from_object_id', $from->id)
            ->where('to_object_id', $to->id)
            ->delete();
    }

    /**
     * The objects on the far end of a named relationship.
     *
     * Direction 'out' follows the edge as declared; 'in' follows it backwards,
     * which is how you ask "what mitigates this risk" from the risk rather than
     * from the control.
     *
     * @return \Illuminate\Support\Collection<int, GraphObject>
     */
    public function related(string $relationshipCode, string $direction = 'out'): \Illuminate\Support\Collection
    {
        $object = $this->graphObject();

        if ($object === null) {
            return collect();
        }

        $near = $direction === 'in' ? 'to_object_id' : 'from_object_id';
        $far = $direction === 'in' ? 'from_object_id' : 'to_object_id';

        return GraphObject::query()
            ->whereIn('id', ObjectRelationship::query()
                ->ofType($relationshipCode)
                ->where($near, $object->id)
                ->select($far))
            ->get();
    }

    /* ------------------------------------------------------------------ */
    /*  Internals */
    /* ------------------------------------------------------------------ */

    /**
     * This record's graph node, created on demand if the mirror is missing.
     */
    public function graphObject(): ?GraphObject
    {
        $object = app(ObjectSyncService::class)->objectFor($this);

        if ($object !== null) {
            return $object;
        }

        $id = $this->syncObjectIdentity();

        return $id === null ? null : GraphObject::query()->find($id);
    }

    private function graphObjectFor(Model $model): ?GraphObject
    {
        if (method_exists($model, 'graphObject')) {
            return $model->graphObject();
        }

        return app(ObjectSyncService::class)->objectFor($model);
    }

    private function objectMorphAlias(): string
    {
        return MorphTypes::aliasFor(static::class) ?? Str::snake(class_basename(static::class));
    }
}
