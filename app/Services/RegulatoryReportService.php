<?php

namespace App\Services;

use App\Models\Control;
use App\Models\KeyRiskIndicator;
use App\Models\KriMeasurement;
use App\Models\LossEvent;
use App\Models\Risk;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RegulatoryReportService
{
    /**
     * Generate CBN ORMS Return - Quarterly loss events by Basel category
     */
    public function generateCbnOrmsReturn(int $orgId, string $quarter, int $year): array
    {
        // Map quarter to months
        $quarterMonths = [
            'Q1' => [1, 2, 3],
            'Q2' => [4, 5, 6],
            'Q3' => [7, 8, 9],
            'Q4' => [10, 11, 12],
        ];

        $months = $quarterMonths[strtoupper($quarter)] ?? [1, 2, 3];

        // A plain date range rather than MONTH(date_of_loss). MONTH() is a
        // MySQL builtin that SQLite does not have, so this query threw
        // "no such function: MONTH" anywhere the app ran on SQLite — including
        // the whole test suite, which is why the on-screen regulatory report
        // had no coverage. A BETWEEN over the quarter's own dates is portable
        // and lets the index on date_of_loss be used.
        $quarterStart = \Carbon\CarbonImmutable::create($year, $months[0], 1)->startOfDay();
        $quarterEnd = $quarterStart->addMonths(3)->subDay()->endOfDay();

        $lossEvents = LossEvent::where('organization_id', $orgId)
            ->whereBetween('date_of_loss', [$quarterStart->toDateString(), $quarterEnd->toDateString()])
            ->get();

        // Group by Basel L1 category
        $byBaselL1 = $lossEvents->groupBy('basel_l1_category');

        $categoryData = [];
        $totalEvents = 0;
        $totalLoss = 0;

        foreach ($byBaselL1 as $category => $events) {
            $count = $events->count();
            $loss = $events->sum('gross_loss_amount_kobo');

            // Calculate frequency distribution
            $maxEvent = $events->max('gross_loss_amount_kobo');
            $minEvent = $events->min('gross_loss_amount_kobo');
            $avgEvent = $events->avg('gross_loss_amount_kobo');

            $categoryData[] = [
                'basel_l1_category' => $category ?? 'Unclassified',
                'event_count' => $count,
                'total_loss_kobo' => $loss,
                'avg_loss_kobo' => round($avgEvent, 0),
                'max_loss_kobo' => $maxEvent,
                'min_loss_kobo' => $minEvent,
                'frequency' => $count,
                'severity_avg' => round($avgEvent, 0),
            ];

            $totalEvents += $count;
            $totalLoss += $loss;
        }

        return [
            'report_type' => 'CBN_ORMS_RETURN',
            'organization_id' => $orgId,
            'quarter' => $quarter,
            'year' => $year,
            'reporting_period' => "{$quarter} {$year}",
            'total_events' => $totalEvents,
            'total_loss_kobo' => $totalLoss,
            'categories' => $categoryData,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Generate comprehensive loss event summary
     */
    public function generateLossEventSummary(int $orgId, ?string $startDate = null, ?string $endDate = null): array
    {
        $startDate = $startDate ? Carbon::parse($startDate) : now()->subYear();
        $endDate = $endDate ? Carbon::parse($endDate) : now();

        $lossEvents = LossEvent::where('organization_id', $orgId)
            ->whereBetween('date_of_loss', [$startDate, $endDate])
            ->get();

        $byCategory = $lossEvents->groupBy('basel_l1_category');
        $byCbnCategory = $lossEvents->groupBy('cbn_risk_category');

        // Trend analysis by month
        $monthlyTrend = $lossEvents
            ->groupBy(function ($event) {
                return $event->date_of_loss->format('Y-m');
            })
            ->map(function ($events) {
                return [
                    'count' => $events->count(),
                    'total_loss_kobo' => $events->sum('gross_loss_amount_kobo'),
                    'avg_loss_kobo' => round($events->avg('gross_loss_amount_kobo'), 0),
                ];
            });

        // Severity distribution
        $severityDist = $lossEvents->groupBy('event_severity')->map(fn ($g) => $g->count());

        $categoryData = [];
        foreach ($byCategory as $cat => $events) {
            $categoryData[] = [
                'category' => $cat ?? 'Unclassified',
                'count' => $events->count(),
                'total_loss_kobo' => $events->sum('gross_loss_amount_kobo'),
                'avg_loss_kobo' => round($events->avg('gross_loss_amount_kobo'), 0),
            ];
        }

        $cbnCategoryData = [];
        foreach ($byCbnCategory as $cat => $events) {
            $cbnCategoryData[] = [
                'category' => $cat ?? 'Unclassified',
                'count' => $events->count(),
                'total_loss_kobo' => $events->sum('gross_loss_amount_kobo'),
            ];
        }

        return [
            'report_type' => 'LOSS_EVENT_SUMMARY',
            'organization_id' => $orgId,
            'period' => [
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
            ],
            'summary' => [
                'total_events' => $lossEvents->count(),
                'total_loss_kobo' => $lossEvents->sum('gross_loss_amount_kobo'),
                'avg_loss_kobo' => round($lossEvents->avg('gross_loss_amount_kobo'), 0),
                'max_loss_kobo' => $lossEvents->max('gross_loss_amount_kobo'),
                'min_loss_kobo' => $lossEvents->min('gross_loss_amount_kobo'),
                'recovered_amount_kobo' => $lossEvents->sum('actual_recovery_kobo') ?? 0,
                'net_loss_kobo' => ($lossEvents->sum('gross_loss_amount_kobo') - ($lossEvents->sum('actual_recovery_kobo') ?? 0)),
            ],
            'by_basel_category' => $categoryData,
            'by_cbn_category' => $cbnCategoryData,
            'severity_distribution' => $severityDist->toArray(),
            'monthly_trend' => $monthlyTrend->toArray(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Generate control effectiveness summary
     */
    public function generateControlEffectivenessSummary(int $orgId): array
    {
        $controls = Control::where('organization_id', $orgId)->get();

        $effectivenessRatings = [
            'Effective' => 0,
            'Mostly Effective' => 0,
            'Partially Effective' => 0,
            'Ineffective' => 0,
            'Not Assessed' => 0,
        ];

        $controlsData = [];

        foreach ($controls as $control) {
            $rating = $control->effectiveness_rating ?? 'Not Assessed';
            $effectivenessRatings[$rating] = ($effectivenessRatings[$rating] ?? 0) + 1;

            $controlsData[] = [
                'control_id' => $control->id,
                'control_name' => $control->control_name ?? $control->title,
                'control_type' => $control->control_type,
                'effectiveness_rating' => $rating,
                'test_frequency' => $control->test_frequency,
                'last_tested' => $control->last_tested_date,
                'coverage' => $control->coverage_scope,
            ];
        }

        $totalControls = $controls->count();
        $effectiveCount = ($effectivenessRatings['Effective'] ?? 0) + ($effectivenessRatings['Mostly Effective'] ?? 0);
        $effectivenessRate = $totalControls > 0 ? round(($effectiveCount / $totalControls) * 100, 1) : 0;

        return [
            'report_type' => 'CONTROL_EFFECTIVENESS',
            'organization_id' => $orgId,
            'summary' => [
                'total_controls' => $totalControls,
                'effective_count' => $effectiveCount,
                'effectiveness_rate_pct' => $effectivenessRate,
                'distribution' => $effectivenessRatings,
            ],
            'controls' => $controlsData,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Generate KRI status report
     */
    public function generateKriStatusReport(int $orgId): array
    {
        $kris = KeyRiskIndicator::where('organization_id', $orgId)->get();

        $statusCounts = [
            'green' => 0,
            'yellow' => 0,
            'red' => 0,
            'unknown' => 0,
        ];

        $kriData = [];

        foreach ($kris as $kri) {
            $status = $kri->current_status ?? 'unknown';
            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;

            // Get latest measurement
            $latestMeasurement = KriMeasurement::where('kri_id', $kri->id)
                ->orderByDesc('measurement_date')
                ->first();

            $kriData[] = [
                'kri_id' => $kri->id,
                'kri_name' => $kri->kri_name,
                'risk_category' => $kri->risk_category,
                'current_status' => $status,
                'threshold_green_upper' => $kri->threshold_green_upper,
                'threshold_yellow_upper' => $kri->threshold_yellow_upper,
                'threshold_red_upper' => $kri->threshold_red_upper,
                'current_value' => $latestMeasurement->measured_value ?? null,
                'measurement_date' => $latestMeasurement->measurement_date ?? null,
                'breach_count' => KriMeasurement::where('kri_id', $kri->id)
                    ->where('status', 'red')
                    ->count(),
            ];
        }

        $breachCount = $statusCounts['red'] ?? 0;
        $totalKris = $kris->count();
        $breachPct = $totalKris > 0 ? round(($breachCount / $totalKris) * 100, 1) : 0;

        return [
            'report_type' => 'KRI_STATUS',
            'organization_id' => $orgId,
            'summary' => [
                'total_kris' => $totalKris,
                'kris_within_limits' => ($statusCounts['green'] ?? 0),
                'kris_approaching_limit' => ($statusCounts['yellow'] ?? 0),
                'kris_breached' => $breachCount,
                'breach_rate_pct' => $breachPct,
                'status_distribution' => $statusCounts,
            ],
            'kris' => $kriData,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Generate risk appetite compliance report
     */
    public function generateRiskAppetiteComplianceReport(int $orgId): array
    {
        $appetiteService = new RiskAppetiteService;
        $appetiteData = $appetiteService->getDashboardData($orgId);

        $breaches = $appetiteService->getBreaches($orgId);

        return [
            'report_type' => 'RISK_APPETITE_COMPLIANCE',
            'organization_id' => $orgId,
            'summary' => [
                'total_categories' => $appetiteData['total_categories'],
                'within_appetite' => $appetiteData['within_appetite'],
                'approaching_tolerance' => $appetiteData['approaching'],
                'exceeds_tolerance' => $appetiteData['exceeds_tolerance'],
                'exceeds_capacity' => $appetiteData['exceeds_capacity'],
                'compliance_rate_pct' => round(
                    ($appetiteData['within_appetite'] / max($appetiteData['total_categories'], 1)) * 100,
                    1
                ),
                'breach_count' => count($breaches),
            ],
            'categories' => $appetiteData['categories'],
            'breaches' => $breaches,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Generate ICAAP summary from quantification data
     */
    public function generateIcaapSummary(int $orgId): array
    {
        $simulationRuns = DB::table('simulation_runs')
            ->where('organization_id', $orgId)
            ->where('status', 'completed')
            ->orderByDesc('completed_at')
            ->get();

        $latestSimulation = $simulationRuns->first();

        if (! $latestSimulation) {
            return [
                'report_type' => 'ICAAP_SUMMARY',
                'organization_id' => $orgId,
                'status' => 'no_data',
                'message' => 'No completed simulations available for ICAAP analysis',
                'generated_at' => now()->toIso8601String(),
            ];
        }

        $simulationResults = DB::table('simulation_results')
            ->where('simulation_run_id', $latestSimulation->id)
            ->get();

        $aggregateResult = $simulationResults->firstWhere('result_type', 'aggregate');

        $totalExpectedLoss = $aggregateResult->expected_annual_loss_kobo ?? 0;
        $var95 = $aggregateResult->var_95_kobo ?? 0;
        $var99 = $aggregateResult->var_99_kobo ?? 0;
        // `var_999_kobo` is not a column. The column on simulation_results is
        // `var_99_9_kobo` (see 2026_02_22_200032_create_simulation_results_table).
        // The misspelling meant this read resolved to null, `?? 0` turned it
        // into zero, and every ICAAP summary regulatory report has been
        // reporting a 99.9% VaR of zero and — via the line below — a capital
        // requirement of exactly ₦0, silently, for every tenant.
        $var999 = $aggregateResult->var_99_9_kobo ?? 0;
        $expectedShortfall99 = $aggregateResult->es_99_kobo;

        // The economic capital requirement for operational risk under an
        // AMA-style approach IS the 99.9% VaR (over a one-year horizon), so
        // that is what is reported here.
        //
        // The previous line was `$capitalRequirement = $var999 * 12.5` under a
        // comment reading "Standard approach: Capital = 12.5 * VaR(99.9%)".
        // That inverts Basel. 12.5 is the reciprocal of the 8% minimum capital
        // ratio, and it converts a capital CHARGE into a RISK-WEIGHTED ASSET
        // equivalent — RWA = 12.5 x capital, not capital = 12.5 x VaR. The
        // figure was therefore twelve and a half times the capital requirement
        // and labelled as the capital requirement. CBN's Guidelines on
        // Regulatory Capital (September 2021) apply the same 12.5 multiplier to
        // derive operational RWA from the Pillar 1 charge.
        //
        // Both quantities are now reported, each under its own name, so the
        // RWA-equivalent can be added to the denominator of a CAR calculation
        // without anyone mistaking it for capital.
        $capitalRequirement = $var999;
        $rwaEquivalent = $var999 * 12.5;

        // Risk contributions
        $riskContributions = json_decode($aggregateResult->risk_contributions, true) ?? [];

        return [
            'report_type' => 'ICAAP_SUMMARY',
            'organization_id' => $orgId,
            'simulation_run_id' => $latestSimulation->id,
            'simulation_date' => $latestSimulation->completed_at,
            'loss_metrics' => [
                'expected_annual_loss_kobo' => $totalExpectedLoss,
                'var_95_kobo' => $var95,
                'var_99_kobo' => $var99,
                'var_99_9_kobo' => $var999,
                // The tail measure is now the computed mean of the losses beyond
                // VaR(99), persisted by MonteCarloService, rather than the
                // previous `($var999 - $var99) / 2` — which was described in a
                // comment as a "simplified tail estimate" but is the midpoint of
                // two quantiles and is not an estimate of anything. It is null,
                // not zero, for runs completed before expected shortfall existed.
                'expected_shortfall_99_kobo' => $expectedShortfall99,
            ],
            'capital_requirements' => [
                'calculated_var_99_9_kobo' => $var999,
                'capital_requirement_kobo' => round($capitalRequirement),
                'rwa_equivalent_kobo' => round($rwaEquivalent),
                'rwa_multiplier' => 12.5,
                'methodology' => 'Loss distribution approach, 99.9% one-year VaR',
                'confidence_level' => '99.9%',
            ],
            'risk_contributions' => $riskContributions,
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
