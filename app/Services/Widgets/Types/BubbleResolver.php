<?php

namespace App\Services\Widgets\Types;

use App\Models\WidgetDefinition;
use App\Services\Widgets\ResolvedPeriods;
use App\Services\Widgets\WidgetContext;
use App\Services\Widgets\WidgetQueryEngine;
use App\Services\Widgets\WidgetScope;
use App\Services\Widgets\WidgetTypeResolver;

/**
 * bubble — control effectiveness (x) against exposure (y = L×C), bubble
 * size = financial magnitude, label = risk name. The chart that shows where
 * money is at risk behind weak controls: bottom-left big bubbles are the
 * conversation.
 *
 * A risk missing either axis is SKIPPED, not zeroed — an unscored risk
 * plotted at (0,0) would render as the best-controlled item on the chart,
 * which is the most dangerous possible misreading. Missing magnitude only
 * shrinks the bubble to the minimum size.
 */
class BubbleResolver implements WidgetTypeResolver
{
    private const LIMIT = 50;

    public function __construct(private readonly WidgetQueryEngine $engine) {}

    public function resolve(
        WidgetDefinition $definition,
        WidgetContext $context,
        WidgetScope $scope,
        ResolvedPeriods $periods,
    ): array {
        if ($scope->isEmpty()) {
            return ['points' => [], 'skipped' => 0];
        }

        $rows = $this->engine->baseQuery($definition, $context, $scope, $periods)
            ->orderByDesc('residual_score')
            ->limit(self::LIMIT * 2)
            ->get(['id', 'title', 'control_effectiveness_pct', 'residual_score', 'financial_exposure_ngn']);

        $points = [];
        $skipped = 0;

        foreach ($rows as $risk) {
            if ($risk->control_effectiveness_pct === null || $risk->residual_score === null) {
                $skipped++;

                continue;
            }

            if (count($points) >= self::LIMIT) {
                break;
            }

            $points[] = [
                'id' => (int) $risk->id,
                'label' => $risk->title,
                'x' => (float) $risk->control_effectiveness_pct,
                'y' => (float) $risk->residual_score,
                'size' => $risk->financial_exposure_ngn === null ? null : (float) $risk->financial_exposure_ngn,
            ];
        }

        return ['points' => $points, 'skipped' => $skipped];
    }
}
