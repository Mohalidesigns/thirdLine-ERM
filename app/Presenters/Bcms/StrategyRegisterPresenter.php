<?php

namespace App\Presenters\Bcms;

use App\Enums\Bcms\StrategyType;
use App\Models\Bcms\Process;
use App\Models\Bcms\Strategy;
use App\Models\User;
use App\Services\Bcms\Strategy\GapAnalysisService;
use App\Services\Bcms\Strategy\StrategyService;

/**
 * The strategy register (Blueprint §15) — cards per process, the cost/capability
 * scatter, and the gap table.
 *
 * THE SCATTER'S AXES ARE COST AND ACHIEVABLE RTO, and the third dimension is
 * whether the point is inside the required RTO. That is the whole ISO 22331
 * conversation on one chart: cheap and slow in one corner, expensive and fast in
 * the other, and a line showing which of them the BIA says is acceptable.
 *
 * A STRATEGY WITH NO COST OR NO ACHIEVABLE RTO IS NOT PLOTTED, and the count of
 * unplottable options is sent with the chart. Dropping them silently would let a
 * register of twelve options render as a chart of four and read as complete.
 */
class StrategyRegisterPresenter
{
    public function __construct(
        private StrategyService $strategies,
        private GapAnalysisService $gaps,
    ) {}

    /** @return array<string, mixed> */
    public function present(?User $user, ?int $maxTier = 1): array
    {
        $analysis = $this->gaps->analyse($user, $maxTier);

        $processes = Process::query()
            ->where('status', 'active')
            ->visibleTo($user)
            ->with('businessUnit:id,name')
            ->orderByRaw('COALESCE(criticality_tier, 99)')
            ->orderBy('code')
            ->get();

        $strategies = Strategy::query()
            ->whereIn('process_id', $processes->modelKeys())
            ->orderBy('process_id')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Strategy $s) => (int) $s->process_id);

        $cards = [];
        $points = [];
        $unplottable = 0;

        foreach ($processes as $process) {
            $required = $this->strategies->requiredRtoAssessment((int) $process->getKey())?->rto_hours;
            $options = $strategies->get((int) $process->getKey(), collect());

            $cards[] = [
                'process_id' => $process->getKey(),
                // The route key for the per-process compare screen.
                'process_uuid' => $process->uuid,
                'code' => $process->code,
                'name' => $process->name,
                'tier' => $process->criticality_tier,
                'is_critical_service' => (bool) $process->is_critical_service,
                'business_unit' => $process->businessUnit?->name,
                'rto_required_hours' => $required === null ? null : (float) $required,
                'option_count' => $options->count(),
                'selected' => $options->first(fn (Strategy $s) => (bool) $s->is_selected)?->only([
                    'id', 'title', 'approval_status',
                ]),
                'options' => $options->map(fn (Strategy $s) => [
                    'id' => $s->getKey(),
                    'uuid' => $s->uuid,
                    'strategy_type' => $s->strategy_type->value,
                    'strategy_label' => $s->strategy_type->label(),
                    'title' => $s->title,
                    'description' => $s->description,
                    'cost_estimate_minor' => $s->cost_estimate_minor === null ? null : (int) $s->cost_estimate_minor,
                    'currency' => $s->currency,
                    'rto_achievable_hours' => $s->rto_achievable_hours === null
                        ? null : (float) $s->rto_achievable_hours,
                    'gap_vs_required_hours' => $s->gap_vs_required_hours === null
                        ? null : (float) $s->gap_vs_required_hours,
                    'is_selected' => (bool) $s->is_selected,
                    'approval_status' => $s->approval_status,
                    'selection_rationale' => $s->selection_rationale,
                    'resource_requirements' => $s->resource_requirements,
                ])->all(),
            ];

            foreach ($options as $option) {
                if ($option->cost_estimate_minor === null || $option->rto_achievable_hours === null) {
                    $unplottable++;

                    continue;
                }

                $points[] = [
                    'strategy_id' => $option->getKey(),
                    'process_code' => $process->code,
                    'strategy_label' => $option->strategy_type->label(),
                    'cost_minor' => (int) $option->cost_estimate_minor,
                    'rto_achievable_hours' => (float) $option->rto_achievable_hours,
                    'rto_required_hours' => $required === null ? null : (float) $required,
                    'meets_requirement' => $required === null
                        ? null
                        : (float) $option->rto_achievable_hours <= (float) $required,
                    'is_selected' => (bool) $option->is_selected,
                ];
            }
        }

        return [
            'cards' => $cards,
            'gap' => $analysis,
            'scatter' => [
                'points' => $points,
                // Named, not hidden. A chart that quietly dropped eight of
                // twelve options would read as a complete picture.
                'unplottable_count' => $unplottable,
            ],
            'strategy_types' => StrategyType::options(),
            'max_tier' => $maxTier,
            'can' => [
                'manage' => $user?->can('bcms.strategy.manage') === true,
                'approve' => $user?->can('bcms.strategy.approve') === true,
                'export' => $user?->can('bcms.report.export') === true,
            ],
        ];
    }
}
