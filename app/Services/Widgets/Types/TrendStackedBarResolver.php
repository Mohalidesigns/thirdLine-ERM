<?php

namespace App\Services\Widgets\Types;

use App\Models\WidgetDefinition;
use App\Repositories\RiskRepository;
use App\Services\PeriodService;
use App\Services\Widgets\ResolvedPeriods;
use App\Services\Widgets\WidgetContext;
use App\Services\Widgets\WidgetQueryEngine;
use App\Services\Widgets\WidgetScope;
use App\Services\Widgets\WidgetTypeResolver;
use App\Support\Measures\MeasureCatalog;
use Carbon\CarbonImmutable;

/**
 * trend_stacked_bar — per quarter, how many risks are Increasing, Constant,
 * Decreasing: direction is a comparison of the risk's recorded residual
 * score against ITS OWN reading the quarter before.
 *
 * A risk without a recorded score in both quarters of a pair is EXCLUDED
 * from that pair's bar — not counted as Constant. "Unmeasured" and "stable"
 * are different facts, and a bar that pads itself with unmeasured risks
 * manufactures stability the data does not contain.
 */
class TrendStackedBarResolver implements WidgetTypeResolver
{
    private const QUARTERS = 4;

    public function __construct(
        private readonly WidgetQueryEngine $engine,
        private readonly RiskRepository $risks,
        private readonly PeriodService $periods,
    ) {}

    public function resolve(
        WidgetDefinition $definition,
        WidgetContext $context,
        WidgetScope $scope,
        ResolvedPeriods $periods,
    ): array {
        if ($scope->isEmpty()) {
            return ['quarters' => [], 'series' => ['increasing' => [], 'constant' => [], 'decreasing' => []]];
        }

        // QUARTERS pairs need QUARTERS + 1 quarters of history.
        $anchor = $periods->primary !== null && $periods->primary->type === 'quarter'
            ? $periods->primary
            : $this->periods->resolve(
                $periods->primary?->end_date ?? CarbonImmutable::today(),
                'quarter',
                $context->organizationId(),
            );

        $window = $this->periods->trailing($anchor, self::QUARTERS + 1, 'quarter')->values()->all();

        if (count($window) < 2) {
            return ['quarters' => [], 'series' => ['increasing' => [], 'constant' => [], 'decreasing' => []]];
        }

        $riskIds = $this->engine->baseQuery($definition, $context, $scope, $periods)
            ->toBase()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $history = $this->risks->trend(
            MeasureCatalog::RISK_RESIDUAL_SCORE,
            $riskIds,
            array_map(fn ($period) => (int) $period->id, $window),
            $context->organizationId(),
        );

        $labels = [];
        $series = ['increasing' => [], 'constant' => [], 'decreasing' => []];

        for ($i = 1; $i < count($window); $i++) {
            $previousId = (int) $window[$i - 1]->id;
            $currentId = (int) $window[$i]->id;

            $up = $flat = $down = 0;

            foreach ($history as $byPeriod) {
                if (! isset($byPeriod[$previousId], $byPeriod[$currentId])) {
                    continue;
                }

                $delta = $byPeriod[$currentId] <=> $byPeriod[$previousId];

                match ($delta) {
                    1 => $up++,
                    0 => $flat++,
                    -1 => $down++,
                };
            }

            $labels[] = $window[$i]->code;
            $series['increasing'][] = $up;
            $series['constant'][] = $flat;
            $series['decreasing'][] = $down;
        }

        return ['quarters' => $labels, 'series' => $series];
    }
}
