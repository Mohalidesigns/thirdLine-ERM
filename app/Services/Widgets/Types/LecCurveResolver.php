<?php

namespace App\Services\Widgets\Types;

use App\Models\SimulationResult;
use App\Models\SimulationRun;
use App\Models\WidgetDefinition;
use App\Services\Widgets\ResolvedPeriods;
use App\Services\Widgets\WidgetContext;
use App\Services\Widgets\WidgetScope;
use App\Services\Widgets\WidgetTypeResolver;

/**
 * lec_curve — the loss exceedance curve: P(annual loss ≥ x) against x.
 *
 * Derived from the latest completed run's AGGREGATE percentile distribution:
 * a stored p95 of ₦X is, by definition, a 5% exceedance probability at X.
 * That gives the curve at the percentiles the engine stored and nothing in
 * between — the renderer joins points, it does not smooth them. WP-18
 * replaces this with the full simulated distribution; until then, no run
 * means an empty panel that says so.
 */
class LecCurveResolver implements WidgetTypeResolver
{
    public function resolve(
        WidgetDefinition $definition,
        WidgetContext $context,
        WidgetScope $scope,
        ResolvedPeriods $periods,
    ): array {
        $run = SimulationRun::query()
            ->where('status', 'completed')
            ->orderByDesc('completed_at')
            ->first();

        if ($run === null) {
            return ['points' => [], 'empty' => true, 'reason' => 'No completed simulation run.'];
        }

        $aggregate = SimulationResult::query()
            ->where('simulation_run_id', $run->id)
            ->where('result_type', 'aggregate')
            ->first();

        $dist = $aggregate?->percentile_distribution ?? [];

        $points = collect($dist)
            ->map(function ($loss, string $percentile) {
                $p = (float) ltrim($percentile, 'p');

                if ($p <= 0 || $p >= 100 || ! is_numeric($loss)) {
                    return null;
                }

                return [
                    'loss' => (float) $loss, // kobo
                    'probability' => round(100 - $p, 2), // % chance of exceeding
                ];
            })
            ->filter()
            ->sortBy('loss')
            ->values()
            ->all();

        return [
            'points' => $points,
            'empty' => $points === [],
            'run_reference' => $run->simulation_reference,
            'unit' => 'kobo',
        ];
    }
}
