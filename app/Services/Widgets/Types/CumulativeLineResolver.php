<?php

namespace App\Services\Widgets\Types;

use App\Models\WidgetDefinition;
use App\Services\PeriodService;
use App\Services\Widgets\ResolvedPeriods;
use App\Services\Widgets\WidgetContext;
use App\Services\Widgets\WidgetQueryEngine;
use App\Services\Widgets\WidgetScope;
use App\Services\Widgets\WidgetTypeResolver;

/**
 * cumulative_line — % of control measures (treatment plans) implemented,
 * month by month: the "are we actually landing the mitigations" line.
 *
 * Denominator: every plan in scope. Numerator at each period: plans whose
 * completion_date falls on or before that period's end. Plans without a
 * completion date simply never enter the numerator — a plan marked
 * completed without a date is an incomplete record, and inventing a month
 * for it would draw progress that cannot be evidenced.
 */
class CumulativeLineResolver implements WidgetTypeResolver
{
    public function __construct(
        private readonly WidgetQueryEngine $engine,
        private readonly PeriodService $periods,
    ) {}

    public function resolve(
        WidgetDefinition $definition,
        WidgetContext $context,
        WidgetScope $scope,
        ResolvedPeriods $periods,
    ): array {
        if ($scope->isEmpty()) {
            return ['periods' => [], 'values' => [], 'counts' => []];
        }

        $window = $periods->window;

        if (count($window) < 2) {
            $end = $periods->primary ?? $this->periods->resolve(\Carbon\CarbonImmutable::today(), 'month', $context->organizationId());
            $window = $this->periods->trailing($end, 12, 'month')->all();
        }

        if ($window === []) {
            return ['periods' => [], 'values' => [], 'counts' => []];
        }

        $plans = $this->engine->baseQuery($definition, $context, $scope, $periods)
            ->toBase()
            ->get(['completion_date']);

        $total = $plans->count();

        $labels = [];
        $values = [];
        $counts = [];

        foreach ($window as $period) {
            $periodEnd = $period->end_date instanceof \DateTimeInterface
                ? $period->end_date->format('Y-m-d')
                : substr((string) $period->end_date, 0, 10);

            $done = $plans
                ->filter(fn (object $plan) => $plan->completion_date !== null
                    && substr((string) $plan->completion_date, 0, 10) <= $periodEnd)
                ->count();

            $labels[] = $period->code;
            $values[] = $total > 0 ? round($done / $total * 100, 1) : null;
            $counts[] = ['done' => $done, 'total' => $total];
        }

        return ['periods' => $labels, 'values' => $values, 'counts' => $counts];
    }
}
