<?php

namespace App\Models\Concerns;

use App\Services\Graph\PivotEdgeProjector;
use App\Support\Graph\PivotEdgeMap;
use Illuminate\Database\Eloquent\Model;

/**
 * A pivot row IS an edge; this makes it one.
 *
 * The graph's whole value is that it is current. It was not: object_
 * relationships had exactly one writer, the backfill, so every link created
 * after the last backfill existed in the typed pivot and nowhere else.
 *
 * The hook lives on the MODEL rather than in the five controllers that write
 * these tables, on purpose. A controller-side call is correct only for as long
 * as everyone remembers to make it, and the next person to add a "link control
 * to risk" endpoint will not know they were supposed to. Here, the projection
 * is a consequence of the row existing.
 *
 * What the model still owes: it must be the class the write actually goes
 * through. RiskControlMapping::create() in a controller does; a belongsToMany
 * attach() only does if the relation declares ->using($model), which is why
 * Risk::controls(), Control::risks(), Risk::kriMappings() and
 * KeyRiskIndicator::risks() now do. The one remaining bypass is a raw
 * DB::table()->insert(), which no model hook can see — those are the rows
 * `schema:audit-deprecated` and the backfill exist to catch.
 *
 * The spec (which relationship code, which direction, which attributes) is
 * NOT declared here: it is looked up in PivotEdgeMap by table name, the same
 * array PivotRelationshipMigrator reads. A model that uses this trait and is
 * not in the map projects nothing, rather than guessing.
 */
trait ProjectsGraphEdge
{
    public static function bootProjectsGraphEdge(): void
    {
        static::created(function (Model $pivot): void {
            app(PivotEdgeProjector::class)->project($pivot);
        });

        // An edit that moves an endpoint (re-pointing a mapping at a different
        // control) is a delete and an insert as far as the graph is concerned;
        // an edit that only changes weight or rationale converges through
        // relate()'s updateOrCreate.
        static::updated(function (Model $pivot): void {
            $projector = app(PivotEdgeProjector::class);
            $spec = PivotEdgeMap::for($pivot->getTable());

            if ($spec !== null) {
                $columns = [$spec['from']['column'], $spec['to']['column']];

                if (array_intersect($columns, array_keys($pivot->getChanges())) !== []) {
                    $projector->retract($pivot, $pivot->getOriginal());
                }
            }

            $projector->project($pivot);
        });

        static::deleted(function (Model $pivot): void {
            // A soft delete of the pivot is still "this link no longer
            // applies", so it retracts the edge either way; restored() below
            // puts it back.
            app(PivotEdgeProjector::class)->retract($pivot);
        });

        if (method_exists(static::class, 'restored')) {
            static::restored(function (Model $pivot): void {
                app(PivotEdgeProjector::class)->project($pivot);
            });
        }
    }
}
