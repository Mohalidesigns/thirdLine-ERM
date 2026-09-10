<?php

namespace App\Services\Widgets\Types;

use App\Models\GraphObject;
use App\Models\WidgetDefinition;
use App\Services\RiskScoringService;
use App\Services\Widgets\ResolvedPeriods;
use App\Services\Widgets\WidgetContext;
use App\Services\Widgets\WidgetScope;
use App\Services\Widgets\WidgetTypeResolver;

/**
 * opportunity_heatmap — ISO 31000's other half: uncertainty with a positive
 * effect. Inverse polarity, blue palette, REVERSED impact axis (the best
 * outcomes sit where a risk map puts the worst).
 *
 * There is no typed opportunity model until WP-10. What exists TODAY is the
 * Opportunity object type in the graph registry (parent type Risk), so this
 * resolver reads graph objects of that type, with likelihood/benefit scored
 * in their attribute bags. Zero objects renders an honest empty matrix — the
 * panel exists so the conversation "why is our opportunity register empty?"
 * can happen, which is the ISO point.
 */
class OpportunityHeatmapResolver implements WidgetTypeResolver
{
    /** Blue ramp, light → saturated: benefit bands for a positive matrix. */
    private const BLUE_RAMP = ['#dbeafe', '#93c5fd', '#3b82f6', '#1d4ed8'];

    public function __construct(private readonly RiskScoringService $scoring) {}

    public function resolve(
        WidgetDefinition $definition,
        WidgetContext $context,
        WidgetScope $scope,
        ResolvedPeriods $periods,
    ): array {
        $profile = $this->scoring->profileFor(null, $context->organizationId(), null, null, null);

        $rows = (int) $profile->matrix_rows;
        $cols = (int) $profile->matrix_cols;

        $counts = [];

        if (! $scope->isEmpty()) {
            $query = GraphObject::query()->ofType('Opportunity');

            if (! $scope->isUnrestricted()) {
                $nodeIds = $scope->nodeIds ?? [];
                $query->where(function ($q) use ($nodeIds) {
                    $q->whereIn('id', $nodeIds)->orWhereIn('node_id', $nodeIds);
                });
            }

            foreach ($query->get() as $object) {
                $l = (int) $object->customAttribute('likelihood', 0);
                $i = (int) $object->customAttribute('benefit', $object->customAttribute('impact', 0));

                if ($l >= 1 && $l <= $rows && $i >= 1 && $i <= $cols) {
                    $counts[$l][$i] = ($counts[$l][$i] ?? 0) + 1;
                }
            }
        }

        $cells = [];
        for ($likelihood = $rows; $likelihood >= 1; $likelihood--) {
            // REVERSED axis: highest benefit renders leftmost.
            for ($impact = $cols; $impact >= 1; $impact--) {
                $score = $likelihood * $impact;
                $max = $rows * $cols;

                $cells[] = [
                    'likelihood' => $likelihood,
                    'impact' => $impact,
                    'count' => $counts[$likelihood][$impact] ?? 0,
                    'score' => $score,
                    'band' => null,
                    'color' => self::BLUE_RAMP[(int) floor(($score - 1) / $max * count(self::BLUE_RAMP))] ?? self::BLUE_RAMP[0],
                    'filters' => ['likelihood' => $likelihood, 'benefit' => $impact],
                ];
            }
        }

        return [
            'rows' => $rows,
            'cols' => $cols,
            'basis' => 'opportunity',
            'polarity' => 'opportunity',
            'as_of' => null,
            'axis' => [
                'likelihood' => $profile->axisLabels('likelihood'),
                // Reversed to match the cell order.
                'impact' => array_reverse($profile->axisLabels('impact'), true),
            ],
            'cells' => $cells,
            'total' => array_sum(array_map('array_sum', $counts)),
        ];
    }
}
