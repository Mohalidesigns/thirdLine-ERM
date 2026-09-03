<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\Dashboard;
use App\Models\GraphObject;
use App\Models\ObjectType;
use App\Services\Graph\GraphQueryService;
use App\Services\Widgets\DashboardBinding;
use App\Services\Widgets\DashboardResolver;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

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
        private readonly DashboardBinding $binding,
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

    /**
     * WP-13 — two things were added here, both of them answers to the same
     * complaint: this page said "No dashboard published for Enterprise" and
     * stopped, leaving the administrator to guess which of five possible
     * causes it was.
     *
     * PREVIEW (?preview={dashboard}). The builder can now send an admin here
     * to see a DRAFT rendered on a real node with real data, before anyone
     * else sees it. It is refused unless the viewer can manage dashboards and
     * the draft is bound to this node's type — a preview that silently fell
     * back to the published dashboard would be worse than no preview, because
     * the admin would sign off on the wrong composition.
     *
     * DIAGNOSIS. When nothing resolves, the empty state now needs to say what
     * this node's type is, what IS bound to that type, and what the admin can
     * do about it — so the drafts and the binding options are loaded whether
     * or not a dashboard was found.
     */
    public function show(Request $request, GraphObject $object)
    {
        $user = $request->user();
        $canManage = (bool) $user?->can('dashboard.manage');

        $preview = $this->previewDashboard($request, $object, $canManage);

        $dashboard = $preview ?? $this->dashboards->resolveFor($object, $user);

        $tabs = $dashboard === null
            ? []
            : $this->dashboards->layoutFor($dashboard, $user, draft: $preview !== null);

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

            // Preview chrome. Null on a normal page load.
            'preview' => $preview,
            // Asked for a preview and did not get one — the banner explains why
            // rather than pretending the request was never made.
            'previewRefused' => $preview === null && $request->filled('preview')
                ? $this->previewRefusal($request, $object, $canManage)
                : null,

            // Empty-state material, only worth loading for someone who can act.
            'canManage' => $canManage,
            'draftsForType' => $canManage ? $this->draftsForType($object) : collect(),
            'nodeCountForType' => $canManage
                ? ($this->binding->nodeCounts()[$object->object_type_id] ?? 0)
                : 0,
            // Published, bound to this type, and still not on screen because
            // of who is looking. See roleBlockedDashboards().
            'roleBlocked' => $dashboard === null ? $this->roleBlockedDashboards($object, $user) : collect(),
        ]);
    }

    /**
     * The draft this request is asking to preview, or null.
     *
     * Every condition below is a refusal to show something misleading rather
     * than a security control (the route already demands hq.view, and the
     * tenant scope stops a foreign id resolving at all).
     */
    private function previewDashboard(Request $request, GraphObject $object, bool $canManage): ?Dashboard
    {
        if (! $request->filled('preview') || ! $canManage) {
            return null;
        }

        $dashboard = Dashboard::query()->find($request->integer('preview'));

        if ($dashboard === null) {
            return null;
        }

        // A dashboard bound to a type renders only on that type. The
        // type-agnostic default (NULL) renders anywhere, so it previews
        // anywhere.
        if ($dashboard->object_type_id !== null
            && (int) $dashboard->object_type_id !== (int) $object->object_type_id) {
            return null;
        }

        return $dashboard;
    }

    /** Why the preview did not happen, in words the admin can act on. */
    private function previewRefusal(Request $request, GraphObject $object, bool $canManage): string
    {
        if (! $canManage) {
            return 'You do not have permission to preview unpublished dashboards.';
        }

        $dashboard = Dashboard::query()->find($request->integer('preview'));

        if ($dashboard === null) {
            return 'That dashboard no longer exists.';
        }

        return sprintf(
            '“%s” is bound to %s, and %s is %s. Pick a %s node in the tree to preview it.',
            $dashboard->name,
            $dashboard->objectType?->name ?? 'another type',
            $object->name,
            $object->objectType?->name ?? 'a different type',
            $dashboard->objectType?->name ?? 'matching',
        );
    }

    /**
     * Published dashboards for this node's type that this viewer's roles
     * exclude.
     *
     * This is the empty state's third cause and by far the worst one to hit,
     * because every other screen says everything is fine. The Dashboards list
     * shows a green "Published · Enterprise — 1 node"; the builder shows
     * "Renders on 1 node"; and Business HQ on that very node says nothing is
     * published. All three are telling the truth. The dashboard is published
     * to three roles and the person looking holds none of them, and until now
     * nothing anywhere said so.
     *
     * DashboardResolver::pick() is the authority on the rule, so this mirrors
     * it rather than reimplementing it: role_ids NULL or [] means everyone, so
     * a dashboard reaching this method has a non-empty role list that does not
     * intersect the viewer's.
     */
    private function roleBlockedDashboards(GraphObject $object, $user)
    {
        $roleIds = $user->roles->pluck('id')->map(fn ($id) => (int) $id)->all();
        $roleNames = Role::query()->pluck('name', 'id');

        return Dashboard::query()
            ->published()
            ->with('objectType')
            ->where(fn ($q) => $q
                ->where('object_type_id', $object->object_type_id)
                ->orWhereNull('object_type_id'))
            ->whereNotNull('role_ids')
            ->where('role_ids', '!=', '[]')
            ->get(['id', 'name', 'object_type_id', 'role_ids'])
            ->reject(fn (Dashboard $d) => array_intersect($roleIds, array_map('intval', $d->role_ids ?? [])) !== [])
            ->each(function (Dashboard $d) use ($roleNames) {
                // Resolved here rather than in the view: naming the roles is
                // the actionable half of the message ("add chief-risk-officer
                // to it, or clear the list"), and a Blade template should not
                // be running queries to say it.
                $d->role_names = collect($d->role_ids ?? [])
                    ->map(fn ($id) => $roleNames[(int) $id] ?? '#'.$id)
                    ->implode(', ');
            })
            ->values();
    }

    /**
     * Unpublished dashboards already bound to this node's type.
     *
     * The empty state offers these before it offers "create a new one",
     * because the overwhelmingly common cause of an empty HQ page is a
     * dashboard that was built and never published — not one that was never
     * built. Offering "Create" first is how a tenant ends up with four drafts
     * called "Untitled dashboard".
     */
    private function draftsForType(GraphObject $object)
    {
        return Dashboard::query()
            ->where('is_published', false)
            ->where(fn ($q) => $q
                ->where('object_type_id', $object->object_type_id)
                ->orWhereNull('object_type_id'))
            ->orderBy('name')
            ->limit(5)
            ->get(['id', 'name', 'object_type_id']);
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
