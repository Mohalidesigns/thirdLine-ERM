<?php

namespace App\Services\Widgets;

use App\Models\WidgetDefinition;
use App\Services\Widgets\Types\ActivityTableResolver;
use App\Services\Widgets\Types\BarByTypeResolver;
use App\Services\Widgets\Types\BubbleResolver;
use App\Services\Widgets\Types\CumulativeLineResolver;
use App\Services\Widgets\Types\DonutResolver;
use App\Services\Widgets\Types\GaugeResolver;
use App\Services\Widgets\Types\GroupedBarResolver;
use App\Services\Widgets\Types\HeatmapResolver;
use App\Services\Widgets\Types\KpiTileResolver;
use App\Services\Widgets\Types\LecCurveResolver;
use App\Services\Widgets\Types\MeasureTableResolver;
use App\Services\Widgets\Types\NetworkResolver;
use App\Services\Widgets\Types\OpportunityHeatmapResolver;
use App\Services\Widgets\Types\ParetoResolver;
use App\Services\Widgets\Types\RegisterResolver;
use App\Services\Widgets\Types\StackedAreaResolver;
use App\Services\Widgets\Types\StackedBarBandsResolver;
use App\Services\Widgets\Types\TimelineResolver;
use App\Services\Widgets\Types\TornadoResolver;
use App\Services\Widgets\Types\TreemapResolver;
use App\Services\Widgets\Types\TrendStackedBarResolver;
use Illuminate\Support\Facades\Log;

/**
 * WP-08 TASK 2 — the widget engine's front door.
 *
 * render() takes a definition and a context and returns the one payload shape
 * every consumer understands — the Blade component, the export, the digest
 * email. The payload always renders: a failing widget degrades to its own
 * error panel and the rest of the page stands, because one misconfigured
 * tile must never take down the board pack.
 *
 * Authorization here is the SOURCE gate: a viewer without loss_event.view
 * gets a locked panel from a loss widget, whatever dashboard somebody
 * composed it onto. Node scope (which slice of the org) is the
 * WidgetContextResolver's job; tenancy is the models'.
 */
class WidgetDataService
{
    /** @var array<string, class-string<WidgetTypeResolver>> */
    public const RESOLVERS = [
        'kpi_tile' => KpiTileResolver::class,
        'register' => RegisterResolver::class,
        'heatmap' => HeatmapResolver::class,
        'opportunity_heatmap' => OpportunityHeatmapResolver::class,
        'stacked_bar_bands' => StackedBarBandsResolver::class,
        'pareto' => ParetoResolver::class,
        'grouped_bar_3' => GroupedBarResolver::class,
        'bubble' => BubbleResolver::class,
        'stacked_area' => StackedAreaResolver::class,
        'trend_stacked_bar' => TrendStackedBarResolver::class,
        'activity_table' => ActivityTableResolver::class,
        'measure_table' => MeasureTableResolver::class,
        'cumulative_line' => CumulativeLineResolver::class,
        'donut' => DonutResolver::class,
        'bar_by_type' => BarByTypeResolver::class,
        'tornado' => TornadoResolver::class,
        'lec_curve' => LecCurveResolver::class,
        'gauge' => GaugeResolver::class,
        'treemap' => TreemapResolver::class,
        'timeline' => TimelineResolver::class,
        'network' => NetworkResolver::class,
    ];

    public function __construct(
        private readonly WidgetContextResolver $contexts,
        private readonly WidgetPeriodResolver $periods,
        private readonly WidgetSourceRegistry $registry,
    ) {}

    /**
     * @param  array<string, mixed>  $overrides  per-placement patches from the
     *                                           dashboard layout (title,
     *                                           filters, visualisation)
     * @return array<string, mixed>
     */
    public function render(WidgetDefinition $definition, WidgetContext $context, array $overrides = []): array
    {
        $title = (string) ($overrides['title'] ?? $definition->name);

        if (! $this->authorized($definition, $context)) {
            return $this->envelope($definition, $context, $title, 'forbidden');
        }

        if (is_array($overrides['filters'] ?? null)) {
            $context = $context->withFilters($overrides['filters']);
        }

        try {
            $scope = $this->contexts->resolve($definition, $context);
            $periods = $this->periods->resolve($definition, $context);

            $resolverClass = self::RESOLVERS[$definition->widget_type] ?? null;

            if ($resolverClass === null) {
                throw new \InvalidArgumentException("Unknown widget type [{$definition->widget_type}].");
            }

            /** @var WidgetTypeResolver $resolver */
            $resolver = app($resolverClass);

            $data = $resolver->resolve($definition, $context, $scope, $periods);

            return $this->envelope($definition, $context, $title, 'ok', $data, $scope, $periods, $overrides);
        } catch (\Throwable $e) {
            Log::warning('Widget render failed', [
                'widget' => $definition->code,
                'type' => $definition->widget_type,
                'error' => $e->getMessage(),
            ]);

            return $this->envelope($definition, $context, $title, 'error');
        }
    }

    /** The viewer must hold the module permission of the source being read. */
    private function authorized(WidgetDefinition $definition, WidgetContext $context): bool
    {
        $sourceKey = $definition->queryConfig('source');
        $source = $this->registry->source(is_string($sourceKey) ? $sourceKey : null);

        // Measure-driven widgets without a row source gate on measure.view.
        $permission = $source['permission'] ?? ($definition->measure_id !== null ? 'measure.view' : null);

        return $permission === null || $context->user->can($permission);
    }

    /**
     * @return array<string, mixed>
     */
    private function envelope(
        WidgetDefinition $definition,
        WidgetContext $context,
        string $title,
        string $state,
        array $data = [],
        ?WidgetScope $scope = null,
        ?ResolvedPeriods $periods = null,
        array $overrides = [],
    ): array {
        return [
            'state' => $state, // ok | forbidden | error
            'widget_id' => $definition->id,
            'code' => $definition->code,
            'type' => $definition->widget_type,
            'title' => $title,
            'data' => $data,
            'visualisation' => array_replace(
                $definition->visualisation ?? [],
                is_array($overrides['visualisation'] ?? null) ? $overrides['visualisation'] : [],
            ),
            'drilldown' => $definition->drilldown,
            'meta' => [
                'node' => $scope?->anchor?->name ?? $context->node?->name,
                'node_id' => $scope?->anchor?->id ?? $context->node?->id,
                'context_binding' => $definition->context_binding,
                'period' => $periods?->label(),
                'period_binding' => $definition->period_binding,
                'generated_at' => now()->toIso8601String(),
            ],
        ];
    }
}
