<?php

namespace App\Services\Widgets\Types;

use App\Models\Control;
use App\Models\User;
use App\Models\WidgetDefinition;
use App\Services\Widgets\ResolvedPeriods;
use App\Services\Widgets\WidgetContext;
use App\Services\Widgets\WidgetQueryEngine;
use App\Services\Widgets\WidgetScope;
use App\Services\Widgets\WidgetTypeResolver;

/**
 * measure_table — control measure, type, responsible, implemented ✓, status
 * glyph. The Corporater screenshot uses it twice: CONTROLS (everything) and
 * NOT IMPLEMENTED / FINDINGS (visualisation.mode = 'not_implemented').
 *
 * "Implemented" is the control's own status column; the glyph is its tested
 * effectiveness where a test exists (last_test_result), its rated
 * effectiveness otherwise, and honestly absent when neither has been
 * recorded — an untested control shows a hollow glyph, not a green tick.
 */
class MeasureTableResolver implements WidgetTypeResolver
{
    public function __construct(private readonly WidgetQueryEngine $engine) {}

    public function resolve(
        WidgetDefinition $definition,
        WidgetContext $context,
        WidgetScope $scope,
        ResolvedPeriods $periods,
    ): array {
        if ($scope->isEmpty()) {
            return ['rows' => []];
        }

        $controls = $this->engine->baseQuery($definition, $context, $scope, $periods)
            ->orderBy('name')
            ->limit(200)
            ->get();

        $owners = User::query()
            ->whereIn('id', $controls->pluck('owner_id')->filter()->unique())
            ->pluck('name', 'id');

        $mode = (string) $definition->visualisationConfig('mode', 'all');

        $rows = $controls
            ->map(function (Control $control) use ($owners) {
                $implemented = $control->status === 'active';
                $glyph = $control->last_test_result ?: $control->effectiveness_rating;

                return [
                    'id' => (int) $control->id,
                    'name' => $control->name,
                    'type' => $control->control_type,
                    'responsible' => $owners[$control->owner_id] ?? null,
                    'implemented' => $implemented,
                    'glyph' => $glyph === null ? null : strtolower((string) $glyph),
                ];
            })
            ->when($mode === 'not_implemented', fn ($rows) => $rows->filter(
                fn (array $r) => ! $r['implemented'] || in_array($r['glyph'], ['ineffective', 'failed'], true),
            ))
            ->take((int) $definition->queryConfig('limit', 25))
            ->values()
            ->all();

        return ['rows' => $rows, 'mode' => $mode];
    }
}
