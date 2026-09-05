<?php

namespace App\Services\Quantification;

use App\Models\IcaapAssessment;
use App\Models\QuantificationScenario;
use App\Models\SimulationRun;
use App\Support\Tenancy\TenantContext;

/**
 * The quantification dashboard's figures (migration Phase 5.2).
 *
 * Lifted out of QuantificationController::dashboard(), which was 117 lines of
 * capital arithmetic inside a controller — the shape icaap() had before
 * {@see IcaapService}, and extracted for the same reason. The capital
 * arithmetic itself stays in IcaapService so the dashboard and the ICAAP screen
 * cannot drift apart.
 *
 * The WP-08 notes below travel with the code because they are the record of
 * what an August 2026 audit found on this screen.
 */
class QuantificationDashboardService
{
    /**
     * The percentiles the loss-distribution chart plots, in order.
     *
     * Only what the engine stored: a level it did not compute produces no
     * point rather than an interpolated one.
     */
    private const LOSS_PERCENTILES = [
        'p5' => '5%', 'p10' => '10%', 'p25' => '25%', 'p50' => '50%',
        'p75' => '75%', 'p90' => '90%', 'p95' => '95%', 'p99' => '99%',
    ];

    public function __construct(private readonly IcaapService $icaap) {}

    /**
     * @return array<string, mixed>
     */
    public function figures(?int $organizationId = null): array
    {
        $orgId = $organizationId ?? TenantContext::organizationId();

        $latestSim = SimulationRun::where('organization_id', $orgId)
            ->where('status', 'completed')
            ->orderByDesc('completed_at')
            ->first();

        $latestIcaap = IcaapAssessment::where('organization_id', $orgId)
            ->orderByDesc('created_at')->first();

        return array_merge(
            [
                'activeScenarios' => QuantificationScenario::where('organization_id', $orgId)
                    ->where('status', 'active')->count(),
                'simulationsRun' => SimulationRun::where('organization_id', $orgId)
                    ->where('status', 'completed')->count(),

                // Null, not zero, when there is nothing to report.
                // `expected_shortfall` used to return VaR(99) under an "ES
                // approximated as average of losses above VaR 95" comment — two
                // different statistics — and the controller then coerced a
                // missing figure to 0, so the tile read "₦0" whether the tail
                // mean was genuinely zero or had never been computed. The
                // accessor returns the stored tail mean or null, and the page
                // renders an explicit not-assessed state for null.
                'var95' => $latestSim?->var_95,
                'expectedShortfall' => $latestSim?->expected_shortfall,
                'lossDistData' => $this->lossDistribution($latestSim),
            ],
            $this->capitalPosition($latestIcaap, $orgId),
        );
    }

    /**
     * The capital figures, and the three mislabels WP-08 removed.
     *
     * All three were mirrored from the ICAAP screen and are corrected the same
     * way. (i) The regulatory minimum was hardcoded to 10% in the blade; it is
     * resolved. (ii) CAR was the free-typed `car_actual`; it is computed from
     * capital and RWA, null when that is impossible, with the typed figure only
     * as a stated fallback. (iii) The `pillar2a_*` columns were summed into
     * "Pillar 1 Capital" and `pillar2b_stress_buffer_kobo` was charted as
     * "Liquidity Risk" — neither is what those columns hold.
     *
     * @return array<string, mixed>
     */
    private function capitalPosition(?IcaapAssessment $icaap, int $orgId): array
    {
        $computed = $this->icaap->capitalRatioPercent(
            $icaap?->total_qualifying_capital_kobo,
            $icaap?->total_rwa_kobo,
        );

        $reported = ($icaap !== null && $icaap->car_actual !== null)
            ? round((float) $icaap->car_actual, 2)
            : null;

        $totalCapital = $this->icaap->naira($icaap?->total_qualifying_capital_kobo);
        $pillar2a = $this->pillar2aTotal($icaap);
        $pillar2b = $this->icaap->naira($icaap?->pillar2b_stress_buffer_kobo);

        return [
            'hasAssessment' => $icaap !== null,
            'minimumCar' => $this->icaap->resolveMinimumCar($icaap, $orgId),
            'capitalAdequacyRatio' => $computed ?? $reported,
            'carReported' => $reported,
            'carBasis' => $computed !== null ? 'computed from capital / RWA' : 'as reported',
            'tier1' => $this->icaap->naira($icaap?->tier1_capital_kobo),
            'tier2' => $this->icaap->naira($icaap?->tier2_capital_kobo),
            'totalCapital' => $totalCapital,
            'pillar2aCapital' => $pillar2a,
            'pillar2bCapital' => $pillar2b,

            // WP-08's rule, applied to the headline tile it had missed. The
            // controller computed this as `($pillar2a ?? 0) + ($pillar2b ?? 0)`
            // and the tile printed the result as "ICAAP Capital Add-on ·
            // Pillar 2A + Pillar 2B, AS ASSESSED". A bank with no assessment on
            // file therefore read "₦0 as assessed" — a specific claim that its
            // capital add-on is nil, made from no data at all. It is null now
            // unless at least one pillar is on record, and the sum covers only
            // the pillars that are.
            'totalEconomicCapital' => $this->addOnTotal($pillar2a, $pillar2b),

            // Unchanged: buffer needs both deductions, as the screen already had it.
            'capitalBuffer' => ($totalCapital !== null && $pillar2a !== null && $pillar2b !== null)
                ? round($totalCapital - $pillar2a - $pillar2b, 2)
                : null,

            // ICAAP capital add-on by component. The old chart headed these
            // "Credit / Market / Operational / Liquidity / Other" as though they
            // were a risk-type decomposition of economic capital; four of them
            // are Pillar 2A columns and the fifth is the Pillar 2B stress
            // buffer, which has nothing to do with liquidity risk.
            'capitalByTypeData' => [
                'labels' => ['Pillar 2A — Credit', 'Pillar 2A — Market', 'Pillar 2A — Operational', 'Pillar 2A — Other', 'Pillar 2B — Stress Buffer'],
                'values' => [
                    $this->icaap->naira($icaap?->pillar2a_credit_kobo),
                    $this->icaap->naira($icaap?->pillar2a_market_kobo),
                    $this->icaap->naira($icaap?->pillar2a_operational_kobo),
                    $this->icaap->naira($icaap?->pillar2a_other_kobo),
                    $this->icaap->naira($icaap?->pillar2b_stress_buffer_kobo),
                ],
            ],
        ];
    }

    /**
     * Pillar 2A across its four components, counting only those on record.
     */
    private function pillar2aTotal(?IcaapAssessment $icaap): ?float
    {
        if ($icaap === null) {
            return null;
        }

        $components = array_filter([
            $this->icaap->naira($icaap->pillar2a_credit_kobo),
            $this->icaap->naira($icaap->pillar2a_market_kobo),
            $this->icaap->naira($icaap->pillar2a_operational_kobo),
            $this->icaap->naira($icaap->pillar2a_other_kobo),
        ], fn ($value) => $value !== null);

        return $components === [] ? null : round(array_sum($components), 2);
    }

    /** The add-on across both pillars, or null when neither is on record. */
    private function addOnTotal(?float $pillar2a, ?float $pillar2b): ?float
    {
        if ($pillar2a === null && $pillar2b === null) {
            return null;
        }

        return round(($pillar2a ?? 0) + ($pillar2b ?? 0), 2);
    }

    /**
     * @return array{labels: list<string>, values: list<float>}
     */
    private function lossDistribution(?SimulationRun $run): array
    {
        $series = ['labels' => [], 'values' => []];

        $aggregate = $run?->aggregate_result;

        if (! $aggregate || ! is_array($aggregate->percentile_distribution)) {
            return $series;
        }

        foreach (self::LOSS_PERCENTILES as $key => $label) {
            if (isset($aggregate->percentile_distribution[$key])) {
                $series['labels'][] = $label;
                $series['values'][] = round($aggregate->percentile_distribution[$key] / 100, 2);
            }
        }

        return $series;
    }
}
