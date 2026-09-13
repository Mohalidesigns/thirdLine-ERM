<?php

namespace App\Services\Widgets;

use App\Models\Dashboard;
use App\Models\GraphObject;
use App\Models\ObjectType;
use Illuminate\Support\Collection;

/**
 * WP-13 — "where does this dashboard actually appear?"
 *
 * This class exists because of one screenshot. The Dashboards list showed
 * "Assessment Context · Obligation · 8 tabs · Published" in confident green,
 * and Business HQ on the enterprise node showed "No dashboard published for
 * Enterprise". Both were correct and neither was any use: Obligation is not a
 * node type and the organisation contains zero Obligation objects, so the only
 * published dashboard in the tenant was bound to a type that appears nowhere
 * in the tree. Nothing on either screen said so.
 *
 * The builder's object-type selector is the place that mistake gets made, so
 * that is where the answer belongs, and it is the same answer the list page
 * and the Business HQ empty state need. Three surfaces, one calculation:
 *
 *   nodeCounts()      how many nodes of each type exist, right now
 *   objectTypeOptions() every type a dashboard can bind to, each carrying its
 *                     node count, whether Business HQ can render on it at all,
 *                     a node to preview against, and what is already live there
 *   warningFor()      the one sentence to put under a dashboard's name
 *
 * `is_node_type` is the load-bearing distinction. Business HQ renders on nodes
 * — /hq/{object} walks the org tree — so binding a dashboard to Risk or
 * Obligation cannot work however many of those exist. A count of zero is a
 * "not yet"; a non-node type is a "never", and they deserve different words.
 */
class DashboardBinding
{
    /**
     * Live object counts per object_type_id.
     *
     * One grouped query rather than a count per type: the selector renders
     * every type in the tenant and this is on the page-load path for three
     * screens.
     *
     * @return Collection<int, int>
     */
    public function nodeCounts(): Collection
    {
        return GraphObject::query()
            ->toBase()
            ->selectRaw('object_type_id, count(*) as aggregate')
            ->whereNull('deleted_at')
            ->groupBy('object_type_id')
            ->pluck('aggregate', 'object_type_id')
            ->map(fn ($count) => (int) $count);
    }

    /**
     * One representative node per type, for "Preview in Business HQ".
     *
     * A preview link is only honest on a node the dashboard would really
     * render on, so the id has to come from the same set the count does.
     *
     * @return Collection<int, int>
     */
    public function firstNodeIds(): Collection
    {
        return GraphObject::query()
            ->toBase()
            ->selectRaw('object_type_id, min(id) as first_id')
            ->whereNull('deleted_at')
            ->groupBy('object_type_id')
            ->pluck('first_id', 'object_type_id')
            ->map(fn ($id) => (int) $id);
    }

    /**
     * Every binding a dashboard can be given, annotated with what it would mean.
     *
     * Ordered node types first, because those are the ones that work; a type
     * with nodes in it before an empty one, because that is the order an
     * administrator is looking for. The type-agnostic default (id NULL) leads
     * the list — it is the binding that always renders somewhere.
     *
     * @return list<array{id:?int,name:string,is_node_type:bool,node_count:int,first_node_id:?int,renderable:bool,published:?Dashboard}>
     */
    public function objectTypeOptions(?int $excludeDashboardId = null): array
    {
        $counts = $this->nodeCounts();
        $firsts = $this->firstNodeIds();
        $published = $this->publishedByType($excludeDashboardId);

        $types = ObjectType::query()
            ->orderByDesc('is_node_type')
            ->orderBy('name')
            ->get(['id', 'name', 'is_node_type'])
            ->map(fn (ObjectType $type) => [
                'id' => (int) $type->id,
                'name' => $type->name,
                'is_node_type' => (bool) $type->is_node_type,
                'node_count' => $counts[$type->id] ?? 0,
                'first_node_id' => $firsts[$type->id] ?? null,
                // Renderable = Business HQ can put this on a real page today.
                'renderable' => (bool) $type->is_node_type && ($counts[$type->id] ?? 0) > 0,
                'published' => $published[$type->id] ?? null,
            ])
            ->sortBy([
                fn ($a, $b) => ($b['is_node_type'] <=> $a['is_node_type']),
                fn ($a, $b) => (($b['node_count'] > 0) <=> ($a['node_count'] > 0)),
                fn ($a, $b) => strcasecmp($a['name'], $b['name']),
            ])
            ->values()
            ->all();

        // The type-agnostic default renders on any node that has no dashboard
        // of its own, so its reach is every node in the graph.
        array_unshift($types, [
            'id' => null,
            'name' => 'Any object type (default dashboard)',
            'is_node_type' => true,
            'node_count' => $this->totalNodeCount(),
            'first_node_id' => $this->firstNodeId(),
            'renderable' => $this->totalNodeCount() > 0,
            'published' => $published[0] ?? null,
        ]);

        return $types;
    }

    /**
     * The one sentence that goes under a dashboard's name.
     *
     * Returns null when there is nothing to warn about — the caller should
     * print nothing at all rather than a reassuring "looks fine", which is
     * noise on a list of twenty.
     *
     * @return array{tone:'warn'|'info', text:string}|null
     */
    public function warningFor(Dashboard $dashboard, array $option): ?array
    {
        if (! $option['is_node_type']) {
            return [
                'tone' => 'warn',
                'text' => $option['name'].' is not a node type, so Business HQ has no page to render this on. Bind it to a type in the organisation tree.',
            ];
        }

        if ($option['node_count'] === 0) {
            return [
                'tone' => 'warn',
                'text' => 'No '.$option['name'].' nodes exist yet, so this will not appear anywhere until one is created.',
            ];
        }

        if (! $dashboard->is_published) {
            return $dashboard->draftWidgetCount() === 0
                ? ['tone' => 'info', 'text' => 'Draft with no widgets yet.']
                : ['tone' => 'info', 'text' => 'Draft — not on Business HQ until it is published.'];
        }

        if ($dashboard->hasUnpublishedChanges()) {
            return ['tone' => 'info', 'text' => 'Edited since it was published. Business HQ still shows v'.$dashboard->version.'.'];
        }

        return null;
    }

    /**
     * Published dashboards keyed by object_type_id, with the type-agnostic
     * default under key 0 (array keys cannot be NULL).
     *
     * @return Collection<int, Dashboard>
     */
    public function publishedByType(?int $excludeDashboardId = null): Collection
    {
        return Dashboard::query()
            ->published()
            ->when($excludeDashboardId !== null, fn ($q) => $q->whereKeyNot($excludeDashboardId))
            ->orderBy('name')
            ->get()
            ->keyBy(fn (Dashboard $d) => (int) ($d->object_type_id ?? 0));
    }

    private function totalNodeCount(): int
    {
        return GraphObject::query()->nodes()->toBase()->whereNull('deleted_at')->count();
    }

    private function firstNodeId(): ?int
    {
        $id = GraphObject::query()->nodes()->toBase()->whereNull('deleted_at')->min('id');

        return $id === null ? null : (int) $id;
    }
}
