<?php

namespace App\Services\Widgets\Concerns;

use App\Models\GraphObject;
use App\Models\Measure;
use App\Services\MeasureService;
use App\Services\Widgets\WidgetScope;

/**
 * Shared plumbing for measure-driven resolvers.
 *
 * A measure value attaches to an OBJECT (measure_values.object_id), so "this
 * measure, in this scope" means: the objects that are nodes in scope or hang
 * off one, narrowed to the measure's object type where it declares one, then
 * one matrix() call. Composing it here rather than per-resolver keeps every
 * widget's idea of "in scope" identical — the property the acceptance test
 * pins.
 */
trait ReadsMeasures
{
    /**
     * @return list<int> object ids carrying values for this measure in scope
     */
    protected function measureObjectIds(Measure $measure, WidgetScope $scope): array
    {
        $query = GraphObject::query();

        if ($measure->object_type_id !== null) {
            $query->where('object_type_id', $measure->object_type_id);
        }

        if (! $scope->isUnrestricted()) {
            $nodeIds = $scope->nodeIds ?? [];
            $query->where(function ($q) use ($nodeIds) {
                $q->whereIn('id', $nodeIds)->orWhereIn('node_id', $nodeIds);
            });
        }

        return $query->toBase()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Collapse a matrix() result for one period across objects, honouring the
     * measure's own aggregation. Returns null — never zero — when no object
     * carried a value.
     *
     * @param  array<int, array<int, float>>  $matrix  [objectId][periodId] => value
     */
    protected function collapse(Measure $measure, array $matrix, int $periodId): ?float
    {
        $values = [];

        foreach ($matrix as $byPeriod) {
            if (array_key_exists($periodId, $byPeriod)) {
                $values[] = (float) $byPeriod[$periodId];
            }
        }

        if ($values === []) {
            return null;
        }

        return match ($measure->aggregation) {
            'avg', 'weighted_avg' => array_sum($values) / count($values),
            'max' => max($values),
            'min' => min($values),
            'count' => (float) count($values),
            'last' => end($values),
            default => array_sum($values),
        };
    }

    protected function measures(): MeasureService
    {
        return app(MeasureService::class);
    }
}
