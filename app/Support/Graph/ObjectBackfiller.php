<?php

namespace App\Support\Graph;

use App\Services\Graph\ObjectSyncService;
use App\Support\MorphTypes;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Gives every pre-existing row the graph identity the trait would have given it
 * had it been created after WP-03.
 *
 * Runs the SAME ObjectSyncService the trait runs, deliberately. A backfill that
 * reimplements the mapping is a second definition of the truth, and the day it
 * drifts from the trait is the day half the graph is subtly different from the
 * other half depending on when a row was created.
 *
 * Also exposed as `php artisan graph:backfill`, because this has to be
 * re-runnable: adding a model to ObjectSourceMap later must not need a
 * migration.
 */
class ObjectBackfiller
{
    /**
     * Processing order. Types that others resolve THROUGH come first —
     * a control test reads its control's node, so controls must be done.
     *
     * @var list<class-string>
     */
    private const ORDER = [
        \App\Models\RiskCategory::class,
        \App\Models\Risk::class,
        \App\Models\Control::class,
        \App\Models\KeyRiskIndicator::class,
        \App\Models\Issue::class,
        \App\Models\LossEvent::class,
        \App\Models\NearMiss::class,
        \App\Models\RiskAppetite::class,
        \App\Models\AssessmentCampaign::class,
        \App\Models\TreatmentPlan::class,
        \App\Models\ControlTest::class,
        \App\Models\QuantificationScenario::class,
    ];

    /**
     * @param  list<class-string>|null  $only
     * @return array<string, int> model class => rows synced
     */
    public function run(?array $only = null, string $source = 'migration'): array
    {
        $classes = $only ?? self::ORDER;
        $synced = [];

        foreach ($classes as $class) {
            $synced[$class] = $this->backfillModel($class, $source);
        }

        $this->resolveSelfParents();

        // Parent links have just changed, so every path below them is stale.
        (new OrganisationGraphUnifier)->rebuildPaths();

        Log::info('Object graph backfilled', $synced);

        return $synced;
    }

    /**
     * @param  class-string  $class
     */
    private function backfillModel(string $class, string $source): int
    {
        if (! class_exists($class) || ObjectSourceMap::for($class) === null) {
            return 0;
        }

        $sync = app(ObjectSyncService::class);
        $count = 0;

        // Untenanted on purpose: this walks every organization, and the sync
        // service stamps each object with the organization_id of the row it
        // came from rather than of the ambient context.
        TenantContext::bypass(function () use ($class, $sync, $source, &$count) {
            $query = $class::query()->withoutGlobalScopes();

            if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($class), true)) {
                // Soft-deleted rows get a soft-deleted node, so the graph can
                // still answer historic questions without resurrecting them.
                $query->withTrashed();
            }

            $query->orderBy('id')->chunkById(500, function ($rows) use ($sync, $source, &$count) {
                foreach ($rows as $row) {
                    /** @var Model $row */
                    if ($row->getAttribute('organization_id') === null) {
                        continue;
                    }

                    $objectId = $sync->sync($row, $source);

                    if ($objectId === null) {
                        continue;
                    }

                    if (method_exists($row, 'trashed') && $row->trashed()) {
                        DB::table('objects')->where('id', $objectId)->update(['deleted_at' => $row->deleted_at]);
                    }

                    $count++;
                }
            });
        }, 'object graph backfill');

        return $count;
    }

    /**
     * Second pass for models that parent themselves.
     *
     * A risk's parent risk may have a higher id than the child, so the first
     * pass cannot always resolve it. Rather than sort topologically per model,
     * every self-parenting spec is re-resolved here in one query per table
     * once all the nodes exist.
     */
    private function resolveSelfParents(): void
    {
        foreach (ObjectSourceMap::all() as $class => $spec) {
            if ($spec['parent'] === null || ! class_exists($class)) {
                continue;
            }

            // The org tree is the unifier's, including the merge decisions it
            // recorded. Re-deriving it from business_units.parent_id here would
            // quietly undo a merge.
            if ($spec['is_node']) {
                continue;
            }

            $alias = MorphTypes::aliasFor($class);

            if ($alias === null) {
                continue;
            }

            $objectsBySource = DB::table('objects')
                ->where('source_model_type', $alias)
                ->pluck('id', 'source_model_id');

            $rows = DB::table($spec['table'])
                ->whereNotNull($spec['parent'])
                ->get(['id', $spec['parent'].' as parent_key']);

            foreach ($rows as $row) {
                $objectId = $objectsBySource[$row->id] ?? null;
                $parentObjectId = $objectsBySource[$row->parent_key] ?? null;

                if ($objectId === null || $parentObjectId === null || $objectId === $parentObjectId) {
                    continue;
                }

                DB::table('objects')->where('id', $objectId)->update(['parent_id' => $parentObjectId]);
            }
        }
    }
}
