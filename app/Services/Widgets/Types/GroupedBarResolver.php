<?php

namespace App\Services\Widgets\Types;

use App\Models\BusinessUnit;
use App\Models\RiskCategory;
use App\Models\TreatmentPlan;
use App\Models\WidgetDefinition;
use App\Services\Widgets\ResolvedPeriods;
use App\Services\Widgets\WidgetContext;
use App\Services\Widgets\WidgetQueryEngine;
use App\Services\Widgets\WidgetScope;
use App\Services\Widgets\WidgetSourceRegistry;
use App\Services\Widgets\WidgetTypeResolver;
use InvalidArgumentException;

/**
 * grouped_bar_3 — Inherent vs Residual vs Planned residual, one bar triple
 * per group (category by default).
 *
 * The first two series average the scores the register already carries. The
 * third is the score the treatment plans EXPECT: the average of
 * expected_residual_likelihood × expected_residual_impact over plans linked
 * to the risks in the group, counting only plans where somebody entered both
 * numbers. A group whose plans never stated an expected residual gets NULL
 * for the third bar — an absent plan is not a plan to stay where you are, and
 * charting it as one would be invention.
 */
class GroupedBarResolver implements WidgetTypeResolver
{
    public function __construct(
        private readonly WidgetQueryEngine $engine,
        private readonly WidgetSourceRegistry $registry,
    ) {}

    public function resolve(
        WidgetDefinition $definition,
        WidgetContext $context,
        WidgetScope $scope,
        ResolvedPeriods $periods,
    ): array {
        if ($scope->isEmpty()) {
            return ['groups' => []];
        }

        $sourceKey = (string) $definition->queryConfig('source', '');
        $groupBy = (string) $definition->queryConfig('group_by', 'category_id');

        if (! $this->registry->allowsColumn($sourceKey, $groupBy)) {
            throw new InvalidArgumentException("Widget group_by [{$groupBy}] is not an allowed column of [{$sourceKey}].");
        }

        $risks = $this->engine->baseQuery($definition, $context, $scope, $periods)
            ->toBase()
            ->get(['id', $groupBy.' as grp', 'inherent_score', 'residual_score']);

        if ($risks->isEmpty()) {
            return ['groups' => []];
        }

        // The planned series joins through risk_id, restricted to the risks
        // already in scope — the plans inherit the risks' scope rather than
        // being scoped twice.
        $planned = TreatmentPlan::query()
            ->whereIn('risk_id', $risks->pluck('id')->all())
            ->whereNotNull('expected_residual_likelihood')
            ->whereNotNull('expected_residual_impact')
            ->toBase()
            ->get(['risk_id', 'expected_residual_likelihood', 'expected_residual_impact']);

        $plannedByRisk = [];
        foreach ($planned as $plan) {
            $plannedByRisk[(int) $plan->risk_id][] =
                (float) $plan->expected_residual_likelihood * (float) $plan->expected_residual_impact;
        }

        $groups = [];

        foreach ($risks as $risk) {
            $key = $risk->grp === null ? '' : (string) $risk->grp;
            $groups[$key] ??= ['inherent' => [], 'residual' => [], 'planned' => []];

            if ($risk->inherent_score !== null) {
                $groups[$key]['inherent'][] = (float) $risk->inherent_score;
            }

            if ($risk->residual_score !== null) {
                $groups[$key]['residual'][] = (float) $risk->residual_score;
            }

            foreach ($plannedByRisk[(int) $risk->id] ?? [] as $score) {
                $groups[$key]['planned'][] = $score;
            }
        }

        $labels = $this->labels($groupBy, array_keys($groups));

        $payload = [];
        foreach ($groups as $key => $series) {
            $payload[] = [
                'label' => $labels[$key] ?? ($key === '' ? 'Unspecified' : (string) $key),
                'inherent' => $this->average($series['inherent']),
                'residual' => $this->average($series['residual']),
                'planned' => $this->average($series['planned']),
            ];
        }

        usort($payload, fn (array $a, array $b) => strnatcasecmp($a['label'], $b['label']));

        return ['groups' => $payload];
    }

    /** @param  list<float>  $values */
    private function average(array $values): ?float
    {
        return $values === [] ? null : round(array_sum($values) / count($values), 1);
    }

    /**
     * @param  list<int|string>  $keys
     * @return array<int|string, string>
     */
    private function labels(string $groupBy, array $keys): array
    {
        $ids = array_filter($keys, fn ($key) => $key !== '');

        if ($ids === []) {
            return [];
        }

        return match ($groupBy) {
            'category_id' => RiskCategory::query()->whereIn('id', $ids)->pluck('name', 'id')->all(),
            'business_unit_id' => BusinessUnit::query()->whereIn('id', $ids)->pluck('name', 'id')->all(),
            default => [],
        };
    }
}
