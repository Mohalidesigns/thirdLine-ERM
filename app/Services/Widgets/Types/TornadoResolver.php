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
 * tornado — the financial distribution per scenario from the LATEST COMPLETED
 * Monte Carlo run: p5 → p95 span with the median marked, widest span first.
 *
 * Reads simulation_results.percentile_distribution — numbers a seeded,
 * reproducible engine actually computed (MonteCarloService is the platform's
 * one allowlisted RNG). No completed run means an empty panel that says so;
 * a tornado sketched from single-point estimates would be a drawing of
 * precision that was never calculated.
 */
class TornadoResolver implements WidgetTypeResolver
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
            return ['rows' => [], 'empty' => true, 'reason' => 'No completed simulation run.'];
        }

        $results = SimulationResult::query()
            ->where('simulation_run_id', $run->id)
            ->where('result_type', 'scenario')
            ->with('scenario')
            ->get();

        $rows = $results
            ->map(function (SimulationResult $result) {
                $dist = $result->percentile_distribution ?? [];

                $low = $dist['p5'] ?? null;
                $mid = $dist['p50'] ?? null;
                $high = $dist['p95'] ?? null;

                if ($low === null || $high === null) {
                    return null;
                }

                return [
                    'label' => $result->scenario?->name ?? 'Scenario '.$result->scenario_id,
                    // Kobo → naira for display; the payload stays minor-unit
                    // honest and the renderer formats.
                    'low' => (float) $low,
                    'mid' => $mid === null ? null : (float) $mid,
                    'high' => (float) $high,
                    'unit' => 'kobo',
                ];
            })
            ->filter()
            ->sortByDesc(fn (array $row) => $row['high'] - $row['low'])
            ->values()
            ->take((int) $definition->queryConfig('limit', 12))
            ->all();

        return [
            'rows' => $rows,
            'empty' => $rows === [],
            'run_reference' => $run->simulation_reference,
        ];
    }
}
