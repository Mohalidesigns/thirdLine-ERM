<?php

namespace App\Services\Widgets\Types;

use App\Models\WidgetDefinition;
use App\Services\Widgets\ResolvedPeriods;
use App\Services\Widgets\WidgetContext;
use App\Services\Widgets\WidgetQueryEngine;
use App\Services\Widgets\WidgetScope;
use App\Services\Widgets\WidgetTypeResolver;

/**
 * donut — composition of a whole: by priority, by category, by status.
 * A grouped COUNT (or whitelisted SUM) over the scope, one slice per key.
 */
class DonutResolver implements WidgetTypeResolver
{
    public function __construct(private readonly WidgetQueryEngine $engine) {}

    public function resolve(
        WidgetDefinition $definition,
        WidgetContext $context,
        WidgetScope $scope,
        ResolvedPeriods $periods,
    ): array {
        if ($scope->isEmpty()) {
            return ['slices' => [], 'total' => 0];
        }

        $slices = $this->engine->aggregate($definition, $context, $scope, $periods)
            ->map(fn (object $row) => [
                'label' => $row->key === null ? 'Unspecified' : (string) $row->key,
                'value' => $row->value,
            ])
            ->values()
            ->all();

        return [
            'slices' => $slices,
            'total' => array_sum(array_column($slices, 'value')),
            'group_by' => $definition->queryConfig('group_by'),
        ];
    }
}
