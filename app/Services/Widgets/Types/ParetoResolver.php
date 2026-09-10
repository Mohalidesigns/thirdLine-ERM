<?php

namespace App\Services\Widgets\Types;

use App\Models\WidgetDefinition;
use App\Services\Widgets\ResolvedPeriods;
use App\Services\Widgets\WidgetContext;
use App\Services\Widgets\WidgetQueryEngine;
use App\Services\Widgets\WidgetScope;
use App\Services\Widgets\WidgetTypeResolver;

/**
 * pareto — bars descending with a cumulative-percentage line, the "which few
 * categories carry most of the loss" chart.
 *
 * The bars are the engine's grouped aggregate (typically SUM of gross loss by
 * basel_l1_category) over the scope; the cumulative percentage is computed in
 * PHP from those same bars, so the line can never disagree with the bars it
 * annotates. No data in scope renders zero bars — the 80/20 story is only
 * told when there are losses to tell it about.
 */
class ParetoResolver implements WidgetTypeResolver
{
    public function __construct(private readonly WidgetQueryEngine $engine) {}

    public function resolve(
        WidgetDefinition $definition,
        WidgetContext $context,
        WidgetScope $scope,
        ResolvedPeriods $periods,
    ): array {
        if ($scope->isEmpty()) {
            return ['bars' => [], 'drilldown' => false];
        }

        // A Pareto is descending by definition, whatever sort the query block
        // declares — the cumulative line is meaningless in any other order.
        $rows = $this->engine->aggregate($definition, $context, $scope, $periods)
            ->sortByDesc(fn (object $row) => $row->value)
            ->values();

        $total = $rows->sum(fn (object $row) => (float) $row->value);

        $bars = [];
        $running = 0.0;

        foreach ($rows as $row) {
            $running += (float) $row->value;

            $bars[] = [
                'label' => $row->key === null ? 'Unspecified' : (string) $row->key,
                'value' => $row->value,
                'cumulative_pct' => $total > 0 ? round($running / $total * 100, 1) : 0.0,
            ];
        }

        return [
            'bars' => $bars,
            'drilldown' => is_array($definition->drilldown) && $definition->drilldown !== [],
        ];
    }
}
