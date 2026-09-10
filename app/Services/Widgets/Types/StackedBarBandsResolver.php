<?php

namespace App\Services\Widgets\Types;

use App\Models\GraphObject;
use App\Models\WidgetDefinition;
use App\Services\RiskScoringService;
use App\Services\Widgets\ResolvedPeriods;
use App\Services\Widgets\WidgetContext;
use App\Services\Widgets\WidgetQueryEngine;
use App\Services\Widgets\WidgetScope;
use App\Services\Widgets\WidgetTypeResolver;

/**
 * stacked_bar_bands — risk per organizational unit, one bar per unit,
 * stacked by severity band.
 *
 * Units are the anchor node's DIRECT child org nodes; each bar counts the
 * risks in that child's whole subtree, which is the graph-derived roll-up
 * the Corporater screenshot shows. Bands come from visualisation.bands where
 * the widget declares the five-band Corporater split (Deep Red / Red /
 * Orange / Amber / Green as score ranges), falling back to the scoring
 * profile's own bands — configuration, never invention.
 */
class StackedBarBandsResolver implements WidgetTypeResolver
{
    public function __construct(
        private readonly WidgetQueryEngine $engine,
        private readonly RiskScoringService $scoring,
    ) {}

    public function resolve(
        WidgetDefinition $definition,
        WidgetContext $context,
        WidgetScope $scope,
        ResolvedPeriods $periods,
    ): array {
        if ($scope->isEmpty()) {
            return ['bands' => [], 'units' => []];
        }

        $bands = $this->bands($definition, $context);
        $scoreColumn = $definition->queryConfig('score_basis', 'residual') === 'inherent'
            ? 'inherent_score'
            : 'residual_score';

        $units = $this->units($scope, $context);

        if ($units === []) {
            return ['bands' => $bands, 'units' => []];
        }

        foreach ($units as $index => $unit) {
            $units[$index]['counts'] = array_fill(0, count($bands), 0);
        }

        // One grouped query: node_id × score, then fold scores into bands and
        // nodes into the unit whose subtree they fall in.
        $unitByNodeId = [];
        foreach ($units as $index => $unit) {
            foreach ($unit['node_ids'] as $nodeId) {
                $unitByNodeId[$nodeId] = $index;
            }
        }

        $rows = $this->engine->baseQuery($definition, $context, $scope, $periods)
            ->toBase()
            ->selectRaw('node_id, '.$scoreColumn.' as score, count(*) as c')
            ->whereNotNull($scoreColumn)
            ->groupBy('node_id', 'score')
            ->get();

        foreach ($rows as $row) {
            $unitIndex = $unitByNodeId[(int) $row->node_id] ?? null;

            if ($unitIndex === null) {
                continue;
            }

            $bandIndex = $this->bandIndexFor((float) $row->score, $bands);

            if ($bandIndex !== null) {
                $units[$unitIndex]['counts'][$bandIndex] += (int) $row->c;
            }
        }

        return [
            'bands' => $bands,
            'units' => array_map(fn (array $unit) => [
                'label' => $unit['label'],
                'node_id' => $unit['node_id'],
                'counts' => $unit['counts'],
            ], $units),
        ];
    }

    /**
     * @return list<array{code: string, label: string, color: string, min: float, max: float}>
     */
    private function bands(WidgetDefinition $definition, WidgetContext $context): array
    {
        $declared = $definition->visualisationConfig('bands');

        if (is_array($declared) && $declared !== []) {
            return array_values(array_map(fn (array $band) => [
                'code' => (string) ($band['code'] ?? $band['label'] ?? ''),
                'label' => (string) ($band['label'] ?? $band['code'] ?? ''),
                'color' => (string) ($band['color'] ?? '#64748b'),
                'min' => (float) ($band['min'] ?? 0),
                'max' => (float) ($band['max'] ?? 0),
            ], $declared));
        }

        $profile = $this->scoring->profileFor(null, $context->organizationId(), null, null, null);

        return array_values(array_map(fn (array $band) => [
            'code' => (string) ($band['code'] ?? ''),
            'label' => (string) ($band['label'] ?? ''),
            'color' => (string) ($band['color'] ?? '#64748b'),
            'min' => (float) ($band['min'] ?? 0),
            'max' => (float) ($band['max'] ?? 0),
        ], $profile->rating_bands ?? []));
    }

    private function bandIndexFor(float $score, array $bands): ?int
    {
        foreach ($bands as $index => $band) {
            if ($score >= $band['min'] && $score <= $band['max']) {
                return $index;
            }
        }

        return null;
    }

    /**
     * The anchor's direct child org nodes, each with its full subtree ids.
     *
     * @return list<array{label: string, node_id: int, node_ids: list<int>, counts: list<int>}>
     */
    private function units(WidgetScope $scope, WidgetContext $context): array
    {
        $graph = app(\App\Services\Graph\GraphQueryService::class);

        $children = $scope->anchor !== null
            ? GraphObject::query()->nodes()->where('parent_id', $scope->anchor->id)->orderBy('sort_order')->orderBy('name')->get()
            // No anchor (org-wide): top-level org nodes.
            : GraphObject::query()->nodes()->whereNull('parent_id')->orderBy('sort_order')->orderBy('name')->get();

        // A leaf anchor has no children; the node itself is then the one unit,
        // so the widget degrades to a single bar rather than an empty panel.
        if ($children->isEmpty() && $scope->anchor !== null) {
            $children = collect([$scope->anchor]);
        }

        $inScope = $scope->isUnrestricted() ? null : array_flip($scope->nodeIds ?? []);

        return $children
            ->map(function (GraphObject $child) use ($graph, $context, $inScope) {
                $nodeIds = collect([(int) $child->id])
                    ->merge($graph->descendantIds((int) $child->id, [], null, $context->user))
                    ->when($inScope !== null, fn ($ids) => $ids->filter(fn (int $id) => isset($inScope[$id])))
                    ->unique()
                    ->values()
                    ->all();

                return [
                    'label' => $child->name,
                    'node_id' => (int) $child->id,
                    'node_ids' => $nodeIds,
                    'counts' => [],
                ];
            })
            ->filter(fn (array $unit) => $unit['node_ids'] !== [])
            ->values()
            ->all();
    }
}
