<?php

namespace App\Services\Widgets\Types;

use App\Models\WidgetDefinition;
use App\Services\Widgets\ResolvedPeriods;
use App\Services\Widgets\WidgetContext;
use App\Services\Widgets\WidgetQueryEngine;
use App\Services\Widgets\WidgetScope;
use App\Services\Widgets\WidgetSourceRegistry;
use App\Services\Widgets\WidgetTypeResolver;

/**
 * timeline — activities as horizontal spans over time. Works over any source
 * with a start/end pair; the defaults cover the two that make sense today
 * (treatment plans: created→target, control tests: scheduled→completed).
 * Items missing a start are skipped; an open end renders as running to
 * today, flagged open-ended so the renderer can draw it as such.
 */
class TimelineResolver implements WidgetTypeResolver
{
    private const SPANS = [
        'treatment_plans' => ['start' => 'created_at', 'end' => 'target_date', 'label' => 'action_title', 'status' => 'status'],
        'control_tests' => ['start' => 'scheduled_date', 'end' => 'completed_date', 'label' => 'title', 'status' => 'status'],
    ];

    public function __construct(
        private readonly WidgetQueryEngine $engine,
        private readonly WidgetSourceRegistry $registry,
    ) {}

    public function resolve(
        WidgetDefinition $definition,
        WidgetContext $context,
        WidgetScope $scope,
        ResolvedPeriods $periods,
    ): array {
        $sourceKey = (string) $definition->queryConfig('source', 'treatment_plans');
        $span = self::SPANS[$sourceKey] ?? null;

        if ($span === null || $scope->isEmpty()) {
            return ['items' => []];
        }

        $rows = $this->engine->baseQuery($definition, $context, $scope, $periods)
            ->orderBy($span['start'])
            ->limit((int) $definition->queryConfig('limit', 30))
            ->get();

        $items = [];

        foreach ($rows as $row) {
            $start = $row->{$span['start']};

            if ($start === null) {
                continue;
            }

            $end = $row->{$span['end']};

            $items[] = [
                'id' => (int) $row->getKey(),
                'label' => (string) $row->{$span['label']},
                'start' => substr((string) $start, 0, 10),
                'end' => $end === null ? now()->format('Y-m-d') : substr((string) $end, 0, 10),
                'open_ended' => $end === null,
                'status' => (string) $row->{$span['status']},
            ];
        }

        return ['items' => $items];
    }
}
