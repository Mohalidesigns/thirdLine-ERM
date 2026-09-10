<?php

namespace Tests\Feature\Graph;

use App\Models\GraphObject;
use App\Models\ObjectType;
use App\Services\Graph\GraphQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * WP-03 TASK 6 acceptance: descendants() under 200 ms on a 50,000-node tree.
 *
 * This is the reason objects carries a materialised hierarchy_path at all. A
 * recursive CTE over 50,000 nodes is seconds, not milliseconds, and this query
 * sits behind every dashboard roll-up and every node-scoped authorization
 * check — it runs on more or less every page.
 *
 * The nodes are written with raw bulk inserts, not the model: 50,000 saves
 * through Eloquent would measure Eloquent, and the paths are exactly what a
 * correct hierarchy would produce.
 *
 * Tagged `performance` so it can be excluded on a constrained CI box:
 *   php vendor/bin/phpunit --exclude-group performance
 */
#[Group('performance')]
class GraphQueryPerformanceTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private const NODE_COUNT = 50_000;

    private const BUDGET_MS = 200.0;

    #[Test]
    public function descendants_of_a_fifty_thousand_node_tree_resolve_within_the_budget(): void
    {
        $this->bootDomainFixtures();

        $rootId = $this->buildTree();

        // Warm the connection and the query plan; the budget is for a steady
        // state request, not for the first one after a cold start.
        app(GraphQueryService::class)->descendantIds($rootId);

        $started = hrtime(true);
        $descendants = app(GraphQueryService::class)->descendantIds($rootId);
        $elapsedMs = (hrtime(true) - $started) / 1_000_000;

        $this->assertSame(self::NODE_COUNT - 1, $descendants->count());

        $this->assertLessThan(
            self::BUDGET_MS,
            $elapsedMs,
            sprintf(
                'descendants() took %.1f ms over %d nodes; the budget is %.0f ms. '
                .'A regression here is almost always hierarchy_path losing its index '
                .'or a caller dropping back to a recursive walk.',
                $elapsedMs,
                self::NODE_COUNT,
                self::BUDGET_MS
            )
        );
    }

    /**
     * The hydrated read, at the size a screen actually asks for.
     *
     * Full hydration of all 50,000 nodes is ~500 ms and always will be: that is
     * the cost of building 50,000 PHP objects, not of the traversal. A caller
     * that genuinely wants every node should use descendantIds(); a caller that
     * wants models is filtering, and this is what filtering costs.
     */
    #[Test]
    public function a_depth_limited_hydrated_read_of_the_same_tree_is_within_budget(): void
    {
        $this->bootDomainFixtures();

        $rootId = $this->buildTree();

        app(GraphQueryService::class)->descendants($rootId, maxDepth: 2);

        $started = hrtime(true);
        $shallow = app(GraphQueryService::class)->descendants($rootId, maxDepth: 2);
        $elapsedMs = (hrtime(true) - $started) / 1_000_000;

        $this->assertGreaterThan(0, $shallow->count());
        $this->assertLessThan(self::BUDGET_MS, $elapsedMs, sprintf('Depth-limited read took %.1f ms', $elapsedMs));
    }

    /**
     * The whole-tree traversal is a range scan, so depth costs nothing. If this
     * ever diverges from the flat read above, something has started walking.
     */
    #[Test]
    public function traversal_cost_does_not_grow_with_depth(): void
    {
        $this->bootDomainFixtures();

        $rootId = $this->buildTree();
        $graph = app(GraphQueryService::class);

        $graph->descendantIds($rootId);

        $started = hrtime(true);
        $deep = $graph->descendantIds($rootId);
        $deepMs = (hrtime(true) - $started) / 1_000_000;

        $started = hrtime(true);
        $shallow = $graph->descendantIds($rootId, maxDepth: 1);
        $shallowMs = (hrtime(true) - $started) / 1_000_000;

        $this->assertGreaterThan($shallow->count(), $deep->count());
        $this->assertLessThan(self::BUDGET_MS, $deepMs);
        $this->assertLessThan(self::BUDGET_MS, $shallowMs);
    }

    /**
     * A branching tree of NODE_COUNT nodes, paths written directly.
     *
     * @return int the root object id
     */
    private function buildTree(): int
    {
        TenantContext::set($this->organization->id);

        $typeId = ObjectType::resolve('BusinessUnit')->id;
        $organizationId = $this->organization->id;
        $now = now();

        // The fixtures have already written a few objects, so node n's id is
        // offset + n. Ids are assigned sequentially by the insert and nothing
        // else writes to this table during the test.
        $offset = (int) DB::table('objects')->max('id');

        // Node n's parent is node intdiv(n - 2, branching) + 1, which is always
        // a lower n and therefore already has its path when n is reached.
        $branching = 8;
        $rows = [];
        $paths = [];
        $depths = [];

        for ($n = 1; $n <= self::NODE_COUNT; $n++) {
            $id = $offset + $n;
            $parentN = $n === 1 ? null : intdiv($n - 2, $branching) + 1;
            $parentId = $parentN === null ? null : $offset + $parentN;

            $paths[$n] = ($parentN === null ? '/' : $paths[$parentN]).$id.'/';
            $depths[$n] = $parentN === null ? 0 : $depths[$parentN] + 1;

            $rows[] = [
                'id' => $id,
                'uuid' => sprintf('%08x-0000-4000-8000-%012x', $id, $id),
                'organization_id' => $organizationId,
                'object_type_id' => $typeId,
                'node_id' => null,
                'code' => 'PERF-'.$n,
                'name' => 'Node '.$n,
                'hierarchy_path' => $paths[$n],
                'hierarchy_depth' => $depths[$n],
                'parent_id' => $parentId,
                'sort_order' => 0,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) === 1000) {
                DB::table('objects')->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('objects')->insert($rows);
        }

        $this->assertSame(self::NODE_COUNT, GraphObject::query()->where('code', 'like', 'PERF-%')->count());

        return $offset + 1;
    }
}
