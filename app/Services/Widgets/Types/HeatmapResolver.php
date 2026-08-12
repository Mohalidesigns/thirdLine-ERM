<?php

namespace App\Services\Widgets\Types;

use App\Models\WidgetDefinition;
use App\Repositories\RiskRepository;
use App\Services\RiskScoringService;
use App\Services\Widgets\ResolvedPeriods;
use App\Services\Widgets\WidgetContext;
use App\Services\Widgets\WidgetQueryEngine;
use App\Services\Widgets\WidgetScope;
use App\Services\Widgets\WidgetTypeResolver;
use Carbon\CarbonImmutable;

/**
 * heatmap — Probability × Consequence with a COUNT BADGE in every cell and
 * click-through to the register filtered to that cell.
 *
 * The matrix shape, axis labels and cell colours come from the organization's
 * SCORING PROFILE — a 4×6 tenant renders 4×6, because the profile is the
 * single definition of what a score means (WP-05). Nothing here assumes 5×5.
 *
 * PERIOD HONESTY, inherited from the old dashboard and kept deliberately:
 * with a period selected that has already ended, counts rebuild from
 * RiskRepository::asOf() — the register as it stood then, carry-forward
 * semantics included — and the payload flags `as_of` so the panel labels
 * itself. A live period counts current rows.
 */
class HeatmapResolver implements WidgetTypeResolver
{
    public function __construct(
        private readonly WidgetQueryEngine $engine,
        private readonly RiskScoringService $scoring,
        private readonly RiskRepository $risks,
    ) {}

    public function resolve(
        WidgetDefinition $definition,
        WidgetContext $context,
        WidgetScope $scope,
        ResolvedPeriods $periods,
    ): array {
        $basis = $definition->queryConfig('score_basis', 'residual') === 'inherent' ? 'inherent' : 'residual';

        $profile = $this->scoring->profileFor(
            null,
            $context->organizationId(),
            $scope->anchor?->id === null ? null : (int) $scope->anchor->id,
            $definition->object_type_id,
            null,
        );

        $rows = (int) $profile->matrix_rows;
        $cols = (int) $profile->matrix_cols;

        [$counts, $asOf] = $this->cellCounts($definition, $context, $scope, $periods, $basis, $rows, $cols);

        $cells = [];
        for ($likelihood = $rows; $likelihood >= 1; $likelihood--) {
            for ($impact = 1; $impact <= $cols; $impact++) {
                $score = $this->scoring->calculateScore($likelihood, $impact, $profile);
                $band = $this->scoring->ratingBand($score, $profile);

                $cells[] = [
                    'likelihood' => $likelihood,
                    'impact' => $impact,
                    'count' => $counts[$likelihood][$impact] ?? 0,
                    'score' => $score,
                    'band' => $band['code'] ?? null,
                    'color' => $band['color'] ?? null,
                    // What the register drill-through filters on.
                    'filters' => [
                        $basis.'_likelihood' => $likelihood,
                        $basis.'_impact' => $impact,
                    ],
                ];
            }
        }

        return [
            'rows' => $rows,
            'cols' => $cols,
            'basis' => $basis,
            'polarity' => 'risk',
            'as_of' => $asOf,
            'axis' => [
                'likelihood' => $profile->axisLabels('likelihood'),
                'impact' => $profile->axisLabels('impact'),
            ],
            'cells' => $cells,
            'total' => array_sum(array_map('array_sum', $counts)),
        ];
    }

    /**
     * @return array{0: array<int, array<int, int>>, 1: ?string}
     */
    private function cellCounts(
        WidgetDefinition $definition,
        WidgetContext $context,
        WidgetScope $scope,
        ResolvedPeriods $periods,
        string $basis,
        int $rows,
        int $cols,
    ): array {
        $likelihoodCol = $basis.'_likelihood';
        $impactCol = $basis.'_impact';

        $period = $periods->primary;
        $counts = [];

        if ($period !== null && CarbonImmutable::parse($period->end_date)->isPast()) {
            // The register as it stood at the end of the selected period.
            $register = $this->risks->asOf($period, ['status' => ['active', 'monitoring']], $context->organizationId());

            if (! $scope->isUnrestricted()) {
                $nodeIds = array_flip($scope->nodeIds ?? []);
                $register = $register->filter(fn ($risk) => isset($nodeIds[(int) $risk->node_id]));
            }

            foreach ($register as $risk) {
                $l = (int) $risk->{$likelihoodCol};
                $i = (int) $risk->{$impactCol};
                if ($l >= 1 && $l <= $rows && $i >= 1 && $i <= $cols) {
                    $counts[$l][$i] = ($counts[$l][$i] ?? 0) + 1;
                }
            }

            return [$counts, $period->name ?? $period->code];
        }

        if ($scope->isEmpty()) {
            return [[], null];
        }

        $grouped = $this->engine->baseQuery($definition, $context, $scope, $periods)
            ->toBase()
            ->selectRaw($likelihoodCol.' as l, '.$impactCol.' as i, count(*) as c')
            ->whereNotNull($likelihoodCol)
            ->whereNotNull($impactCol)
            ->groupBy('l', 'i')
            ->get();

        foreach ($grouped as $row) {
            $l = (int) $row->l;
            $i = (int) $row->i;
            if ($l >= 1 && $l <= $rows && $i >= 1 && $i <= $cols) {
                $counts[$l][$i] = (int) $row->c;
            }
        }

        return [$counts, null];
    }
}
