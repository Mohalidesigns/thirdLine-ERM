<?php

namespace App\Services\Widgets\Types;

use App\Models\GraphObject;
use App\Models\ObjectType;
use App\Models\WidgetDefinition;
use App\Services\Widgets\ResolvedPeriods;
use App\Services\Widgets\WidgetContext;
use App\Services\Widgets\WidgetScope;
use App\Services\Widgets\WidgetTypeResolver;

/**
 * bar_by_type — what lives in this part of the organization, counted by
 * object type: N risks, N controls, N issues, N KRIs. The graph's own
 * census, straight off `objects`.
 *
 * Org-node types are excluded by default (a business unit "containing 4
 * departments" is navigation, not exposure); visualisation.include_nodes
 * turns them back on.
 */
class BarByTypeResolver implements WidgetTypeResolver
{
    public function resolve(
        WidgetDefinition $definition,
        WidgetContext $context,
        WidgetScope $scope,
        ResolvedPeriods $periods,
    ): array {
        if ($scope->isEmpty()) {
            return ['bars' => []];
        }

        $query = GraphObject::query();

        if (! $scope->isUnrestricted()) {
            $nodeIds = $scope->nodeIds ?? [];
            $query->where(function ($q) use ($nodeIds) {
                $q->whereIn('id', $nodeIds)->orWhereIn('node_id', $nodeIds);
            });
        }

        $counts = $query->toBase()
            ->selectRaw('object_type_id, count(*) as c')
            ->groupBy('object_type_id')
            ->pluck('c', 'object_type_id');

        $types = ObjectType::query()->whereIn('id', $counts->keys())->get()->keyBy('id');

        $includeNodes = (bool) $definition->visualisationConfig('include_nodes', false);

        $bars = $counts
            ->map(function ($count, $typeId) use ($types) {
                $type = $types->get($typeId);

                return $type === null ? null : [
                    'label' => $type->plural_name ?: $type->name,
                    'type_code' => $type->code,
                    'is_node' => (bool) $type->is_node_type,
                    'value' => (int) $count,
                ];
            })
            ->filter()
            ->when(! $includeNodes, fn ($bars) => $bars->reject(fn (array $bar) => $bar['is_node']))
            ->sortByDesc('value')
            ->values()
            ->map(fn (array $bar) => collect($bar)->except('is_node')->all())
            ->all();

        return ['bars' => $bars];
    }
}
