<?php

namespace App\Services\Widgets\Types;

use App\Models\BusinessUnit;
use App\Models\RiskCategory;
use App\Models\WidgetDefinition;
use App\Services\Widgets\ResolvedPeriods;
use App\Services\Widgets\WidgetContext;
use App\Services\Widgets\WidgetQueryEngine;
use App\Services\Widgets\WidgetScope;
use App\Services\Widgets\WidgetTypeResolver;

/**
 * treemap — area-proportional composition, the "where is the mass" view.
 * The engine's grouped aggregate with foreign-key group keys resolved to
 * names in one lookup (category_id → RiskCategory, business_unit_id →
 * BusinessUnit); any other key renders as itself.
 */
class TreemapResolver implements WidgetTypeResolver
{
    public function __construct(private readonly WidgetQueryEngine $engine) {}

    public function resolve(
        WidgetDefinition $definition,
        WidgetContext $context,
        WidgetScope $scope,
        ResolvedPeriods $periods,
    ): array {
        if ($scope->isEmpty()) {
            return ['tiles' => []];
        }

        $rows = $this->engine->aggregate($definition, $context, $scope, $periods);

        $labels = $this->labelMap((string) $definition->queryConfig('group_by', ''), $rows->pluck('key'));

        $tiles = $rows
            ->map(fn (object $row) => [
                'label' => $labels[$row->key] ?? ($row->key === null ? 'Unspecified' : (string) $row->key),
                'value' => $row->value,
            ])
            ->filter(fn (array $tile) => $tile['value'] > 0)
            ->values()
            ->all();

        return ['tiles' => $tiles];
    }

    /** @return array<int|string, string> */
    private function labelMap(string $groupBy, iterable $keys): array
    {
        $ids = collect($keys)->filter(fn ($k) => is_numeric($k))->map(fn ($k) => (int) $k)->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return match ($groupBy) {
            'category_id' => RiskCategory::query()->whereIn('id', $ids)->pluck('name', 'id')->all(),
            'business_unit_id' => BusinessUnit::query()->whereIn('id', $ids)->pluck('name', 'id')->all(),
            default => [],
        };
    }
}
