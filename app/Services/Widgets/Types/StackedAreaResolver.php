<?php

namespace App\Services\Widgets\Types;

use App\Models\WidgetDefinition;
use App\Repositories\RiskRepository;
use App\Services\PeriodService;
use App\Services\RiskScoringService;
use App\Services\Widgets\ResolvedPeriods;
use App\Services\Widgets\WidgetContext;
use App\Services\Widgets\WidgetQueryEngine;
use App\Services\Widgets\WidgetScope;
use App\Services\Widgets\WidgetTypeResolver;
use Carbon\CarbonImmutable;

/**
 * stacked_area — risk development over time by priority band, dozen periods.
 *
 * Counts come from the period-aware measure history (risk.residual_score in
 * measure_values), banded through the scoring profile, with CARRY-FORWARD:
 * a risk scored in March and untouched in April is still a High risk in
 * April — the same reading RiskRepository::asOf() gives the register. A risk
 * contributes nothing before its first recorded score; history is not
 * backfilled from today's values.
 */
class StackedAreaResolver implements WidgetTypeResolver
{
    private const MAX_PERIODS = 12;

    public function __construct(
        private readonly WidgetQueryEngine $engine,
        private readonly RiskRepository $risks,
        private readonly RiskScoringService $scoring,
        private readonly PeriodService $periods,
    ) {}

    public function resolve(
        WidgetDefinition $definition,
        WidgetContext $context,
        WidgetScope $scope,
        ResolvedPeriods $periods,
    ): array {
        if ($scope->isEmpty()) {
            return ['periods' => [], 'bands' => [], 'series' => []];
        }

        $window = $periods->window;

        if (count($window) < 2) {
            $end = $periods->primary ?? $this->periods->resolve(CarbonImmutable::today(), 'month', $context->organizationId());
            $window = $this->periods->trailing($end, self::MAX_PERIODS, 'month')->all();
        }

        $window = array_slice($window, -self::MAX_PERIODS);

        if ($window === []) {
            return ['periods' => [], 'bands' => [], 'series' => []];
        }

        $riskIds = $this->engine->baseQuery($definition, $context, $scope, $periods)
            ->toBase()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $profile = $this->scoring->profileFor(null, $context->organizationId(), $scope->anchor?->id, null, null);

        $bands = array_values(array_map(fn (array $band) => [
            'code' => (string) ($band['code'] ?? ''),
            'label' => (string) ($band['label'] ?? ''),
            'color' => (string) ($band['color'] ?? '#64748b'),
        ], $profile->rating_bands ?? []));

        $series = [];
        foreach ($bands as $band) {
            $series[$band['code']] = array_fill(0, count($window), 0);
        }

        $history = $this->risks->trend(
            \App\Support\Measures\MeasureCatalog::RISK_RESIDUAL_SCORE,
            $riskIds,
            array_map(fn ($period) => (int) $period->id, $window),
            $context->organizationId(),
        );

        foreach ($history as $byPeriod) {
            $carried = null;

            foreach ($window as $index => $period) {
                $carried = $byPeriod[(int) $period->id] ?? $carried;

                if ($carried === null) {
                    continue;
                }

                $band = $this->scoring->ratingBand($carried, $profile);
                $code = $band['code'] ?? null;

                if ($code !== null && isset($series[$code])) {
                    $series[$code][$index]++;
                }
            }
        }

        return [
            'periods' => array_map(fn ($p) => $p->code, $window),
            'bands' => $bands,
            'series' => $series,
        ];
    }
}
