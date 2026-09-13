<?php

namespace App\Services\Graph;

use App\Models\ObjectRelationship;
use App\Support\Graph\PivotEdgeMap;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Keeps `object_relationships` in step with the pivot tables at RUNTIME.
 *
 * Until this existed, object_relationships was written by exactly one thing —
 * the backfill — so the graph was frozen at whatever the last backfill saw.
 * Every link a user made afterwards went into the pivot and nowhere else, and
 * the network widget showed a neighbourhood that was months out of date while
 * looking perfectly healthy.
 *
 * The fix is deliberately NOT "call this from the controllers". There are five
 * call sites today and there will be more, and one of them will be forgotten.
 * The pivot models themselves carry ProjectsGraphEdge, so the projection is a
 * property of writing the row rather than of remembering to.
 *
 * PROJECTION NEVER FAILS THE WRITE. Same rule as ObjectSyncService: a degraded
 * graph index is repairable by re-running PivotRelationshipMigrator; a control
 * mapping lost because its edge could not be written is not.
 */
class PivotEdgeProjector
{
    /**
     * Assert the edge a pivot row stands for.
     *
     * Idempotent — it goes through HasObjectIdentity::relate(), which is an
     * updateOrCreate on (type, from, to). Creating the same mapping twice, or
     * running the backfill over rows this already projected, converges on one
     * edge rather than two.
     */
    public function project(Model $pivot): ?ObjectRelationship
    {
        $spec = PivotEdgeMap::for($pivot->getTable());

        if ($spec === null) {
            return null;
        }

        try {
            [$from, $to] = $this->endpoints($spec, $pivot->getAttributes());

            if ($from === null || $to === null) {
                return null;
            }

            return $from->relate(
                $spec['relationship_code'],
                $to,
                PivotEdgeMap::attributesFrom($spec, $pivot),
                PivotEdgeMap::weightFrom($spec, $pivot),
            );
        } catch (\Throwable $exception) {
            Log::error('Pivot edge projection failed', [
                'table' => $pivot->getTable(),
                'id' => $pivot->getKey(),
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Withdraw the edge a pivot row stood for.
     *
     * $values is passed explicitly because the delete hook has to read the
     * endpoints the row held, and on an update-that-moved-an-endpoint it has
     * to read the ORIGINAL ones — the model's current attributes are already
     * the new pair by then.
     *
     * @param  array<string, mixed>|null  $values
     */
    public function retract(Model $pivot, ?array $values = null): int
    {
        $spec = PivotEdgeMap::for($pivot->getTable());

        if ($spec === null) {
            return 0;
        }

        try {
            [$from, $to] = $this->endpoints($spec, $values ?? $pivot->getAttributes());

            if ($from === null || $to === null) {
                return 0;
            }

            return $from->unrelate($spec['relationship_code'], $to);
        } catch (\Throwable $exception) {
            Log::error('Pivot edge retraction failed', [
                'table' => $pivot->getTable(),
                'id' => $pivot->getKey(),
                'message' => $exception->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Load the two typed records a pivot row joins.
     *
     * POLICY FOR A MISSING OBJECT IDENTITY, in two parts:
     *
     *  1. The typed ROW is missing, or is invisible to the current tenant.
     *     Skip and log. There is no honest edge to write — writing one would
     *     mean inventing an endpoint, and writing a half-edge is worse than
     *     writing none because the graph would then contain a link that
     *     resolves to nothing on one side.
     *
     *  2. The typed row exists but has no `objects` row yet — a mapping
     *     created inside the same transaction as its risk, or a record that
     *     predates HasObjectIdentity. CREATE the identity, do not skip.
     *     relate() does this for us: graphObject() falls back to
     *     syncObjectIdentity(), which is the same code the backfill runs. The
     *     alternative — skipping — leaves a pivot row with no edge and no
     *     retry, which is precisely the silent drift this class exists to
     *     stop. If sync itself cannot produce a node (the model is not in
     *     ObjectSourceMap, or has no organization), relate() logs and returns
     *     null; nothing is thrown and no broken edge is written.
     *
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $values
     * @return array{0: Model|null, 1: Model|null}
     */
    private function endpoints(array $spec, array $values): array
    {
        [$fromId, $toId] = PivotEdgeMap::endpointKeys($spec, $values);

        return [
            $this->endpoint($spec['from'], $fromId, $spec['relationship_code']),
            $this->endpoint($spec['to'], $toId, $spec['relationship_code']),
        ];
    }

    /**
     * @param  array<string, mixed>  $side
     */
    private function endpoint(array $side, ?int $key, string $relationshipCode): ?Model
    {
        if ($key === null) {
            return null;
        }

        /** @var class-string<Model> $class */
        $class = $side['model'];

        $model = $class::query()->find($key);

        if ($model === null) {
            Log::warning('Pivot edge skipped: endpoint record not found', [
                'relationship' => $relationshipCode,
                'alias' => $side['alias'],
                'source_id' => $key,
            ]);

            return null;
        }

        // relate()/unrelate() need the trait; a pivot pointed at a model that
        // has no graph identity at all is a mapping error, not a data error.
        if (! method_exists($model, 'relate')) {
            Log::warning('Pivot edge skipped: endpoint model has no graph identity', [
                'relationship' => $relationshipCode,
                'model' => $class,
            ]);

            return null;
        }

        return $model;
    }
}
