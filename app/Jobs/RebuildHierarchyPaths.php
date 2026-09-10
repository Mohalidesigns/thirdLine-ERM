<?php

namespace App\Jobs;

use App\Models\GraphObject;
use App\Support\Graph\OrganisationGraphUnifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Re-materialise hierarchy_path and hierarchy_depth across the object graph.
 *
 * ObjectSyncService cascades paths inline when a node is saved, which is right
 * for the handful of children one save touches. Re-parenting a division with
 * ten thousand descendants under a different subsidiary is not that: it is a
 * bulk rewrite, and doing it inside the request that moved the node would time
 * the request out and leave half the tree with stale paths.
 *
 * Also the repair path. Paths are an authorization input — GraphQueryService
 * scopes on them — so "rebuild them all and be certain" has to be one command
 * away.
 *
 * Scope it to one organization where you can; the whole-graph form exists for
 * the migration and for the repair command.
 */
class RebuildHierarchyPaths implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        public readonly ?int $organizationId = null,
        public readonly ?int $rootObjectId = null,
    ) {}

    public function handle(): void
    {
        if ($this->organizationId === null && $this->rootObjectId === null) {
            // Whole graph: the unifier already does this correctly, including
            // breaking any cycle it finds rather than spinning on it.
            (new OrganisationGraphUnifier)->rebuildPaths();

            return;
        }

        TenantContext::actingAs($this->organizationId, function () {
            $rows = $this->subtreeRows();

            if ($rows === []) {
                return;
            }

            $paths = [];
            $depths = [];
            $pending = $rows;
            $guard = 0;

            while ($pending !== [] && $guard++ < 1000) {
                $progressed = false;

                foreach ($pending as $id => $parentId) {
                    if ($parentId === null || ! array_key_exists($parentId, $rows)) {
                        // A parent outside the subtree being rebuilt: read its
                        // path rather than treating this node as a root, which
                        // would detach the subtree from everything above it.
                        $parentPath = $parentId === null
                            ? '/'
                            : (GraphObject::withoutGlobalScopes()->whereKey($parentId)->value('hierarchy_path') ?: '/');

                        $paths[$id] = $parentPath.$id.'/';
                        $depths[$id] = max(0, substr_count($paths[$id], '/') - 2);
                    } elseif (isset($paths[$parentId])) {
                        $paths[$id] = $paths[$parentId].$id.'/';
                        $depths[$id] = $depths[$parentId] + 1;
                    } else {
                        continue;
                    }

                    unset($pending[$id]);
                    $progressed = true;
                }

                if (! $progressed) {
                    foreach ($pending as $id => $parentId) {
                        DB::table('objects')->where('id', $id)->update(['parent_id' => null]);
                        $paths[$id] = "/{$id}/";
                        $depths[$id] = 0;

                        Log::error('Cycle detected while rebuilding paths; parent link broken', [
                            'object_id' => $id,
                            'parent_id' => $parentId,
                        ]);
                    }

                    break;
                }
            }

            foreach (array_chunk($paths, 500, true) as $chunk) {
                foreach ($chunk as $id => $path) {
                    DB::table('objects')->where('id', $id)->update([
                        'hierarchy_path' => $path,
                        'hierarchy_depth' => $depths[$id],
                    ]);
                }
            }

            Log::info('Hierarchy paths rebuilt', [
                'organization_id' => $this->organizationId,
                'root_object_id' => $this->rootObjectId,
                'nodes' => count($paths),
            ]);
        });
    }

    /**
     * @return array<int, int|null> id => parent id
     */
    private function subtreeRows(): array
    {
        $query = GraphObject::query()->withTrashed();

        if ($this->rootObjectId !== null) {
            $root = GraphObject::query()->withTrashed()->find($this->rootObjectId);

            if ($root === null) {
                return [];
            }

            // Match on the path the subtree HAD, since that is what its
            // descendants still carry until this job rewrites them.
            $query->where(fn ($q) => $q
                ->where('hierarchy_path', 'like', $root->pathOrFallback().'%')
                ->orWhereKey($root->getKey()));
        }

        return $query->orderBy('id')
            ->get(['id', 'parent_id'])
            ->mapWithKeys(fn (GraphObject $row) => [(int) $row->id => $row->parent_id === null ? null : (int) $row->parent_id])
            ->all();
    }
}
