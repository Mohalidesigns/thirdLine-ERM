<?php

namespace App\Services\Widgets\Types;

use App\Models\TreatmentPlan;
use App\Models\User;
use App\Models\WidgetDefinition;
use App\Services\Widgets\ResolvedPeriods;
use App\Services\Widgets\WidgetContext;
use App\Services\Widgets\WidgetQueryEngine;
use App\Services\Widgets\WidgetScope;
use App\Services\Widgets\WidgetTypeResolver;
use Carbon\CarbonImmutable;

/**
 * activity_table — name, responsible, start, end, progress, RAG. Used twice
 * on the Corporater treatment tab: once filtered ON TRACK, once OFF TRACK
 * (visualisation.mode).
 *
 * RAG is DERIVED, not stored, and the derivation is deliberate:
 *   red    past its target date and not completed — late is late;
 *   amber  target within 14 days with progress below 80% — predictably late;
 *   green  everything else, including completed.
 * The same rule drives the off-track filter, so the two panels partition the
 * same list rather than disagreeing at the edges.
 */
class ActivityTableResolver implements WidgetTypeResolver
{
    private const LIMIT = 25;

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

        $plans = $this->engine->baseQuery($definition, $context, $scope, $periods)
            ->orderByRaw('target_date is null, target_date')
            ->limit(200)
            ->get();

        $owners = User::query()
            ->whereIn('id', $plans->pluck('owner_id')->filter()->unique())
            ->pluck('name', 'id');

        $mode = (string) $definition->visualisationConfig('mode', 'all');

        $rows = $plans
            ->map(function (TreatmentPlan $plan) use ($owners) {
                return [
                    'id' => (int) $plan->id,
                    'name' => $plan->action_title,
                    'responsible' => $owners[$plan->owner_id] ?? null,
                    'start' => $plan->created_at?->format('Y-m-d'),
                    'end' => $plan->target_date?->format('Y-m-d'),
                    'progress_pct' => (int) ($plan->progress_pct ?? 0),
                    'status' => $plan->status,
                    'rag' => $this->rag($plan),
                ];
            })
            ->when($mode === 'on_track', fn ($rows) => $rows->filter(fn (array $r) => $r['rag'] === 'green'))
            ->when($mode === 'off_track', fn ($rows) => $rows->filter(fn (array $r) => $r['rag'] !== 'green'))
            ->take((int) $definition->queryConfig('limit', self::LIMIT))
            ->values()
            ->all();

        return ['rows' => $rows, 'mode' => $mode];
    }

    private function rag(TreatmentPlan $plan): string
    {
        if (in_array($plan->status, ['completed', 'cancelled'], true)) {
            return 'green';
        }

        if ($plan->target_date === null) {
            return 'amber';
        }

        $target = CarbonImmutable::parse($plan->target_date);
        $today = CarbonImmutable::today();

        if ($target->lt($today)) {
            return 'red';
        }

        if ($target->lte($today->addDays(14)) && (int) ($plan->progress_pct ?? 0) < 80) {
            return 'amber';
        }

        return 'green';
    }
}
