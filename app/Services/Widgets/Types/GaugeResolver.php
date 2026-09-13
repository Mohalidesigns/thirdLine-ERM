<?php

namespace App\Services\Widgets\Types;

use App\Models\WidgetDefinition;
use App\Services\Widgets\Concerns\ReadsMeasures;
use App\Services\Widgets\ResolvedPeriods;
use App\Services\Widgets\WidgetContext;
use App\Services\Widgets\WidgetScope;
use App\Services\Widgets\WidgetTypeResolver;

/**
 * gauge — one measure value against its threshold bands. The bands come
 * from the measure's ACTIVE threshold row (object-specific override first,
 * measure default second) via MeasureService, so the gauge and the breach
 * engine can never disagree about where amber ends.
 *
 * No recorded value for the period renders an empty gauge; no threshold
 * renders the value without bands. Neither invents the other.
 */
class GaugeResolver implements WidgetTypeResolver
{
    use ReadsMeasures;

    public function resolve(
        WidgetDefinition $definition,
        WidgetContext $context,
        WidgetScope $scope,
        ResolvedPeriods $periods,
    ): array {
        $measure = $definition->measure;

        if ($measure === null || $periods->primary === null || $scope->isEmpty()) {
            return ['value' => null, 'bands' => [], 'band' => null];
        }

        $objectIds = $this->measureObjectIds($measure, $scope);

        if ($objectIds === []) {
            return ['value' => null, 'bands' => [], 'band' => null];
        }

        $matrix = $this->measures()->matrix($measure, $objectIds, [(int) $periods->primary->id]);
        $value = $this->collapse($measure, $matrix, (int) $periods->primary->id);

        $bands = [];
        $currentBand = null;

        // Bands resolve against the anchor object where the scope has one —
        // that is where an object-specific threshold override would live.
        $threshold = $this->measures()->activeThreshold(
            $measure,
            $scope->anchor?->id,
            $periods->primary->end_date instanceof \DateTimeInterface
                ? $periods->primary->end_date->format('Y-m-d')
                : (string) $periods->primary->end_date,
        );

        if ($threshold !== null) {
            $bands = array_values(array_map(fn (array $band) => [
                'code' => (string) ($band['code'] ?? ''),
                'label' => (string) ($band['label'] ?? ''),
                'color' => (string) ($band['color'] ?? '#64748b'),
                'min' => isset($band['min']) ? (float) $band['min'] : null,
                'max' => isset($band['max']) ? (float) $band['max'] : null,
            ], $this->measures()->resolveBands($threshold, ['object_id' => $scope->anchor?->id])));

            if ($value !== null) {
                $match = $threshold->bandFor($value, $this->measures()->resolveBands($threshold, ['object_id' => $scope->anchor?->id]));
                $currentBand = $match['code'] ?? null;
            }
        }

        return [
            'value' => $value,
            'formatted' => $value === null ? null : $measure->format($value),
            'unit' => $measure->unit?->symbol,
            'bands' => $bands,
            'band' => $currentBand,
        ];
    }
}
