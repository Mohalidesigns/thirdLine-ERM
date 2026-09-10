<?php

namespace App\Services\Widgets\Types;

use App\Models\WidgetDefinition;
use App\Services\Widgets\Concerns\ReadsMeasures;
use App\Services\Widgets\ResolvedPeriods;
use App\Services\Widgets\WidgetContext;
use App\Services\Widgets\WidgetPeriodResolver;
use App\Services\Widgets\WidgetQueryEngine;
use App\Services\Widgets\WidgetScope;
use App\Services\Widgets\WidgetTypeResolver;

/**
 * kpi_tile — big number + label, optional sparkline and delta.
 *
 * Two data paths, chosen by the definition:
 *
 *   measure_id set   the number is a measure value aggregated over the scope
 *                    for the primary period; the sparkline is the same
 *                    aggregation over a trailing window; the delta compares
 *                    against the previous period. Period-honest by
 *                    construction — no value recorded, no number shown.
 *
 *   query.source     the number is a COUNT (or whitelisted SUM/AVG) of rows
 *                    in scope, current-state. No delta is invented for
 *                    current-state counts: "open issues vs last month" needs
 *                    a period-aware source, not arithmetic on today's rows.
 */
class KpiTileResolver implements WidgetTypeResolver
{
    use ReadsMeasures;

    public function __construct(
        private readonly WidgetQueryEngine $engine,
        private readonly WidgetPeriodResolver $periodResolver,
    ) {}

    public function resolve(
        WidgetDefinition $definition,
        WidgetContext $context,
        WidgetScope $scope,
        ResolvedPeriods $periods,
    ): array {
        if ($scope->isEmpty()) {
            return ['value' => null, 'unit' => null, 'delta' => null, 'sparkline' => []];
        }

        if ($definition->measure_id !== null) {
            return $this->fromMeasure($definition, $scope, $periods);
        }

        $value = $this->engine->scalar($definition, $context, $scope, $periods);

        return [
            'value' => $value,
            'formatted' => $value === null ? null : $this->formatNumber($value, $definition),
            'unit' => $definition->visualisationConfig('unit'),
            'delta' => null,
            'sparkline' => [],
        ];
    }

    private function fromMeasure(WidgetDefinition $definition, WidgetScope $scope, ResolvedPeriods $periods): array
    {
        $measure = $definition->measure;

        if ($measure === null || $periods->primary === null) {
            return ['value' => null, 'unit' => null, 'delta' => null, 'sparkline' => []];
        }

        $objectIds = $this->measureObjectIds($measure, $scope);

        if ($objectIds === []) {
            return ['value' => null, 'unit' => null, 'delta' => null, 'sparkline' => []];
        }

        // Sparkline window: the resolved window when the binding provides one,
        // otherwise a trailing 12 of the primary period's type.
        $window = count($periods->window) > 1
            ? $periods->window
            : app(\App\Services\PeriodService::class)->trailing($periods->primary, 12)->all();

        $windowIds = array_map(fn ($p) => (int) $p->id, $window);

        $matrix = $this->measures()->matrix($measure, $objectIds, $windowIds);

        $sparkline = [];
        foreach ($window as $period) {
            $sparkline[] = [
                'period' => $period->code,
                'value' => $this->collapse($measure, $matrix, (int) $period->id),
            ];
        }

        $value = $this->collapse($measure, $matrix, (int) $periods->primary->id);

        // Delta against the nearest preceding period IN THE WINDOW that has a
        // value — a gap month yields no delta, not a delta against zero.
        $delta = null;
        $before = array_filter(
            $sparkline,
            fn (array $point, int $i) => $point['value'] !== null
                && $window[$i]->end_date < $periods->primary->start_date,
            ARRAY_FILTER_USE_BOTH,
        );

        if ($value !== null && $before !== []) {
            $previous = end($before)['value'];
            $delta = [
                'absolute' => $value - $previous,
                'pct' => $previous != 0.0 ? round(($value - $previous) / abs($previous) * 100, 1) : null,
                // Whether an increase is good depends on the measure, not the
                // arithmetic sign.
                'improving' => $measure->polarity === 'higher_better' ? $value >= $previous : $value <= $previous,
            ];
        }

        return [
            'value' => $value,
            'formatted' => $value === null ? null : $measure->format($value),
            'unit' => $measure->unit?->symbol,
            'delta' => $delta,
            'sparkline' => $sparkline,
        ];
    }

    private function formatNumber(int|float $value, WidgetDefinition $definition): string
    {
        $decimals = (int) $definition->visualisationConfig('decimals', 0);

        return number_format((float) $value, $decimals);
    }
}
