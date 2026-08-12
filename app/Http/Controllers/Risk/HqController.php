<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\GraphObject;
use App\Models\ObjectType;
use App\Services\Graph\GraphQueryService;
use App\Services\Widgets\DashboardResolver;
use Illuminate\Http\Request;

/**
 * WP-08 TASK 4 — Business HQ: one landing page per node of the organization.
 *
 * /hq/{object} renders the published dashboard for the node's object type,
 * every widget resolved in THAT node's context. Clicking a node in the tree
 * re-renders the same dashboard in the new context — the page IS the
 * demonstration of "one definition, N contexts".
 *
 * Guarding: hq.view on the route; the object arrives through the tenant
 * global scope (a foreign id 404s, it does not leak), and a subtree-pinned
 * user's tree and widgets are narrowed by GraphScope through the engine.
 */
class HqController extends Controller
{
    public function __construct(
        private readonly DashboardResolver $dashboards,
        private readonly GraphQueryService $graph,
    ) {}

    /**
     * Land on the organization's root node.
     *
     * The graph can hold more than one tree (the legacy business-unit list
     * and the entity hierarchy coexist until they are merged), so "the root"
     * is the root whose subtree actually CARRIES the organization — the one
     * governance objects hang off. Landing on an empty parallel root would
     * make the whole engine look broken on first open.
     */
    public function index(Request $request)
    {
        $roots = GraphObject::query()
            ->nodes()
            ->whereNull('parent_id')
            ->get(['id', 'hierarchy_path']);

        if ($roots->isEmpty()) {
            return view('hq.empty');
        }

        $busiest = $roots
            ->sortByDesc(function (GraphObject $root) {
                $subtree = GraphObject::query()
                    ->where('hierarchy_path', 'like', $root->pathOrFallback().'%')
                    ->toBase()
                    ->select('id');

                // Descendant nodes plus governance objects hanging off them.
                return GraphObject::query()->whereIn('node_id', $subtree)->toBase()->count()
                    + GraphObject::query()->where('hierarchy_path', 'like', $root->pathOrFallback().'%')->toBase()->count();
            })
            ->first();

        return redirect()->route('hq.show', $busiest);
    }

    public function show(Request $request, GraphObject $object)
    {
        $user = $request->user();

        $dashboard = $this->dashboards->resolveFor($object, $user);

        $tabs = $dashboard === null ? [] : $this->dashboards->layoutFor($dashboard, $user);

        $activeTab = collect($tabs)->firstWhere('code', $request->query('tab'))
            ?? ($tabs[0] ?? null);

        $ancestors = $this->graph->ancestors((int) $object->id, $user)->reverse()->values();

        return view('hq.show', [
            'object' => $object,
            'objectType' => $object->objectType,
            'dashboard' => $dashboard,
            'tabs' => $tabs,
            'activeTab' => $activeTab,
            'ancestors' => $ancestors,
            'tree' => $this->tree($user),
        ]);
    }

    /**
     * The org-node tree for the left navigator, nested in one pass.
     *
     * @return list<array{id:int,name:string,type:?string,icon:?string,depth:int,children:array}>
     */
    private function tree($user): array
    {
        $nodes = GraphObject::query()
            ->nodes()
            ->orderBy('hierarchy_depth')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id', 'object_type_id', 'hierarchy_depth']);

        $types = ObjectType::query()
            ->whereIn('id', $nodes->pluck('object_type_id')->unique())
            ->get()
            ->keyBy('id');

        $byParent = $nodes->groupBy('parent_id');

        $build = function ($parentId, int $depth) use (&$build, $byParent, $types): array {
            if ($depth > 8) {
                return [];
            }

            return ($byParent[$parentId] ?? collect())
                ->map(fn (GraphObject $node) => [
                    'id' => (int) $node->id,
                    'name' => $node->name,
                    'type' => $types[$node->object_type_id]->name ?? null,
                    'icon' => $types[$node->object_type_id]->icon ?? null,
                    'depth' => $depth,
                    'children' => $build($node->id, $depth + 1),
                ])
                ->values()
                ->all();
        };

        // Roots: no parent, or a parent outside the node set (an org node
        // hanging off a soft-deleted ancestor still needs to be reachable).
        $nodeIds = $nodes->pluck('id')->flip();
        $roots = $nodes->filter(fn (GraphObject $n) => $n->parent_id === null || ! $nodeIds->has($n->parent_id));

        return $roots
            ->map(fn (GraphObject $node) => [
                'id' => (int) $node->id,
                'name' => $node->name,
                'type' => $types[$node->object_type_id]->name ?? null,
                'icon' => $types[$node->object_type_id]->icon ?? null,
                'depth' => 0,
                'children' => $build($node->id, 1),
            ])
            ->values()
            ->all();
    }
}
