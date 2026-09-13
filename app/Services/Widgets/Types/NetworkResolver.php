<?php

namespace App\Services\Widgets\Types;

use App\Models\GraphObject;
use App\Models\ObjectRelationship;
use App\Models\ObjectType;
use App\Models\WidgetDefinition;
use App\Services\Widgets\ResolvedPeriods;
use App\Services\Widgets\WidgetContext;
use App\Services\Widgets\WidgetScope;
use App\Services\Widgets\WidgetTypeResolver;

/**
 * network — the anchor node's neighbourhood in the object graph: what
 * touches this thing, and what touches those. Two hops, capped, because a
 * dashboard panel is a peripheral-vision view — the full graph explorer is
 * a different surface.
 */
class NetworkResolver implements WidgetTypeResolver
{
    private const MAX_NODES = 60;

    public function resolve(
        WidgetDefinition $definition,
        WidgetContext $context,
        WidgetScope $scope,
        ResolvedPeriods $periods,
    ): array {
        $anchor = $scope->anchor ?? $context->node;

        if ($anchor === null) {
            return ['nodes' => [], 'edges' => [], 'empty' => true];
        }

        // Edges live between GOVERNANCE objects (a control mitigates a risk);
        // an org node rarely carries one directly. The neighbourhood of a
        // node is therefore seeded with the objects that hang off it, so a
        // division page draws its risks and what touches them.
        $hangingIds = GraphObject::query()
            ->where('node_id', $anchor->id)
            ->limit(20)
            ->toBase()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $nodeIds = [(int) $anchor->id => true];
        $edges = [];
        $frontier = [(int) $anchor->id, ...$hangingIds];

        foreach ($hangingIds as $id) {
            $nodeIds[$id] = true;
        }

        for ($hop = 0; $hop < 2 && count($nodeIds) < self::MAX_NODES; $hop++) {
            $found = ObjectRelationship::query()
                ->where(function ($q) use ($frontier) {
                    $q->whereIn('from_object_id', $frontier)->orWhereIn('to_object_id', $frontier);
                })
                ->with('relationshipType')
                ->limit(200)
                ->get();

            $next = [];

            foreach ($found as $edge) {
                $from = (int) $edge->from_object_id;
                $to = (int) $edge->to_object_id;

                if (count($nodeIds) >= self::MAX_NODES && (! isset($nodeIds[$from]) || ! isset($nodeIds[$to]))) {
                    continue;
                }

                foreach ([$from, $to] as $id) {
                    if (! isset($nodeIds[$id])) {
                        $nodeIds[$id] = true;
                        $next[] = $id;
                    }
                }

                $edges[$from.'-'.$to] = [
                    'from' => $from,
                    'to' => $to,
                    'code' => $edge->relationshipType?->code ?? 'related',
                ];
            }

            $frontier = $next;

            if ($frontier === []) {
                break;
            }
        }

        // Hydrate through the tenant scope; ids an attacker cannot see fall
        // out here, and their edges with them.
        $objects = GraphObject::query()->whereIn('id', array_keys($nodeIds))->get(['id', 'name', 'object_type_id']);
        $types = ObjectType::query()->whereIn('id', $objects->pluck('object_type_id')->unique())->get()->keyBy('id');

        $visible = $objects->pluck('id')->map(fn ($id) => (int) $id)->flip();

        $nodes = $objects
            ->map(fn (GraphObject $object) => [
                'id' => (int) $object->id,
                'label' => $object->name,
                'type' => $types[$object->object_type_id]->code ?? null,
                'is_anchor' => (int) $object->id === (int) $anchor->id,
            ])
            ->values()
            ->all();

        // The node_id column IS a containment edge; draw it as one so the
        // hanging objects connect to their node rather than floating.
        foreach ($hangingIds as $id) {
            $key = $anchor->id.'-'.$id;
            $reverse = $id.'-'.$anchor->id;

            if (! isset($edges[$key]) && ! isset($edges[$reverse])) {
                $edges[$key] = ['from' => (int) $anchor->id, 'to' => $id, 'code' => 'contains'];
            }
        }

        $edges = collect($edges)
            ->filter(fn (array $edge) => $visible->has($edge['from']) && $visible->has($edge['to']))
            ->values()
            ->all();

        return ['nodes' => $nodes, 'edges' => $edges, 'empty' => count($nodes) <= 1];
    }
}
