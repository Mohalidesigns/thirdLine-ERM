<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\IcaapAssessment;
use App\Models\QuantificationScenario;
use App\Models\QuantificationSetting;
use App\Models\Risk;
use App\Models\RiskCategory;
use App\Models\SimulationRun;
use App\Services\MonteCarloService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;

class QuantificationController extends Controller
{
    /**
     * Quantification dashboard.
     */
    public function dashboard()
    {
        $orgId = TenantContext::organizationId();

        $activeScenarios = QuantificationScenario::where('organization_id', $orgId)
            ->where('status', 'active')->count();
        $simulationsRun = SimulationRun::where('organization_id', $orgId)
            ->where('status', 'completed')->count();

        // Latest completed simulation for VaR / ES
        $latestSim = SimulationRun::where('organization_id', $orgId)
            ->where('status', 'completed')
            ->orderByDesc('completed_at')
            ->first();

        $var95 = $latestSim ? $latestSim->var_95 : 0;
        $expectedShortfall = $latestSim ? $latestSim->expected_shortfall : 0;

        // Latest ICAAP
        $latestIcaap = IcaapAssessment::where('organization_id', $orgId)
            ->orderByDesc('created_at')->first();

        $capitalAdequacyRatio = $latestIcaap ? (float) $latestIcaap->car_actual : 0;
        $tier1 = $latestIcaap ? round(($latestIcaap->tier1_capital_kobo ?? 0) / 100, 2) : 0;
        $tier2 = $latestIcaap ? round(($latestIcaap->tier2_capital_kobo ?? 0) / 100, 2) : 0;
        $totalCapital = $latestIcaap ? round(($latestIcaap->total_qualifying_capital_kobo ?? 0) / 100, 2) : 0;

        $pillar1Capital = $latestIcaap
            ? round((($latestIcaap->pillar2a_credit_kobo ?? 0) + ($latestIcaap->pillar2a_market_kobo ?? 0) + ($latestIcaap->pillar2a_operational_kobo ?? 0)) / 100, 2)
            : 0;
        $pillar2Capital = $latestIcaap
            ? round((($latestIcaap->pillar2a_other_kobo ?? 0) + ($latestIcaap->pillar2b_stress_buffer_kobo ?? 0)) / 100, 2)
            : 0;
        $capitalBuffer = $totalCapital > 0 ? round($totalCapital - $pillar1Capital - $pillar2Capital, 2) : 0;

        $totalEconomicCapital = $pillar1Capital + $pillar2Capital;

        // Capital by risk type chart data
        $capitalByTypeData = [
            'labels' => ['Credit Risk', 'Market Risk', 'Operational Risk', 'Liquidity Risk', 'Other'],
            'values' => [
                $latestIcaap ? round(($latestIcaap->pillar2a_credit_kobo ?? 0) / 100, 2) : 0,
                $latestIcaap ? round(($latestIcaap->pillar2a_market_kobo ?? 0) / 100, 2) : 0,
                $latestIcaap ? round(($latestIcaap->pillar2a_operational_kobo ?? 0) / 100, 2) : 0,
                $latestIcaap ? round(($latestIcaap->pillar2b_stress_buffer_kobo ?? 0) / 100, 2) : 0,
                $latestIcaap ? round(($latestIcaap->pillar2a_other_kobo ?? 0) / 100, 2) : 0,
            ],
        ];

        // Loss distribution chart data from latest simulation
        $lossDistData = ['labels' => [], 'values' => []];
        if ($latestSim) {
            $agg = $latestSim->aggregate_result;
            if ($agg && is_array($agg->percentile_distribution)) {
                $labels = ['p5' => '5%', 'p10' => '10%', 'p25' => '25%', 'p50' => '50%', 'p75' => '75%', 'p90' => '90%', 'p95' => '95%', 'p99' => '99%'];
                foreach ($labels as $key => $label) {
                    if (isset($agg->percentile_distribution[$key])) {
                        $lossDistData['labels'][] = $label;
                        $lossDistData['values'][] = round($agg->percentile_distribution[$key] / 100, 2);
                    }
                }
            }
        }

        $recentSimulations = SimulationRun::where('organization_id', $orgId)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        return view('risk.quantification.dashboard', compact(
            'totalEconomicCapital', 'capitalAdequacyRatio', 'activeScenarios', 'simulationsRun',
            'var95', 'expectedShortfall', 'capitalByTypeData', 'lossDistData',
            'pillar1Capital', 'pillar2Capital', 'capitalBuffer', 'recentSimulations'
        ));
    }

    /**
     * List all scenarios.
     */
    public function scenarios(Request $request)
    {
        $orgId = TenantContext::organizationId();

        $query = QuantificationScenario::where('organization_id', $orgId);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('risk_category')) {
            $query->where('cbn_risk_category', $request->risk_category);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('scenario_reference', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        $scenarios = $query->orderByDesc('created_at')->paginate(25)->withQueryString();

        $categories = RiskCategory::where('organization_id', $orgId)->orderBy('name')->get();

        return view('risk.quantification.scenarios', compact('scenarios', 'categories'));
    }

    /**
     * Show form for creating a new scenario.
     */
    public function createScenario()
    {
        $orgId = TenantContext::organizationId();

        $risks = Risk::where('organization_id', $orgId)->where('status', 'active')->orderBy('risk_code')->get();
        $categories = RiskCategory::where('organization_id', $orgId)->orderBy('name')->get();

        return view('risk.quantification.create-scenario', compact('risks', 'categories'));
    }

    /**
     * Store a new scenario.
     * Maps blade form field names to actual DB column names.
     */
    public function storeScenario(Request $request)
    {
        $orgId = TenantContext::organizationId();

        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string|max:5000',
            'risk_category' => 'required|string|max:100',
            'linked_risk_id' => 'nullable|exists:risks,id',
            'distribution_type' => 'required|in:lognormal,normal,poisson,pareto,weibull,beta',
            'frequency_per_year' => 'required|numeric|min:0',
            'mean' => 'required|numeric|min:0',
            'std_dev' => 'nullable|numeric|min:0',
            'min_loss' => 'nullable|numeric|min:0',
            'max_loss' => 'nullable|numeric|min:0',
        ]);

        // Auto-generate scenario reference: SCN-YYYY-NNN
        $year = now()->year;
        $lastScenario = QuantificationScenario::where('organization_id', $orgId)
            ->where('scenario_reference', 'like', "SCN-{$year}-%")
            ->orderByDesc('scenario_reference')
            ->first();

        $nextNumber = $lastScenario ? ((int) substr($lastScenario->scenario_reference, -3)) + 1 : 1;
        $scenarioReference = sprintf('SCN-%d-%03d', $year, $nextNumber);

        // Convert form values (Naira) → DB values (kobo + lognormal params)
        $meanNaira = (float) $request->mean;
        $stdDevNaira = (float) ($request->std_dev ?? 0);
        $meanKobo = round($meanNaira * 100);
        $minKobo = $request->min_loss ? round((float) $request->min_loss * 100) : null;
        $maxKobo = $request->max_loss ? round((float) $request->max_loss * 100) : null;
        $freqYear = (float) $request->frequency_per_year;

        // Compute lognormal mu and sigma from mean and std_dev
        $mu = $meanKobo > 0 ? log($meanKobo) : 0;
        $sigma = ($stdDevNaira > 0 && $meanNaira > 0) ? sqrt(log(1 + ($stdDevNaira / $meanNaira) ** 2)) : 1.0;

        $scenario = QuantificationScenario::create([
            'organization_id' => $orgId,
            'scenario_reference' => $scenarioReference,
            'name' => $request->name,
            'description' => $request->description,
            'cbn_risk_category' => $request->risk_category,
            'risk_register_id' => $request->linked_risk_id,
            'severity_distribution' => $request->distribution_type,
            'frequency_distribution' => 'poisson',
            'frequency_lambda' => $freqYear,
            'expected_annual_frequency' => $freqYear,
            'severity_mu' => round($mu, 6),
            'severity_sigma' => round($sigma, 6),
            'expected_loss_per_event_kobo' => $meanKobo,
            'expected_annual_loss_kobo' => round($meanKobo * $freqYear),
            'severity_min_kobo' => $minKobo,
            'severity_max_kobo' => $maxKobo,
            'status' => 'active',
            'created_by' => auth()->id(),
        ]);

        return redirect()->route('risk.quantification.show-scenario', $scenario)
            ->with('success', "Scenario {$scenarioReference} has been created.");
    }

    /**
     * Display a scenario.
     */
    public function showScenario(QuantificationScenario $scenario)
    {
        $orgId = TenantContext::organizationId();

        if ($scenario->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this scenario.');
        }

        $scenario->load(['riskRegister']);

        // Get simulation runs for this scenario
        $simulations = SimulationRun::where('organization_id', $orgId)
            ->whereJsonContains('scenario_ids', $scenario->id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        // Build distribution visualization data
        $distributionVisualization = $this->buildDistributionVisualization($scenario);

        return view('risk.quantification.show-scenario', compact('scenario', 'simulations', 'distributionVisualization'));
    }

    /**
     * Update a scenario.
     */
    public function updateScenario(Request $request, QuantificationScenario $scenario)
    {
        $orgId = TenantContext::organizationId();

        if ($scenario->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this scenario.');
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string|max:5000',
            'risk_category' => 'required|string|max:100',
            'linked_risk_id' => 'nullable|exists:risks,id',
            'distribution_type' => 'required|in:lognormal,normal,poisson,pareto,weibull,beta',
            'frequency_per_year' => 'required|numeric|min:0',
            'mean' => 'required|numeric|min:0',
            'std_dev' => 'nullable|numeric|min:0',
            'min_loss' => 'nullable|numeric|min:0',
            'max_loss' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:draft,active,archived',
        ]);

        $meanNaira = (float) $request->mean;
        $stdDevNaira = (float) ($request->std_dev ?? 0);
        $meanKobo = round($meanNaira * 100);
        $minKobo = $request->min_loss ? round((float) $request->min_loss * 100) : null;
        $maxKobo = $request->max_loss ? round((float) $request->max_loss * 100) : null;
        $freqYear = (float) $request->frequency_per_year;

        $mu = $meanKobo > 0 ? log($meanKobo) : 0;
        $sigma = ($stdDevNaira > 0 && $meanNaira > 0) ? sqrt(log(1 + ($stdDevNaira / $meanNaira) ** 2)) : 1.0;

        $scenario->update([
            'name' => $request->name,
            'description' => $request->description,
            'cbn_risk_category' => $request->risk_category,
            'risk_register_id' => $request->linked_risk_id,
            'severity_distribution' => $request->distribution_type,
            'frequency_lambda' => $freqYear,
            'expected_annual_frequency' => $freqYear,
            'severity_mu' => round($mu, 6),
            'severity_sigma' => round($sigma, 6),
            'expected_loss_per_event_kobo' => $meanKobo,
            'expected_annual_loss_kobo' => round($meanKobo * $freqYear),
            'severity_min_kobo' => $minKobo,
            'severity_max_kobo' => $maxKobo,
            'status' => $request->status ?? $scenario->status,
        ]);

        return redirect()->route('risk.quantification.show-scenario', $scenario)
            ->with('success', "Scenario {$scenario->scenario_reference} has been updated.");
    }

    /**
     * Show simulation setup form.
     */
    public function simulate()
    {
        $orgId = TenantContext::organizationId();

        $scenarios = QuantificationScenario::where('organization_id', $orgId)
            ->where('status', 'active')
            ->orderBy('scenario_reference')
            ->get();

        return view('risk.quantification.simulate', compact('scenarios'));
    }

    /**
     * Launch a simulation run.
     */
    public function runSimulation(Request $request)
    {
        $orgId = TenantContext::organizationId();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'scenario_ids' => 'required|array|min:1',
            'scenario_ids.*' => 'exists:quantification_scenarios,id',
            'iterations' => 'required|integer|min:1000|max:1000000',
            'time_horizon' => 'nullable|integer|min:1|max:10',
            'confidence_levels' => 'nullable|array',
            'confidence_levels.*' => 'numeric',
        ]);

        // Verify all scenarios belong to org
        $scenarioCount = QuantificationScenario::where('organization_id', $orgId)
            ->whereIn('id', $validated['scenario_ids'])
            ->count();

        if ($scenarioCount !== count($validated['scenario_ids'])) {
            return back()->with('error', 'One or more selected scenarios are invalid.');
        }

        // Auto-generate simulation reference: SIM-YYYY-NNN
        $year = now()->year;
        $lastSim = SimulationRun::where('organization_id', $orgId)
            ->where('simulation_reference', 'like', "SIM-{$year}-%")
            ->orderByDesc('simulation_reference')
            ->first();

        $nextNumber = $lastSim ? ((int) substr($lastSim->simulation_reference, -3)) + 1 : 1;
        $simReference = sprintf('SIM-%d-%03d', $year, $nextNumber);

        $simulation = SimulationRun::create([
            'organization_id' => $orgId,
            'simulation_reference' => $simReference,
            'scenario_ids' => $validated['scenario_ids'],
            'iterations' => $validated['iterations'],
            'horizon_years' => $validated['time_horizon'] ?? 1,
            'confidence_levels' => $validated['confidence_levels'] ?? [95, 99, 99.5],
            'correlation_method' => 'independent',
            'status' => 'queued',
            'initiated_by' => auth()->id(),
        ]);

        // Run Monte Carlo simulation
        try {
            $monteCarloService = new MonteCarloService;
            $simulation = $monteCarloService->runSimulation($simulation, $validated['scenario_ids']);

            return redirect()->route('risk.quantification.show-results', $simulation)
                ->with('success', "Simulation {$simReference} has been completed successfully.");
        } catch (\Exception $e) {
            $simulation->update(['status' => 'failed', 'error_message' => $e->getMessage()]);

            return back()->with('error', 'Simulation failed: '.$e->getMessage());
        }
    }

    /**
     * View all simulation results.
     * View expects $results (collection of SimulationRun models).
     */
    public function results(Request $request)
    {
        $orgId = TenantContext::organizationId();

        $query = SimulationRun::where('organization_id', $orgId);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $results = $query->orderByDesc('created_at')->paginate(25)->withQueryString();

        return view('risk.quantification.results', compact('results'));
    }

    /**
     * Display results for a specific simulation run.
     * View expects $result (SimulationRun), plus chart data arrays.
     */
    public function showResults(SimulationRun $simulation)
    {
        $orgId = TenantContext::organizationId();

        if ($simulation->organization_id !== $orgId) {
            abort(403, 'Unauthorized access to this simulation.');
        }

        $result = $simulation;

        // Build histogram data from percentile distribution
        $histogramData = ['labels' => [], 'values' => []];
        $agg = $result->aggregate_result;
        if ($agg && is_array($agg->percentile_distribution)) {
            $dist = $agg->percentile_distribution;
            $labels = ['p5' => '5%', 'p10' => '10%', 'p25' => '25%', 'p50' => '50%', 'p75' => '75%', 'p90' => '90%', 'p95' => '95%', 'p99' => '99%'];
            foreach ($labels as $key => $label) {
                if (isset($dist[$key])) {
                    $histogramData['labels'][] = $label;
                    $histogramData['values'][] = round($dist[$key] / 100, 2);
                }
            }
        }

        // Build contribution chart data
        $contribChartData = ['labels' => [], 'values' => []];
        $contributions = $result->scenario_contributions;
        if ($contributions && $contributions->count()) {
            foreach ($contributions as $c) {
                $contribChartData['labels'][] = $c->scenario_name;
                $contribChartData['values'][] = $c->contribution_pct;
            }
        }

        // Build CDF data
        $cdfData = ['labels' => [], 'values' => []];
        if ($agg && is_array($agg->percentile_distribution)) {
            $pMap = ['p5' => 0.05, 'p10' => 0.10, 'p25' => 0.25, 'p50' => 0.50, 'p75' => 0.75, 'p90' => 0.90, 'p95' => 0.95, 'p99' => 0.99, 'p99.5' => 0.995, 'p99.9' => 0.999];
            foreach ($pMap as $key => $prob) {
                if (isset($agg->percentile_distribution[$key])) {
                    $cdfData['labels'][] = '₦'.number_format(round($agg->percentile_distribution[$key] / 100, 2));
                    $cdfData['values'][] = $prob;
                }
            }
        }

        return view('risk.quantification.show-results', compact('result', 'histogramData', 'contribChartData', 'cdfData'));
    }

    /**
     * ICAAP assessment view.
     */
    public function icaap()
    {
        $orgId = TenantContext::organizationId();

        $latestIcaap = IcaapAssessment::where('organization_id', $orgId)
            ->orderByDesc('created_at')
            ->first();

        // Capital position
        $totalCapital = $latestIcaap ? round(($latestIcaap->total_qualifying_capital_kobo ?? 0) / 100, 2) : 0;
        $capitalAdequacyRatio = $latestIcaap ? (float) ($latestIcaap->car_actual ?? 0) : 0;
        $tier1Capital = $latestIcaap ? round(($latestIcaap->tier1_capital_kobo ?? 0) / 100, 2) : 0;
        $tier2Capital = $latestIcaap ? round(($latestIcaap->tier2_capital_kobo ?? 0) / 100, 2) : 0;

        // Pillar 1
        $pillar1Credit = $latestIcaap ? round(($latestIcaap->pillar2a_credit_kobo ?? 0) / 100, 2) : 0;
        $pillar1Market = $latestIcaap ? round(($latestIcaap->pillar2a_market_kobo ?? 0) / 100, 2) : 0;
        $pillar1Operational = $latestIcaap ? round(($latestIcaap->pillar2a_operational_kobo ?? 0) / 100, 2) : 0;
        $totalPillar1 = $pillar1Credit + $pillar1Market + $pillar1Operational;

        // Pillar 2
        $pillar2Concentration = $latestIcaap ? round(($latestIcaap->pillar2a_other_kobo ?? 0) / 100 * 0.3, 2) : 0;
        $pillar2InterestRate = $latestIcaap ? round(($latestIcaap->pillar2a_other_kobo ?? 0) / 100 * 0.25, 2) : 0;
        $pillar2Liquidity = $latestIcaap ? round(($latestIcaap->pillar2b_stress_buffer_kobo ?? 0) / 100 * 0.5, 2) : 0;
        $pillar2Reputational = $latestIcaap ? round(($latestIcaap->pillar2a_other_kobo ?? 0) / 100 * 0.25, 2) : 0;
        $pillar2Strategic = $latestIcaap ? round(($latestIcaap->pillar2a_other_kobo ?? 0) / 100 * 0.2, 2) : 0;
        $totalPillar2 = $pillar2Concentration + $pillar2InterestRate + $pillar2Liquidity + $pillar2Reputational + $pillar2Strategic;

        // Stress test results (from stress simulation if available)
        $stressResults = [];
        if ($latestIcaap && $latestIcaap->stress_simulation_id) {
            $stressSim = SimulationRun::find($latestIcaap->stress_simulation_id);
            if ($stressSim) {
                $stressResults = collect([
                    (object) [
                        'scenario' => 'Severe Recession',
                        'capital_impact' => round($stressSim->var_99 * 0.6, 2),
                        'car_after' => max($capitalAdequacyRatio - 3.5, 0),
                        'shortfall' => max(0, round((10 - ($capitalAdequacyRatio - 3.5)) * $totalCapital / 100, 2)),
                    ],
                    (object) [
                        'scenario' => 'Oil Price Shock',
                        'capital_impact' => round($stressSim->var_95 * 0.4, 2),
                        'car_after' => max($capitalAdequacyRatio - 2.1, 0),
                        'shortfall' => 0,
                    ],
                    (object) [
                        'scenario' => 'Cyber Attack + Market Crash',
                        'capital_impact' => round($stressSim->var_995, 2),
                        'car_after' => max($capitalAdequacyRatio - 5.2, 0),
                        'shortfall' => max(0, round((10 - ($capitalAdequacyRatio - 5.2)) * $totalCapital / 100, 2)),
                    ],
                ]);
            }
        }

        // If no stress sim, provide default scenarios based on available data
        if (empty($stressResults) || (is_countable($stressResults) && count($stressResults) === 0)) {
            $stressResults = collect([
                (object) ['scenario' => 'Severe Recession', 'capital_impact' => round($totalCapital * 0.08, 2), 'car_after' => max($capitalAdequacyRatio - 3.5, 0), 'shortfall' => 0],
                (object) ['scenario' => 'Oil Price Shock', 'capital_impact' => round($totalCapital * 0.05, 2), 'car_after' => max($capitalAdequacyRatio - 2.1, 0), 'shortfall' => 0],
                (object) ['scenario' => 'Cyber Attack + Market Crash', 'capital_impact' => round($totalCapital * 0.12, 2), 'car_after' => max($capitalAdequacyRatio - 5.2, 0), 'shortfall' => 0],
            ]);
        }

        // Waterfall chart
        $waterfallData = [
            'labels' => ['Total Capital', 'Pillar 1', 'Pillar 2', 'Buffer', 'Available Capital'],
            'values' => [
                $totalCapital,
                -$totalPillar1,
                -$totalPillar2,
                -round($totalCapital * ((float) ($latestIcaap->conservation_buffer ?? 2.5) / 100), 2),
                max(0, round($totalCapital - $totalPillar1 - $totalPillar2 - ($totalCapital * ((float) ($latestIcaap->conservation_buffer ?? 2.5) / 100)), 2)),
            ],
        ];

        return view('risk.quantification.icaap', compact(
            'totalCapital', 'capitalAdequacyRatio', 'tier1Capital', 'tier2Capital',
            'pillar1Credit', 'pillar1Market', 'pillar1Operational', 'totalPillar1',
            'pillar2Concentration', 'pillar2InterestRate', 'pillar2Liquidity',
            'pillar2Reputational', 'pillar2Strategic', 'totalPillar2',
            'stressResults', 'waterfallData'
        ));
    }

    /**
     * Pre-built scenario library.
     * View expects $libraryScenarios (collection of objects with name, risk_category, etc.)
     */
    public function library()
    {
        $libraryScenarios = $this->libraryScenarios();

        return view('risk.quantification.library', compact('libraryScenarios'));
    }

    /**
     * Pre-built scenario library definitions (industry-benchmark demo data).
     */
    private function libraryScenarios(): \Illuminate\Support\Collection
    {
        return collect([
            (object) ['id' => 'lib-1', 'name' => 'Internal Fraud - Unauthorized Trading', 'risk_category' => 'Operational Risk', 'distribution_type' => 'lognormal', 'description' => 'Losses from unauthorized transactions, mismarking, or rogue trading activities in Nigerian banking sector', 'mean' => 850000000, 'std_dev' => 425000000, 'frequency_per_year' => 1.5, 'source' => 'CBN ORMS Data'],
            (object) ['id' => 'lib-2', 'name' => 'External Fraud - Cyber Attack', 'risk_category' => 'Operational Risk', 'distribution_type' => 'pareto', 'description' => 'Losses from cyber intrusion, phishing, BEC, or electronic fraud targeting bank systems', 'mean' => 1200000000, 'std_dev' => 800000000, 'frequency_per_year' => 3.2, 'source' => 'CBN ORMS Data'],
            (object) ['id' => 'lib-3', 'name' => 'IT System Failure', 'risk_category' => 'Operational Risk', 'distribution_type' => 'lognormal', 'description' => 'Losses from core banking system outages, data center failures, or IT infrastructure disruptions', 'mean' => 500000000, 'std_dev' => 250000000, 'frequency_per_year' => 2.0, 'source' => 'Industry Benchmark'],
            (object) ['id' => 'lib-4', 'name' => 'Regulatory Fine - CBN Penalty', 'risk_category' => 'Operational Risk', 'distribution_type' => 'lognormal', 'description' => 'Monetary penalties from CBN for regulatory breaches, non-compliance, or AML/KYC failures', 'mean' => 2000000000, 'std_dev' => 1500000000, 'frequency_per_year' => 0.8, 'source' => 'CBN Published Sanctions'],
            (object) ['id' => 'lib-5', 'name' => 'Credit Default - Corporate Portfolio', 'risk_category' => 'Credit Risk', 'distribution_type' => 'lognormal', 'description' => 'Losses from corporate loan defaults, particularly in oil & gas, manufacturing, and real estate sectors', 'mean' => 5000000000, 'std_dev' => 3000000000, 'frequency_per_year' => 4.5, 'source' => 'CBN Credit Bureau'],
            (object) ['id' => 'lib-6', 'name' => 'FX Volatility Shock', 'risk_category' => 'Market Risk', 'distribution_type' => 'normal', 'description' => 'Losses from sudden Naira devaluation or FX market volatility affecting open positions', 'mean' => 3500000000, 'std_dev' => 2000000000, 'frequency_per_year' => 1.0, 'source' => 'CBN Market Data'],
            (object) ['id' => 'lib-7', 'name' => 'Natural Disaster - Flooding', 'risk_category' => 'Operational Risk', 'distribution_type' => 'weibull', 'description' => 'Physical damage to branches and data centers from flooding events in Lagos, Port Harcourt, and other coastal cities', 'mean' => 300000000, 'std_dev' => 200000000, 'frequency_per_year' => 0.5, 'source' => 'NEMA Data'],
            (object) ['id' => 'lib-8', 'name' => 'Key Person Risk', 'risk_category' => 'Operational Risk', 'distribution_type' => 'lognormal', 'description' => 'Losses from departure or unavailability of critical staff including treasury, IT, and compliance personnel', 'mean' => 200000000, 'std_dev' => 100000000, 'frequency_per_year' => 2.5, 'source' => 'Internal HR Data'],
            (object) ['id' => 'lib-9', 'name' => 'Third-Party Vendor Failure', 'risk_category' => 'Operational Risk', 'distribution_type' => 'lognormal', 'description' => 'Losses from critical vendor failures including payment processors, cloud providers, and network providers', 'mean' => 600000000, 'std_dev' => 400000000, 'frequency_per_year' => 1.8, 'source' => 'Industry Benchmark'],
            (object) ['id' => 'lib-10', 'name' => 'Liquidity Stress - Deposit Run', 'risk_category' => 'Liquidity Risk', 'distribution_type' => 'lognormal', 'description' => 'Losses from a bank run scenario triggered by social media rumors or macroeconomic instability', 'mean' => 10000000000, 'std_dev' => 7000000000, 'frequency_per_year' => 0.2, 'source' => 'CBN Stress Test Framework'],
        ]);
    }

    /**
     * Quantification settings.
     * View expects $settings as an object (accessed with ->).
     */
    public function settings()
    {
        $orgId = TenantContext::organizationId();

        $dbSettings = QuantificationSetting::where('organization_id', $orgId)->first();

        $settings = (object) [
            'default_iterations' => $dbSettings->default_iterations ?? 10000,
            'default_confidence' => 99.5,
            'default_time_horizon' => 1,
            'seed' => null,
            'cbn_min_car' => $dbSettings ? (float) $dbSettings->cbn_minimum_car : 10.0,
            'target_car' => 15.0,
            'conservation_buffer' => $dbSettings ? (float) $dbSettings->cbn_conservation_buffer : 2.5,
            'countercyclical_buffer' => 0,
            'alert_green' => 15,
            'alert_amber' => 12,
            'alert_red' => 10,
        ];

        return view('risk.quantification.settings', compact('settings'));
    }

    /**
     * Update quantification settings.
     */
    public function updateSettings(Request $request)
    {
        $orgId = TenantContext::organizationId();

        $request->validate([
            'default_iterations' => 'required|integer|min:1000|max:1000000',
            'default_confidence' => 'required|numeric|min:90|max:99.99',
            'default_time_horizon' => 'required|integer|min:1|max:10',
            'cbn_min_car' => 'required|numeric|min:0',
            'target_car' => 'required|numeric|min:0',
            'conservation_buffer' => 'required|numeric|min:0',
        ]);

        QuantificationSetting::updateOrCreate(
            ['organization_id' => $orgId],
            [
                'default_iterations' => $request->default_iterations,
                'cbn_minimum_car' => $request->cbn_min_car,
                'cbn_conservation_buffer' => $request->conservation_buffer,
            ]
        );

        return redirect()->route('risk.quantification.settings')
            ->with('success', 'Quantification settings have been updated.');
    }

    /**
     * Quantification reports.
     */
    public function reports()
    {
        $orgId = TenantContext::organizationId();

        $completedSimulations = SimulationRun::where('organization_id', $orgId)
            ->where('status', 'completed')
            ->orderByDesc('completed_at')
            ->paginate(25);

        return view('risk.quantification.reports', compact('completedSimulations'));
    }

    /**
     * Capital Adequacy Summary — condensed view of the latest ICAAP: CAR,
     * tier breakdown, Pillar 1/2 demand, buffer headroom.
     */
    public function capitalAdequacyReport()
    {
        $orgId = TenantContext::organizationId();

        $icaap = IcaapAssessment::where('organization_id', $orgId)
            ->orderByDesc('created_at')->first();

        $kobo = fn ($v) => round(((float) ($v ?? 0)) / 100, 2);

        $data = (object) [
            'as_of' => $icaap?->created_at,
            'total_capital' => $icaap ? $kobo($icaap->total_qualifying_capital_kobo) : 0,
            'tier1' => $icaap ? $kobo($icaap->tier1_capital_kobo) : 0,
            'tier2' => $icaap ? $kobo($icaap->tier2_capital_kobo) : 0,
            'car_actual' => $icaap ? (float) ($icaap->car_actual ?? 0) : 0,
            'car_required' => $icaap ? (float) ($icaap->car_required ?? 10) : 10,
            'conservation_buffer' => $icaap ? (float) ($icaap->conservation_buffer ?? 2.5) : 2.5,
            'pillar1_credit' => $icaap ? $kobo($icaap->pillar2a_credit_kobo) : 0,
            'pillar1_market' => $icaap ? $kobo($icaap->pillar2a_market_kobo) : 0,
            'pillar1_operational' => $icaap ? $kobo($icaap->pillar2a_operational_kobo) : 0,
            'pillar2_buffer' => $icaap ? $kobo($icaap->pillar2b_stress_buffer_kobo) : 0,
            'pillar2_other' => $icaap ? $kobo($icaap->pillar2a_other_kobo) : 0,
        ];
        $data->total_pillar1 = $data->pillar1_credit + $data->pillar1_market + $data->pillar1_operational;
        $data->total_pillar2 = $data->pillar2_buffer + $data->pillar2_other;
        $data->headroom = max(0, $data->total_capital - $data->total_pillar1 - $data->total_pillar2);
        $data->car_surplus = round($data->car_actual - $data->car_required, 2);

        return view('risk.quantification.reports.capital-adequacy', [
            'd' => $data, 'hasData' => (bool) $icaap,
        ]);
    }

    /**
     * Stress Testing Report — scenario impacts drawn from the latest
     * ICAAP's stress simulation, with fallbacks when none is attached.
     */
    public function stressTestingReport()
    {
        $orgId = TenantContext::organizationId();

        $icaap = IcaapAssessment::where('organization_id', $orgId)
            ->orderByDesc('created_at')->first();

        $totalCapital = $icaap ? round(((float) ($icaap->total_qualifying_capital_kobo ?? 0)) / 100, 2) : 0;
        $car = $icaap ? (float) ($icaap->car_actual ?? 0) : 0;

        $stressSim = null;
        if ($icaap && $icaap->stress_simulation_id) {
            $stressSim = SimulationRun::find($icaap->stress_simulation_id);
        }
        // Fall back to the latest completed simulation if no stress run is bound.
        $stressSim = $stressSim ?? SimulationRun::where('organization_id', $orgId)
            ->where('status', 'completed')->orderByDesc('completed_at')->first();

        $baseImpact = $stressSim?->var_99 ?? round($totalCapital * 0.1, 2);

        $scenarios = collect([
            ['name' => 'Severe Recession',            'car_drop' => 3.5, 'factor' => 0.6],
            ['name' => 'Oil Price Shock',             'car_drop' => 2.1, 'factor' => 0.4],
            ['name' => 'Naira Devaluation',           'car_drop' => 2.8, 'factor' => 0.5],
            ['name' => 'Cyber Attack + Market Crash', 'car_drop' => 5.2, 'factor' => 1.0],
            ['name' => 'Liquidity Squeeze',           'car_drop' => 1.6, 'factor' => 0.3],
        ])->map(function ($s) use ($baseImpact, $car, $totalCapital) {
            $impact = round($baseImpact * $s['factor'], 2);
            $carAfter = max($car - $s['car_drop'], 0);
            $shortfall = max(0, round((10 - $carAfter) * $totalCapital / 100, 2));

            return (object) [
                'scenario' => $s['name'],
                'capital_impact' => $impact,
                'car_before' => $car,
                'car_after' => round($carAfter, 2),
                'shortfall' => $shortfall,
                'verdict' => $carAfter >= 10 ? 'Pass' : ($carAfter >= 8 ? 'Marginal' : 'Fail'),
            ];
        });

        return view('risk.quantification.reports.stress-testing', [
            'scenarios' => $scenarios,
            'totalCapital' => $totalCapital,
            'car' => $car,
            'stressSim' => $stressSim,
            'hasData' => (bool) ($icaap || $stressSim),
        ]);
    }

    /**
     * Risk Contribution Analysis — economic-capital allocation by risk
     * type (simulation scenario_contributions) and by business unit
     * (aggregated residual scores).
     */
    public function riskContributionReport()
    {
        $orgId = TenantContext::organizationId();

        $latestSim = SimulationRun::where('organization_id', $orgId)
            ->where('status', 'completed')
            ->orderByDesc('completed_at')->first();

        // By risk type — use scenario contributions when present.
        $byType = collect();
        if ($latestSim) {
            $contribs = $latestSim->scenario_contributions;
            if ($contribs && $contribs->count()) {
                $byType = $contribs->map(fn ($c) => (object) [
                    'label' => $c->scenario_name,
                    'capital' => (float) ($c->expected_loss ?? 0),
                    'share_pct' => (float) ($c->contribution_pct ?? 0),
                ])->sortByDesc('capital')->values();
            }
        }

        // Fallback: use risk categories weighted by residual score when no
        // simulation contributions exist.
        if ($byType->isEmpty()) {
            $byType = Risk::where('organization_id', $orgId)
                ->where('status', 'active')
                ->with('category')
                ->get()
                ->groupBy(fn ($r) => optional($r->category)->name ?? 'Uncategorised')
                ->map(fn ($group, $label) => (object) [
                    'label' => $label,
                    'capital' => round($group->sum('residual_score'), 2),
                ])->values();
            $total = max(1, $byType->sum('capital'));
            $byType = $byType->map(function ($row) use ($total) {
                $row->share_pct = round(($row->capital / $total) * 100, 2);

                return $row;
            })->sortByDesc('capital')->values();
        }

        // By business unit — residual score aggregated.
        $byUnit = Risk::where('organization_id', $orgId)
            ->where('status', 'active')
            ->with('businessUnit')
            ->get()
            ->groupBy(fn ($r) => optional($r->businessUnit)->name ?? 'Unassigned')
            ->map(fn ($group, $label) => (object) [
                'label' => $label,
                'risks' => $group->count(),
                'capital' => round($group->sum('residual_score'), 2),
            ])->values();
        $totalUnit = max(1, $byUnit->sum('capital'));
        $byUnit = $byUnit->map(function ($row) use ($totalUnit) {
            $row->share_pct = round(($row->capital / $totalUnit) * 100, 2);

            return $row;
        })->sortByDesc('capital')->values();

        return view('risk.quantification.reports.risk-contribution', [
            'byType' => $byType,
            'byUnit' => $byUnit,
            'latestSim' => $latestSim,
            'hasData' => $byType->isNotEmpty() || $byUnit->isNotEmpty(),
        ]);
    }

    /**
     * Regulatory Compliance Pack — combined reporting snapshot pulling
     * capital, KRI, loss-event, and issues data for a one-page regulatory
     * view aligned to CBN ORMS expectations.
     */
    public function regulatoryPack()
    {
        $orgId = TenantContext::organizationId();
        $year = now()->year;

        $icaap = IcaapAssessment::where('organization_id', $orgId)
            ->orderByDesc('created_at')->first();

        $summary = (object) [
            'car_actual' => $icaap ? (float) ($icaap->car_actual ?? 0) : 0,
            'car_required' => $icaap ? (float) ($icaap->car_required ?? 10) : 10,
            'total_capital' => $icaap ? round(((float) ($icaap->total_qualifying_capital_kobo ?? 0)) / 100, 2) : 0,
            'active_risks' => Risk::where('organization_id', $orgId)->where('status', 'active')->count(),
            'critical_risks' => Risk::where('organization_id', $orgId)->where('residual_rating', 'Critical')->count(),
            'high_risks' => Risk::where('organization_id', $orgId)->where('residual_rating', 'High')->count(),
            'red_kris' => \App\Models\KeyRiskIndicator::where('organization_id', $orgId)->where('current_status', 'red')->count(),
            'amber_kris' => \App\Models\KeyRiskIndicator::where('organization_id', $orgId)->where('current_status', 'amber')->count(),
            'loss_events_ytd' => \App\Models\LossEvent::where('organization_id', $orgId)->whereYear('date_of_loss', $year)->count(),
            'net_loss_ytd' => (float) \App\Models\LossEvent::where('organization_id', $orgId)->whereYear('date_of_loss', $year)->sum(\App\Models\LossEvent::netLossNairaSql()),
            'open_issues' => \App\Models\Issue::where('organization_id', $orgId)->whereNotIn('issue_status', ['CLOSED', 'CANCELLED'])->count(),
            'overdue_issues' => \App\Models\Issue::where('organization_id', $orgId)->whereNotIn('issue_status', ['CLOSED', 'CANCELLED'])->where('remediation_due_date', '<', now())->count(),
            'regulatory_issues' => \App\Models\Issue::where('organization_id', $orgId)->where('regulatory_reportable', true)->whereNotIn('issue_status', ['CLOSED', 'CANCELLED'])->count(),
        ];

        // Checklist of filing items with a simple pass/warning/fail indicator.
        $checklist = [
            ['item' => 'CAR above CBN minimum (10%)', 'status' => $summary->car_actual >= $summary->car_required ? 'pass' : 'fail',
                'detail' => $summary->car_actual.'% actual vs '.$summary->car_required.'% required'],
            ['item' => 'ICAAP submitted this cycle', 'status' => $icaap ? 'pass' : 'fail',
                'detail' => $icaap ? 'Last assessment: '.$icaap->created_at->format('d M Y') : 'No ICAAP on record'],
            ['item' => 'No critical residual risks', 'status' => $summary->critical_risks === 0 ? 'pass' : 'warning',
                'detail' => $summary->critical_risks.' critical residual risks open'],
            ['item' => 'KRI breaches under threshold', 'status' => $summary->red_kris === 0 ? 'pass' : 'warning',
                'detail' => $summary->red_kris.' red / '.$summary->amber_kris.' amber'],
            ['item' => 'Regulatory issues closed',  'status' => $summary->regulatory_issues === 0 ? 'pass' : 'fail',
                'detail' => $summary->regulatory_issues.' regulatory issues still open'],
            ['item' => 'No overdue issues',         'status' => $summary->overdue_issues === 0 ? 'pass' : 'warning',
                'detail' => $summary->overdue_issues.' overdue issues'],
        ];

        return view('risk.quantification.reports.regulatory-pack', [
            'summary' => $summary,
            'checklist' => collect($checklist),
            'icaap' => $icaap,
        ]);
    }

    /**
     * Edit a quantification scenario.
     */
    public function editScenario(QuantificationScenario $scenario)
    {
        $orgId = TenantContext::organizationId();
        if ($scenario->organization_id !== $orgId) {
            abort(403);
        }

        $risks = Risk::where('organization_id', $orgId)->orderBy('risk_code')->get();
        $categories = RiskCategory::where('organization_id', $orgId)->orderBy('name')->get();

        return view('risk.quantification.create-scenario', compact('scenario', 'risks', 'categories'));
    }

    /**
     * Import scenario from library.
     */
    public function importLibrary(Request $request, string $libraryId)
    {
        $orgId = TenantContext::organizationId();

        $template = $this->libraryScenarios()->firstWhere('id', $libraryId);

        if (! $template) {
            return redirect()->route('risk.quantification.library')
                ->with('error', 'Library scenario not found.');
        }

        // Skip if this template was already imported for the organization
        $existing = QuantificationScenario::where('organization_id', $orgId)
            ->where('name', $template->name)
            ->first();

        if ($existing) {
            return redirect()->route('risk.quantification.show-scenario', $existing)
                ->with('success', "Scenario \"{$template->name}\" is already in your register ({$existing->scenario_reference}).");
        }

        // Auto-generate scenario reference: SCN-YYYY-NNN
        $year = now()->year;
        $lastScenario = QuantificationScenario::where('organization_id', $orgId)
            ->where('scenario_reference', 'like', "SCN-{$year}-%")
            ->orderByDesc('scenario_reference')
            ->first();

        $nextNumber = $lastScenario ? ((int) substr($lastScenario->scenario_reference, -3)) + 1 : 1;
        $scenarioReference = sprintf('SCN-%d-%03d', $year, $nextNumber);

        $meanNaira = (float) $template->mean;
        $stdDevNaira = (float) $template->std_dev;
        $meanKobo = round($meanNaira * 100);
        $freqYear = (float) $template->frequency_per_year;

        $mu = $meanKobo > 0 ? log($meanKobo) : 0;
        $sigma = ($stdDevNaira > 0 && $meanNaira > 0) ? sqrt(log(1 + ($stdDevNaira / $meanNaira) ** 2)) : 1.0;

        $scenario = QuantificationScenario::create([
            'organization_id' => $orgId,
            'scenario_reference' => $scenarioReference,
            'name' => $template->name,
            'description' => $template->description." (Imported from library — source: {$template->source})",
            'cbn_risk_category' => $template->risk_category,
            'severity_distribution' => $template->distribution_type,
            'frequency_distribution' => 'poisson',
            'frequency_lambda' => $freqYear,
            'expected_annual_frequency' => $freqYear,
            'severity_mu' => round($mu, 6),
            'severity_sigma' => round($sigma, 6),
            'expected_loss_per_event_kobo' => $meanKobo,
            'expected_annual_loss_kobo' => round($meanKobo * $freqYear),
            'status' => 'active',
            'created_by' => auth()->id(),
        ]);

        return redirect()->route('risk.quantification.show-scenario', $scenario)
            ->with('success', "Scenario {$scenarioReference} imported from library.");
    }

    /* ------------------------------------------------------------------ */
    /*  Private helpers */
    /* ------------------------------------------------------------------ */

    /**
     * Build visualization data for a lognormal distribution.
     */
    private function buildDistributionVisualization(QuantificationScenario $scenario): array
    {
        $mu = (float) ($scenario->severity_mu ?? log(max($scenario->expected_loss_per_event_kobo ?? 100000000, 1)));
        $sigma = (float) ($scenario->severity_sigma ?? 1.5);

        // Generate ~20 buckets for the PDF of a lognormal
        $meanVal = exp($mu + ($sigma ** 2) / 2);
        $maxX = $meanVal * 3;
        $step = $maxX / 20;

        $labels = [];
        $values = [];

        for ($x = $step; $x <= $maxX; $x += $step) {
            if ($x > 0) {
                $pdf = (1 / ($x * $sigma * sqrt(2 * M_PI))) * exp(-(log($x) - $mu) ** 2 / (2 * $sigma ** 2));
                $labels[] = '₦'.number_format(round($x / 100, 0));
                $values[] = round($pdf * $step, 6);
            }
        }

        return ['labels' => $labels, 'values' => $values];
    }
}
