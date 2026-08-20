<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Jobs\RunSimulationJob;
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
     * Severity distributions a user is allowed to choose.
     *
     * WP-08. The form used to offer six — lognormal, normal, poisson, pareto,
     * weibull, beta — and the validator accepted all six. MonteCarloService
     * has exactly one severity draw, lognormalRandom(), and calls it
     * unconditionally. Choosing "Pareto" therefore stored the string 'pareto'
     * and then simulated a lognormal, so a scenario calibrated for a heavy
     * tail was quantified with a light one and nothing on screen said so. On
     * an ICAAP tail measure that is not a cosmetic difference.
     *
     * normal, poisson, pareto, weibull and beta come back to this list when —
     * and only when — MonteCarloService implements a draw for them. Offering a
     * distribution the engine cannot run is worse than not offering it: the
     * user gets a number, it just is not the number they asked for.
     */
    private const SUPPORTED_SEVERITY_DISTRIBUTIONS = ['lognormal'];

    /**
     * Sigma used when a scenario's coefficient of variation cannot be derived
     * (no standard deviation, or a zero mean). Unchanged from the pre-WP-08
     * behaviour so that scenarios keep the spread they were created with.
     */
    private const DEFAULT_SEVERITY_SIGMA = 1.0;

    /**
     * The VaR columns MonteCarloService actually writes on a simulation
     * result, mapped to the confidence level each one represents.
     *
     * Stress impact is reported off THIS map rather than off the run's
     * requested `confidence_levels`, because the two disagree: a run defaults
     * to asking for [95, 99, 99.5] but the engine stores no 99.5 column, and
     * SimulationRun::getVar995Attribute() answers a request for "99.5" with
     * the 99.9 figure. Reporting a 99.9 loss under a 99.5 heading is the kind
     * of mislabel this work package exists to remove, so the screen states the
     * levels that were genuinely computed.
     */
    private const STRESS_VAR_COLUMNS = [
        'var_90_kobo' => 90.0,
        'var_95_kobo' => 95.0,
        'var_99_kobo' => 99.0,
        'var_99_9_kobo' => 99.9,
    ];

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

        // Null, not zero, when there is nothing to report. `expected_shortfall`
        // used to return VaR(99) under an "ES approximated as average of losses
        // above VaR 95" comment — two different statistics — and this line then
        // coerced a missing figure to 0, so the tile read "₦0" whether the tail
        // mean was genuinely zero or had never been computed. The accessor now
        // returns the stored tail mean or null, and the view renders an explicit
        // not-assessed state for null.
        $var95 = $latestSim?->var_95;
        $expectedShortfall = $latestSim?->expected_shortfall;

        // Latest ICAAP
        $latestIcaap = IcaapAssessment::where('organization_id', $orgId)
            ->orderByDesc('created_at')->first();

        // WP-08. Three mislabels from the ICAAP screen were mirrored here and
        // are corrected the same way. (i) The regulatory minimum was hardcoded
        // to 10% in the blade; it is now resolved. (ii) CAR was the free-typed
        // `car_actual`; it is now computed from capital and RWA, null when
        // that is impossible, with the typed figure only as a stated fallback.
        // (iii) The `pillar2a_*` columns were summed into "Pillar 1 Capital"
        // and `pillar2b_stress_buffer_kobo` was charted as "Liquidity Risk" —
        // neither is what those columns hold.
        $minimumCar = $this->resolveMinimumCar($latestIcaap, $orgId);

        $capitalAdequacyRatio = $this->capitalRatioPercent(
            $latestIcaap?->total_qualifying_capital_kobo,
            $latestIcaap?->total_rwa_kobo,
        );
        $carReported = ($latestIcaap !== null && $latestIcaap->car_actual !== null)
            ? round((float) $latestIcaap->car_actual, 2)
            : null;
        $carBasis = $capitalAdequacyRatio !== null ? 'computed from capital / RWA' : 'as reported';
        $capitalAdequacyRatio ??= $carReported;

        $tier1 = $this->naira($latestIcaap?->tier1_capital_kobo);
        $tier2 = $this->naira($latestIcaap?->tier2_capital_kobo);
        $totalCapital = $this->naira($latestIcaap?->total_qualifying_capital_kobo);

        // Pillar 2A add-on (credit + market + operational + other) and the
        // Pillar 2B stress buffer, named for the columns they read.
        $pillar2aCapital = $latestIcaap
            ? round((($latestIcaap->pillar2a_credit_kobo ?? 0) + ($latestIcaap->pillar2a_market_kobo ?? 0) + ($latestIcaap->pillar2a_operational_kobo ?? 0) + ($latestIcaap->pillar2a_other_kobo ?? 0)) / 100, 2)
            : null;
        $pillar2bCapital = $this->naira($latestIcaap?->pillar2b_stress_buffer_kobo);

        $capitalBuffer = ($totalCapital !== null && $pillar2aCapital !== null && $pillar2bCapital !== null)
            ? round($totalCapital - $pillar2aCapital - $pillar2bCapital, 2)
            : null;

        $totalEconomicCapital = ($pillar2aCapital ?? 0) + ($pillar2bCapital ?? 0);

        // ICAAP capital add-on by component. The old chart headed these
        // "Credit / Market / Operational / Liquidity / Other" as though they
        // were a risk-type decomposition of economic capital; four of them are
        // Pillar 2A columns and the fifth is the Pillar 2B stress buffer,
        // which has nothing to do with liquidity risk.
        $capitalByTypeData = [
            'labels' => ['Pillar 2A — Credit', 'Pillar 2A — Market', 'Pillar 2A — Operational', 'Pillar 2A — Other', 'Pillar 2B — Stress Buffer'],
            'values' => [
                $this->naira($latestIcaap?->pillar2a_credit_kobo) ?? 0,
                $this->naira($latestIcaap?->pillar2a_market_kobo) ?? 0,
                $this->naira($latestIcaap?->pillar2a_operational_kobo) ?? 0,
                $this->naira($latestIcaap?->pillar2a_other_kobo) ?? 0,
                $this->naira($latestIcaap?->pillar2b_stress_buffer_kobo) ?? 0,
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
            'totalEconomicCapital', 'capitalAdequacyRatio', 'carReported', 'carBasis',
            'minimumCar', 'activeScenarios', 'simulationsRun',
            'var95', 'expectedShortfall', 'capitalByTypeData', 'lossDistData',
            'pillar2aCapital', 'pillar2bCapital', 'capitalBuffer', 'recentSimulations'
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
            'distribution_type' => 'required|in:'.implode(',', self::SUPPORTED_SEVERITY_DISTRIBUTIONS),
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
        $minKobo = $request->min_loss ? round((float) $request->min_loss * 100) : null;
        $maxKobo = $request->max_loss ? round((float) $request->max_loss * 100) : null;
        $freqYear = (float) $request->frequency_per_year;

        [$mu, $sigma, $meanKobo] = $this->lognormalParametersFromMoments(
            (float) $request->mean,
            (float) ($request->std_dev ?? 0),
        );

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
            'distribution_type' => 'required|in:'.implode(',', self::SUPPORTED_SEVERITY_DISTRIBUTIONS),
            'frequency_per_year' => 'required|numeric|min:0',
            'mean' => 'required|numeric|min:0',
            'std_dev' => 'nullable|numeric|min:0',
            'min_loss' => 'nullable|numeric|min:0',
            'max_loss' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:draft,active,archived',
        ]);

        $minKobo = $request->min_loss ? round((float) $request->min_loss * 100) : null;
        $maxKobo = $request->max_loss ? round((float) $request->max_loss * 100) : null;
        $freqYear = (float) $request->frequency_per_year;

        [$mu, $sigma, $meanKobo] = $this->lognormalParametersFromMoments(
            (float) $request->mean,
            (float) ($request->std_dev ?? 0),
        );

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

        // WP-07. This used to run 10,000 iterations x N scenarios inside the
        // request. On a real scenario set the web server killed it partway,
        // leaving status stuck on 'running' with no results and nothing on
        // screen to say why.
        //
        // The seed is decided when the run is QUEUED rather than inside the
        // worker, so the figure is recorded as re-derivable from the moment the
        // user presses the button, and a replayed job cannot produce a
        // different capital number from the same request. The draw itself lives
        // in MonteCarloService — the one file allowed to hold an RNG.
        $seed = MonteCarloService::drawSeed();
        $simulation->update(['random_seed' => $seed]);

        $jobRun = RunSimulationJob::track(
            label: "Simulation {$simReference}",
            subject: $simulation,
            organizationId: $orgId,
            creator: $request->user(),
            total: $validated['iterations'] * count($validated['scenario_ids']),
        );

        $simulation->update(['job_run_id' => $jobRun->id]);

        RunSimulationJob::dispatch(
            $simulation->id,
            $validated['scenario_ids'],
            $seed,
            $jobRun->id,
        );

        return redirect()->route('risk.quantification.show-results', $simulation)
            ->with('success', "Simulation {$simReference} is running. This page updates as it progresses.");
    }

    /**
     * Ask a running simulation to stop.
     *
     * A request, not an interrupt: a worker cannot be killed from here, only
     * told. The job checks between iterations and stops at a point where
     * nothing is half-written — a cancelled run has NO results, because a
     * partial loss distribution is not a smaller answer, it is a wrong one.
     */
    public function cancelSimulation(Request $request, SimulationRun $simulation)
    {
        abort_unless($simulation->organization_id === TenantContext::organizationId(), 403);

        if (! in_array($simulation->status, ['queued', 'running'], true)) {
            return back()->with('error', 'That simulation has already finished.');
        }

        $simulation->update(['cancel_requested_at' => now()]);

        \App\Models\JobRun::withoutGlobalScopes()
            ->whereKey($simulation->job_run_id)
            ->update([
                'cancel_requested_at' => now(),
                'cancel_requested_by' => $request->user()->id,
            ]);

        return back()->with('success', 'Cancellation requested. The run stops at its next checkpoint.');
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
     *
     * WP-08 rewrite. What this screen used to show, and why none of it
     * survived:
     *
     * 1. PILLAR 2A WAS LABELLED PILLAR 1. `pillar2a_credit_kobo`,
     *    `pillar2a_market_kobo` and `pillar2a_operational_kobo` were read into
     *    $pillar1Credit / $pillar1Market / $pillar1Operational and headed
     *    "Pillar 1 Capital Requirements". Pillar 1 is the regulatory minimum
     *    charge against risk-weighted assets; Pillar 2A is the ICAAP add-on
     *    for risks Pillar 1 does not fully capture. Presenting one as the
     *    other overstates Pillar 1 and hides the entire ICAAP add-on, which is
     *    the one thing this screen exists to evidence. The variables now carry
     *    the names of the columns they read, and a GENUINE Pillar 1 figure is
     *    computed from stored RWA:
     *
     *        Pillar 1 minimum capital requirement = (minimum CAR / 100) x total RWA
     *
     *    It is null, not zero, when RWA is absent.
     *
     * 2. THE PILLAR 2 DECOMPOSITION WAS INVENTED. `pillar2a_other_kobo` was
     *    sliced 30 / 25 / 25 / 20 and the four pieces presented as
     *    concentration, interest-rate, reputational and strategic risk, and
     *    exactly half of `pillar2b_stress_buffer_kobo` was presented as
     *    liquidity risk. Nobody computed those splits, no column holds them,
     *    and the four weights do not even sum to 1. All five are deleted.
     *    `pillar2a_other_kobo` is now shown as one undecomposed "Other Pillar
     *    2A" line and `pillar2b_stress_buffer_kobo` as the Pillar 2B stress
     *    buffer, which is what they are.
     *
     * 3. NO RATIO WAS EVER COMPUTED. CAR came straight from `car_actual`, a
     *    free-typed decimal, and `cet1_capital_kobo` was referenced exactly
     *    once in the whole codebase — in $fillable. CAR, the CET1 ratio and
     *    the Tier 1 ratio are now computed from stored capital and RWA, and
     *    the typed figure is kept alongside as "as reported" so the two can be
     *    reconciled on screen. A variance beyond
     *    config('quantification.car_reconciliation_tolerance') is called out
     *    explicitly rather than one number quietly winning.
     *
     * 4. THE CBN MINIMUM WAS HARDCODED. See resolveMinimumCar().
     *
     * 5. STRESS RESULTS WERE FABRICATED. See stressImpactRows(). The
     *    no-simulation fallback that invented three scenarios out of
     *    `$totalCapital * 0.08` is deleted outright; with no stress run bound,
     *    the screen now says so.
     */
    public function icaap()
    {
        $orgId = TenantContext::organizationId();

        $assessment = IcaapAssessment::where('organization_id', $orgId)
            ->orderByDesc('created_at')
            ->first();

        $minimumCar = $this->resolveMinimumCar($assessment, $orgId);
        $conservationBuffer = $this->resolveConservationBuffer($assessment, $orgId);

        // `icaap_assessments.cbn_minimum_car` is NOT NULL with a database
        // default of 10.0, so an assessment saved without an explicit minimum
        // still resolves to 10.0 and the organisation's standing figure is
        // never reached. That is the right precedence — an assessment is
        // reconciled against the minimum it was prepared under — but it is a
        // trap for a bank that set 15.0 org-wide and expected it to apply
        // retrospectively. Where the two disagree, the screen says so instead
        // of quietly preferring one.
        $organizationMinimumCar = QuantificationSetting::where('organization_id', $orgId)
            ->value('cbn_minimum_car');
        $organizationMinimumCar = $organizationMinimumCar === null ? null : (float) $organizationMinimumCar;

        // Stored inputs. Absent stays absent — an unrecorded balance sheet is
        // not a balance sheet of zeroes.
        $rwaKobo = $assessment?->total_rwa_kobo;
        $capitalKobo = $assessment?->total_qualifying_capital_kobo;
        $cet1Kobo = $assessment?->cet1_capital_kobo;
        $tier1Kobo = $assessment?->tier1_capital_kobo;
        $tier2Kobo = $assessment?->tier2_capital_kobo;

        $totalCapital = $this->naira($capitalKobo);
        $totalRwa = $this->naira($rwaKobo);
        $cet1Capital = $this->naira($cet1Kobo);
        $tier1Capital = $this->naira($tier1Kobo);
        $tier2Capital = $this->naira($tier2Kobo);

        // Ratios: capital / RWA * 100, null whenever RWA is missing or zero.
        $carComputed = $this->capitalRatioPercent($capitalKobo, $rwaKobo);
        $cet1Ratio = $this->capitalRatioPercent($cet1Kobo, $rwaKobo);
        $tier1Ratio = $this->capitalRatioPercent($tier1Kobo, $rwaKobo);

        // The preparer's own figure, kept separate and reconciled against ours.
        $carReported = ($assessment !== null && $assessment->car_actual !== null)
            ? round((float) $assessment->car_actual, 2)
            : null;

        $carVariance = ($carComputed !== null && $carReported !== null)
            ? round($carComputed - $carReported, 2)
            : null;

        $carVarianceMaterial = $carVariance !== null
            && abs($carVariance) > (float) config('quantification.car_reconciliation_tolerance');

        // Pillar 1 — the regulatory minimum charge against RWA. Computable
        // from stored quantities, so it is computed rather than borrowed from
        // the Pillar 2A columns as it used to be.
        $pillar1RequirementKobo = ($rwaKobo !== null && (float) $rwaKobo > 0)
            ? ($minimumCar / 100) * (float) $rwaKobo
            : null;
        $pillar1Requirement = $this->naira($pillar1RequirementKobo);

        // Pillar 2A — the ICAAP add-on, exactly as stored. No decomposition.
        $pillar2aCredit = $this->naira($assessment?->pillar2a_credit_kobo);
        $pillar2aMarket = $this->naira($assessment?->pillar2a_market_kobo);
        $pillar2aOperational = $this->naira($assessment?->pillar2a_operational_kobo);
        $pillar2aOther = $this->naira($assessment?->pillar2a_other_kobo);

        $pillar2aComponents = array_filter(
            [$pillar2aCredit, $pillar2aMarket, $pillar2aOperational, $pillar2aOther],
            fn ($v) => $v !== null,
        );
        $totalPillar2a = $pillar2aComponents === [] ? null : round(array_sum($pillar2aComponents), 2);

        // Pillar 2B — the stress buffer, as stored.
        $pillar2bStressBuffer = $this->naira($assessment?->pillar2b_stress_buffer_kobo);

        // Capital conservation buffer: a percentage of total RWA held in CET1,
        // not a percentage of qualifying capital as the old waterfall assumed.
        $conservationBufferAmount = ($rwaKobo !== null && (float) $rwaKobo > 0)
            ? $this->naira(($conservationBuffer / 100) * (float) $rwaKobo)
            : null;

        // Stress testing. Only from a bound run, only real arithmetic.
        $stressSimulation = null;
        $stressRows = collect();
        $stressAggregateMissing = false;

        if ($assessment !== null && $assessment->stress_simulation_id) {
            // Tenancy: the ICAAP row is already org-scoped, and the bound run
            // is re-scoped here so a mis-set foreign key can never surface
            // another organisation's loss distribution on this screen.
            $stressSimulation = SimulationRun::where('organization_id', $orgId)
                ->whereKey($assessment->stress_simulation_id)
                ->first();

            if ($stressSimulation !== null) {
                $stressRows = $this->stressImpactRows($stressSimulation, $assessment, $minimumCar);
                $stressAggregateMissing = $stressRows->isEmpty();
            }
        }

        $stressHeadlineConfidence = (float) config('quantification.headline_stress_confidence');
        $stressHeadline = $stressRows->firstWhere('confidence', $stressHeadlineConfidence) ?? $stressRows->last();

        // Capital waterfall, rebuilt from the corrected quantities. A missing
        // component stays null so Chart.js leaves a gap: a zero bar here would
        // read as "this bank has no Pillar 2A add-on", which is a claim, not
        // an absence of data.
        $waterfallComponents = [
            'Total Qualifying Capital' => $totalCapital,
            'Pillar 1 Requirement' => $pillar1Requirement === null ? null : -$pillar1Requirement,
            'Pillar 2A Add-on' => $totalPillar2a === null ? null : -$totalPillar2a,
            'Pillar 2B Stress Buffer' => $pillar2bStressBuffer === null ? null : -$pillar2bStressBuffer,
            'Conservation Buffer' => $conservationBufferAmount === null ? null : -$conservationBufferAmount,
        ];

        $waterfallMissing = array_keys(array_filter($waterfallComponents, fn ($v) => $v === null));

        // Available capital is only meaningful once every deduction is known.
        $availableCapital = $waterfallMissing === []
            ? round(array_sum($waterfallComponents), 2)
            : null;

        $waterfallData = [
            'labels' => [...array_keys($waterfallComponents), 'Available Capital'],
            'values' => [...array_values($waterfallComponents), $availableCapital],
        ];

        return view('risk.quantification.icaap', [
            'assessment' => $assessment,
            'hasAssessment' => $assessment !== null,
            'minimumCar' => $minimumCar,
            'organizationMinimumCar' => $organizationMinimumCar,
            'internationalMinimumCar' => (float) config('quantification.international_or_dsib_minimum_car'),
            'conservationBuffer' => $conservationBuffer,
            'conservationBufferAmount' => $conservationBufferAmount,
            'totalCapital' => $totalCapital,
            'totalRwa' => $totalRwa,
            'cet1Capital' => $cet1Capital,
            'tier1Capital' => $tier1Capital,
            'tier2Capital' => $tier2Capital,
            'carComputed' => $carComputed,
            'carReported' => $carReported,
            'carVariance' => $carVariance,
            'carVarianceMaterial' => $carVarianceMaterial,
            'cet1Ratio' => $cet1Ratio,
            'tier1Ratio' => $tier1Ratio,
            'pillar1Requirement' => $pillar1Requirement,
            'pillar2aCredit' => $pillar2aCredit,
            'pillar2aMarket' => $pillar2aMarket,
            'pillar2aOperational' => $pillar2aOperational,
            'pillar2aOther' => $pillar2aOther,
            'totalPillar2a' => $totalPillar2a,
            'pillar2bStressBuffer' => $pillar2bStressBuffer,
            'stressSimulation' => $stressSimulation,
            'stressRows' => $stressRows,
            'stressHeadline' => $stressHeadline,
            'stressAggregateMissing' => $stressAggregateMissing,
            'availableCapital' => $availableCapital,
            'waterfallData' => $waterfallData,
            'waterfallMissing' => $waterfallMissing,
        ]);
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
     *
     * WP-08. 'External Fraud - Cyber Attack' shipped as `pareto`, 'Natural
     * Disaster - Flooding' as `weibull` and 'FX Volatility Shock' as `normal`.
     * MonteCarloService has one severity draw — lognormalRandom() — and calls
     * it unconditionally, so ALL THREE were already being simulated as
     * lognormals; the stored distribution name was the only thing that said
     * otherwise. Retyping them `lognormal` changes no simulated number
     * whatsoever. It makes the record honest about what the engine ran, and
     * stops the library importing scenarios typed with a distribution the
     * create/edit form can no longer offer and the validator now rejects.
     *
     * A genuine heavy-tail calibration for the cyber-attack scenario has to
     * wait for a Pareto draw in the engine; every mean, standard deviation and
     * frequency below is unchanged and remains the calibration to re-fit
     * against when that lands.
     */
    private function libraryScenarios(): \Illuminate\Support\Collection
    {
        return collect([
            (object) ['id' => 'lib-1', 'name' => 'Internal Fraud - Unauthorized Trading', 'risk_category' => 'Operational Risk', 'distribution_type' => 'lognormal', 'description' => 'Losses from unauthorized transactions, mismarking, or rogue trading activities in Nigerian banking sector', 'mean' => 850000000, 'std_dev' => 425000000, 'frequency_per_year' => 1.5, 'source' => 'CBN ORMS Data'],
            (object) ['id' => 'lib-2', 'name' => 'External Fraud - Cyber Attack', 'risk_category' => 'Operational Risk', 'distribution_type' => 'lognormal', 'description' => 'Losses from cyber intrusion, phishing, BEC, or electronic fraud targeting bank systems', 'mean' => 1200000000, 'std_dev' => 800000000, 'frequency_per_year' => 3.2, 'source' => 'CBN ORMS Data'],
            (object) ['id' => 'lib-3', 'name' => 'IT System Failure', 'risk_category' => 'Operational Risk', 'distribution_type' => 'lognormal', 'description' => 'Losses from core banking system outages, data center failures, or IT infrastructure disruptions', 'mean' => 500000000, 'std_dev' => 250000000, 'frequency_per_year' => 2.0, 'source' => 'Industry Benchmark'],
            (object) ['id' => 'lib-4', 'name' => 'Regulatory Fine - CBN Penalty', 'risk_category' => 'Operational Risk', 'distribution_type' => 'lognormal', 'description' => 'Monetary penalties from CBN for regulatory breaches, non-compliance, or AML/KYC failures', 'mean' => 2000000000, 'std_dev' => 1500000000, 'frequency_per_year' => 0.8, 'source' => 'CBN Published Sanctions'],
            (object) ['id' => 'lib-5', 'name' => 'Credit Default - Corporate Portfolio', 'risk_category' => 'Credit Risk', 'distribution_type' => 'lognormal', 'description' => 'Losses from corporate loan defaults, particularly in oil & gas, manufacturing, and real estate sectors', 'mean' => 5000000000, 'std_dev' => 3000000000, 'frequency_per_year' => 4.5, 'source' => 'CBN Credit Bureau'],
            (object) ['id' => 'lib-6', 'name' => 'FX Volatility Shock', 'risk_category' => 'Market Risk', 'distribution_type' => 'lognormal', 'description' => 'Losses from sudden Naira devaluation or FX market volatility affecting open positions', 'mean' => 3500000000, 'std_dev' => 2000000000, 'frequency_per_year' => 1.0, 'source' => 'CBN Market Data'],
            (object) ['id' => 'lib-7', 'name' => 'Natural Disaster - Flooding', 'risk_category' => 'Operational Risk', 'distribution_type' => 'lognormal', 'description' => 'Physical damage to branches and data centers from flooding events in Lagos, Port Harcourt, and other coastal cities', 'mean' => 300000000, 'std_dev' => 200000000, 'frequency_per_year' => 0.5, 'source' => 'NEMA Data'],
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
     * tier breakdown, Pillar 1 requirement, Pillar 2A/2B demand, headroom.
     *
     * WP-08. This report carried the same two defects as the ICAAP screen and
     * is corrected the same way: the `pillar2a_*` columns were being printed
     * under a "Pillar 1 — Minimum Capital Requirements" heading, and the
     * regulatory minimum came from `$icaap->car_required ?? 10` — a column
     * that does not exist, so every tenant saw 10%. Pillar 1 is now computed
     * as (minimum CAR / 100) x total RWA, and the minimum is resolved once in
     * resolveMinimumCar().
     */
    public function capitalAdequacyReport()
    {
        $orgId = TenantContext::organizationId();

        $icaap = IcaapAssessment::where('organization_id', $orgId)
            ->orderByDesc('created_at')->first();

        $minimumCar = $this->resolveMinimumCar($icaap, $orgId);

        $rwaKobo = $icaap?->total_rwa_kobo;
        $capitalKobo = $icaap?->total_qualifying_capital_kobo;

        $data = (object) [
            'as_of' => $icaap?->created_at,
            'total_capital' => $this->naira($capitalKobo),
            'total_rwa' => $this->naira($rwaKobo),
            'cet1' => $this->naira($icaap?->cet1_capital_kobo),
            'tier1' => $this->naira($icaap?->tier1_capital_kobo),
            'tier2' => $this->naira($icaap?->tier2_capital_kobo),
            'car_computed' => $this->capitalRatioPercent($capitalKobo, $rwaKobo),
            'car_reported' => ($icaap !== null && $icaap->car_actual !== null) ? round((float) $icaap->car_actual, 2) : null,
            'cet1_ratio' => $this->capitalRatioPercent($icaap?->cet1_capital_kobo, $rwaKobo),
            'tier1_ratio' => $this->capitalRatioPercent($icaap?->tier1_capital_kobo, $rwaKobo),
            'car_required' => $minimumCar,
            'conservation_buffer' => $this->resolveConservationBuffer($icaap, $orgId),
            'pillar2a_credit' => $this->naira($icaap?->pillar2a_credit_kobo),
            'pillar2a_market' => $this->naira($icaap?->pillar2a_market_kobo),
            'pillar2a_operational' => $this->naira($icaap?->pillar2a_operational_kobo),
            'pillar2a_other' => $this->naira($icaap?->pillar2a_other_kobo),
            'pillar2b_buffer' => $this->naira($icaap?->pillar2b_stress_buffer_kobo),
        ];

        // Pillar 1 minimum capital requirement = (minimum CAR / 100) x RWA.
        $data->pillar1_requirement = ($rwaKobo !== null && (float) $rwaKobo > 0)
            ? $this->naira(($minimumCar / 100) * (float) $rwaKobo)
            : null;

        $pillar2aParts = array_filter(
            [$data->pillar2a_credit, $data->pillar2a_market, $data->pillar2a_operational, $data->pillar2a_other],
            fn ($v) => $v !== null,
        );
        $data->total_pillar2a = $pillar2aParts === [] ? null : round(array_sum($pillar2aParts), 2);

        // Headroom is only meaningful once every deduction is known; a partial
        // total would read as more headroom than the bank has.
        $deductions = [$data->pillar1_requirement, $data->total_pillar2a, $data->pillar2b_buffer];
        $data->headroom = ($data->total_capital !== null && ! in_array(null, $deductions, true))
            ? round($data->total_capital - array_sum($deductions), 2)
            : null;

        $data->car_surplus = $data->car_computed === null
            ? null
            : round($data->car_computed - $minimumCar, 2);

        $data->car_variance = ($data->car_computed !== null && $data->car_reported !== null)
            ? round($data->car_computed - $data->car_reported, 2)
            : null;

        $data->car_variance_material = $data->car_variance !== null
            && abs($data->car_variance) > (float) config('quantification.car_reconciliation_tolerance');

        return view('risk.quantification.reports.capital-adequacy', [
            'd' => $data, 'hasData' => (bool) $icaap,
        ]);
    }

    /**
     * Stress Testing Report — capital impact of the stress simulation bound to
     * the latest ICAAP assessment, plus the stress scenarios this tenant has
     * actually defined.
     *
     * WP-08 rewrite. What was deleted, and why:
     *
     * 1. FIVE HARDCODED SCENARIOS. 'Severe Recession' (-3.50pp), 'Oil Price
     *    Shock' (-2.10pp), 'Naira Devaluation' (-2.80pp), 'Cyber Attack +
     *    Market Crash' (-5.20pp) and 'Liquidity Squeeze' (-1.60pp), each
     *    scaled by an invented `factor`. Those CAR deltas were literals: they
     *    did not depend on the bank's balance sheet, its RWA, its portfolio
     *    mix or its own scenario library, so every tenant of this product saw
     *    the same five numbers regardless of what they hold. A stress test
     *    whose result is independent of the thing being stressed is not a
     *    stress test.
     *
     * 2. THE "LATEST COMPLETED SIMULATION" FALLBACK. When no stress run was
     *    bound, the report picked up whatever simulation had finished most
     *    recently. That is how a single-scenario operational-risk run — an
     *    internal fraud calibration, say — ended up presented to a bank's
     *    board as a macroeconomic stress test. There is no honest way to guess
     *    which run the preparer intended, so the report no longer guesses: it
     *    reports on the run that was deliberately bound to the assessment, or
     *    it reports nothing and says what to do about it.
     *
     * 3. THE 'Marginal' VERDICT BAND. Pass at >= 10%, Marginal at >= 8%, Fail
     *    below. There is no 8% supervisory threshold in the CBN capital
     *    guidelines; it was invented. The verdict is now binary against the
     *    resolved minimum.
     *
     * What replaces them is the arithmetic in stressImpactRows(): one row per
     * confidence level the bound run genuinely computed, each stating its own
     * confidence level, with capital impact, capital after stress, CAR after
     * stress and shortfall all derived from stored capital and RWA.
     */
    public function stressTestingReport()
    {
        $orgId = TenantContext::organizationId();

        $icaap = IcaapAssessment::where('organization_id', $orgId)
            ->orderByDesc('created_at')->first();

        $minimumCar = $this->resolveMinimumCar($icaap, $orgId);

        $totalCapital = $this->naira($icaap?->total_qualifying_capital_kobo);
        $totalRwa = $this->naira($icaap?->total_rwa_kobo);

        $carComputed = $this->capitalRatioPercent(
            $icaap?->total_qualifying_capital_kobo,
            $icaap?->total_rwa_kobo,
        );
        $carReported = ($icaap !== null && $icaap->car_actual !== null)
            ? round((float) $icaap->car_actual, 2)
            : null;

        // Only the deliberately bound run, and only this organisation's.
        $stressSim = null;
        if ($icaap !== null && $icaap->stress_simulation_id) {
            $stressSim = SimulationRun::where('organization_id', $orgId)
                ->whereKey($icaap->stress_simulation_id)
                ->first();
        }

        $rows = ($stressSim !== null && $icaap !== null)
            ? $this->stressImpactRows($stressSim, $icaap, $minimumCar)
            : collect();

        // The stress scenarios this tenant has defined for itself, so the
        // report can show what was in scope of the bound run and what was not.
        // A scenario is "stress" if it carries a CBN stress designation or was
        // typed as one — the two ways this schema records the flag.
        $stressScenarios = QuantificationScenario::where('organization_id', $orgId)
            ->where(function ($q) {
                $q->whereNotNull('cbn_stress_scenario')
                    ->orWhereRaw('LOWER(scenario_type) = ?', ['stress']);
            })
            ->orderBy('scenario_reference')
            ->get();

        $runScenarioIds = collect($stressSim?->scenario_ids ?? [])->map(fn ($id) => (int) $id)->all();

        $stressScenarios = $stressScenarios->map(fn ($scenario) => (object) [
            'reference' => $scenario->scenario_reference,
            'name' => $scenario->name,
            'category' => $scenario->cbn_risk_category,
            'cbn_stress_scenario' => $scenario->cbn_stress_scenario,
            'expected_annual_loss' => $this->naira($scenario->expected_annual_loss_kobo),
            'in_bound_run' => in_array((int) $scenario->id, $runScenarioIds, true),
        ]);

        return view('risk.quantification.reports.stress-testing', [
            'icaap' => $icaap,
            'rows' => $rows,
            'stressScenarios' => $stressScenarios,
            'stressSim' => $stressSim,
            'minimumCar' => $minimumCar,
            'totalCapital' => $totalCapital,
            'totalRwa' => $totalRwa,
            'carComputed' => $carComputed,
            'carReported' => $carReported,
            'hasBoundRun' => $stressSim !== null,
            'hasRows' => $rows->isNotEmpty(),
        ]);
    }

    /**
     * Risk Contribution Analysis — where the modelled loss sits, by risk type
     * and by business unit.
     *
     * WP-08. TWO THINGS WERE CALLED "CAPITAL" THAT ARE NOT CAPITAL.
     *
     * 1. `round($group->sum('residual_score'), 2)`, aggregated by category and
     *    by business unit, was emitted under the key `capital` and rendered in
     *    a column headed "Capital / Score". A residual score is an ordinal
     *    point on a 1-25 matrix. Ordinal values are not additive — the
     *    distance from 4 to 6 is not the distance from 20 to 22 — and they are
     *    not denominated in Naira, so a sum of them is neither a capital
     *    number nor a quantity that supports the percentage shares computed
     *    from it. The rows are now labelled for what they are: a total of
     *    residual scores, with the count of risks behind it, and the view is
     *    told which basis it is rendering via $byTypeBasis / $byUnitBasis so
     *    it cannot print a naira sign in front of an ordinal total. Nothing is
     *    fabricated to replace it; a bank that wants capital by business unit
     *    needs a simulation scoped to business units, which this engine does
     *    not yet run.
     *
     * 2. `risk_contributions` / `scenario_contributions` off a simulation
     *    result is each scenario's share of EXPECTED ANNUAL LOSS — it is
     *    computed in MonteCarloService as scenario expected loss over total
     *    expected loss. It is NOT a component-VaR or Euler capital allocation:
     *    it says nothing about how each scenario contributes to the TAIL, and
     *    a scenario with a small mean and a fat tail is exactly the one this
     *    measure under-reports. It must not be presented as an allocation of
     *    economic capital. The by-type rows therefore carry the expected-loss
     *    basis explicitly and the view labels the column "Expected Annual
     *    Loss", not "Capital".
     */
    public function riskContributionReport()
    {
        $orgId = TenantContext::organizationId();

        $latestSim = SimulationRun::where('organization_id', $orgId)
            ->where('status', 'completed')
            ->orderByDesc('completed_at')->first();

        // By risk type — scenario contributions when a completed run exists.
        // 'expected_loss' basis: Naira, share of total expected annual loss.
        $byType = collect();
        $byTypeBasis = 'residual_score';

        if ($latestSim) {
            $contribs = $latestSim->scenario_contributions;
            if ($contribs && $contribs->count()) {
                $byTypeBasis = 'expected_loss';
                $byType = $contribs->map(fn ($c) => (object) [
                    'label' => $c->scenario_name,
                    'value' => (float) ($c->expected_loss ?? 0),
                    'share_pct' => (float) ($c->contribution_pct ?? 0),
                    'risks' => null,
                ])->sortByDesc('value')->values();
            }
        }

        // Fallback when no simulation has run: risk categories by residual
        // score. 'residual_score' basis: ordinal totals, NOT Naira.
        if ($byType->isEmpty()) {
            $byTypeBasis = 'residual_score';
            $byType = Risk::where('organization_id', $orgId)
                ->where('status', 'active')
                ->with('category')
                ->get()
                ->groupBy(fn ($r) => optional($r->category)->name ?? 'Uncategorised')
                ->map(fn ($group, $label) => (object) [
                    'label' => $label,
                    'value' => round($group->sum('residual_score'), 2),
                    'risks' => $group->count(),
                ])->values();

            $total = $byType->sum('value');
            $byType = $byType->map(function ($row) use ($total) {
                $row->share_pct = $total > 0 ? round(($row->value / $total) * 100, 2) : null;

                return $row;
            })->sortByDesc('value')->values();
        }

        // By business unit — always residual score; the engine has never
        // produced a business-unit loss distribution.
        $byUnitBasis = 'residual_score';
        $byUnit = Risk::where('organization_id', $orgId)
            ->where('status', 'active')
            ->with('businessUnit')
            ->get()
            ->groupBy(fn ($r) => optional($r->businessUnit)->name ?? 'Unassigned')
            ->map(fn ($group, $label) => (object) [
                'label' => $label,
                'risks' => $group->count(),
                'value' => round($group->sum('residual_score'), 2),
            ])->values();

        $totalUnit = $byUnit->sum('value');
        $byUnit = $byUnit->map(function ($row) use ($totalUnit) {
            $row->share_pct = $totalUnit > 0 ? round(($row->value / $totalUnit) * 100, 2) : null;

            return $row;
        })->sortByDesc('value')->values();

        return view('risk.quantification.reports.risk-contribution', [
            'byType' => $byType,
            'byTypeBasis' => $byTypeBasis,
            'byUnit' => $byUnit,
            'byUnitBasis' => $byUnitBasis,
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

        // WP-08. Was `$icaap->car_required ?? 10` against a column that does
        // not exist, so the pack filed to CBN always claimed a 10% minimum.
        $minimumCar = $this->resolveMinimumCar($icaap, $orgId);

        // WP-08. CAR is recomputed from stored capital and RWA; the preparer's
        // typed `car_actual` is only used when RWA is not on file, and the
        // checklist says which basis it used.
        $carComputed = $this->capitalRatioPercent(
            $icaap?->total_qualifying_capital_kobo,
            $icaap?->total_rwa_kobo,
        );
        $carReported = ($icaap !== null && $icaap->car_actual !== null)
            ? round((float) $icaap->car_actual, 2)
            : null;

        $summary = (object) [
            'car_actual' => $carComputed ?? $carReported,
            'car_computed' => $carComputed,
            'car_reported' => $carReported,
            'car_basis' => $carComputed !== null ? 'computed from capital / RWA' : 'as reported on the assessment',
            'car_required' => $minimumCar,
            'total_capital' => $this->naira($icaap?->total_qualifying_capital_kobo),
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
        // The minimum is printed as resolved, not as a hardcoded "10%" — a
        // bank on international authorisation or designated a D-SIB is
        // measured against 15%, and this line is read as a compliance
        // assertion.
        $checklist = [
            ['item' => 'CAR above CBN minimum ('.rtrim(rtrim(number_format($summary->car_required, 2), '0'), '.').'%)',
                'status' => $summary->car_actual === null ? 'warning' : ($summary->car_actual >= $summary->car_required ? 'pass' : 'fail'),
                'detail' => $summary->car_actual === null
                    ? 'No capital position on record — CAR cannot be assessed'
                    : $summary->car_actual.'% ('.$summary->car_basis.') vs '.$summary->car_required.'% required'],
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

        $freqYear = (float) $template->frequency_per_year;

        [$mu, $sigma, $meanKobo] = $this->lognormalParametersFromMoments(
            (float) $template->mean,
            (float) $template->std_dev,
        );

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
     * Method-of-moments lognormal parameters from a mean and standard
     * deviation quoted in Naira.
     *
     * THE DEFECT (WP-08). Three copies of this arithmetic — storeScenario,
     * updateScenario and importLibrary — computed
     *
     *     $mu = log($meanKobo);
     *
     * For a lognormal, E[X] = exp(mu + sigma^2/2). Setting mu = ln(mean)
     * therefore does not produce a distribution whose mean is `mean`; it
     * produces one whose mean is mean * exp(sigma^2/2). At sigma = 1.0 — the
     * fallback used whenever no standard deviation is supplied — every
     * simulated severity was 1.65x the mean the user typed in, and at the
     * engine's own sigma = 1.5 fallback it was 3.08x. That error flows
     * straight through expected loss, VaR and the ICAAP capital number.
     *
     * The correct inversion, given a target mean m and standard deviation s:
     *
     *     sigma = sqrt( ln( 1 + (s/m)^2 ) )
     *     mu    = ln(m) - sigma^2 / 2
     *
     * sigma was already right; only mu was wrong, and it can only be computed
     * once sigma is known, which is why sigma is derived first here. The same
     * file already used the correct relationship in
     * buildDistributionVisualization() (exp($mu + $sigma^2/2)) — the two sides
     * of the screen disagreed with each other.
     *
     * The ratio s/m is dimensionless, so it is taken in Naira while mu is
     * taken in kobo; the parameters are stored against a kobo scale because
     * that is the scale MonteCarloService draws on.
     *
     * Existing rows written under the old formula are corrected by migration
     * 2026_08_19_120003_correct_lognormal_mu_on_scenarios.
     *
     * @return array{0: float, 1: float, 2: float} [mu, sigma, mean in kobo]
     */
    private function lognormalParametersFromMoments(float $meanNaira, float $stdDevNaira): array
    {
        $meanKobo = round($meanNaira * 100);

        $sigma = ($stdDevNaira > 0 && $meanNaira > 0)
            ? sqrt(log(1 + ($stdDevNaira / $meanNaira) ** 2))
            : self::DEFAULT_SEVERITY_SIGMA;

        $mu = $meanKobo > 0
            ? log($meanKobo) - ($sigma ** 2) / 2
            : 0.0;

        return [$mu, $sigma, $meanKobo];
    }

    /**
     * Resolve the minimum Capital Adequacy Ratio, in percent, that this
     * organisation is to be measured against.
     *
     * REGULATORY BASIS. The CBN Guidelines on Regulatory Capital (September
     * 2021) set the minimum CAR at 10.0% of total risk-weighted assets for
     * banks on a national or regional authorisation, and 15.0% for banks on an
     * international authorisation and for Domestic Systemically Important
     * Banks (D-SIBs). A capital conservation buffer of 1.0% of total RWA is
     * required on top, to be met with CET1, and a D-SIB carries a further
     * higher-loss-absorbency surcharge of 1.0%, also met with CET1.
     *
     * A bank on international authorisation, or one designated a D-SIB, MUST
     * configure 15.0 — either on the assessment (`cbn_minimum_car`) or as the
     * organisation's standing figure in Quantification Settings. Nothing here
     * infers authorisation class or D-SIB status, and nothing here rewrites a
     * stored figure; an assessment is always measured against the minimum it
     * was prepared against, which is what a validator reconciles to.
     *
     * THE DEFECT (WP-08). This used to be `$icaap->car_required ?? 10`.
     * `car_required` is not a column on icaap_assessments and never has been,
     * so the null coalesce fired on every single row and the screen showed a
     * hardcoded 10% to every tenant — including the international banks and
     * D-SIBs for whom the answer is 15%. Meanwhile
     * `quantification_settings.cbn_minimum_car`, which the settings screen
     * lets an org edit, was read by nothing at all. Both are now resolved
     * here, in one place, in a stated order.
     */
    private function resolveMinimumCar(?IcaapAssessment $assessment, ?int $organizationId = null): float
    {
        if ($assessment !== null && $assessment->cbn_minimum_car !== null && (float) $assessment->cbn_minimum_car > 0) {
            return (float) $assessment->cbn_minimum_car;
        }

        $organizationId ??= TenantContext::organizationId();

        $setting = QuantificationSetting::where('organization_id', $organizationId)->first();

        if ($setting !== null && $setting->cbn_minimum_car !== null && (float) $setting->cbn_minimum_car > 0) {
            return (float) $setting->cbn_minimum_car;
        }

        return (float) config('quantification.default_minimum_car');
    }

    /**
     * Resolve the capital conservation buffer, in percent of total RWA.
     *
     * Same resolution order and same rule as the minimum CAR: whatever the
     * assessment was prepared against wins, then the organisation's standing
     * figure, then the CBN default of 1.0%. The previous code fell back to a
     * hardcoded 2.5 — the Basel III figure, not the Nigerian one.
     */
    private function resolveConservationBuffer(?IcaapAssessment $assessment, ?int $organizationId = null): float
    {
        if ($assessment !== null && $assessment->conservation_buffer !== null && (float) $assessment->conservation_buffer > 0) {
            return (float) $assessment->conservation_buffer;
        }

        $organizationId ??= TenantContext::organizationId();

        $setting = QuantificationSetting::where('organization_id', $organizationId)->first();

        if ($setting !== null && $setting->cbn_conservation_buffer !== null && (float) $setting->cbn_conservation_buffer > 0) {
            return (float) $setting->cbn_conservation_buffer;
        }

        return (float) config('quantification.default_conservation_buffer');
    }

    /**
     * A capital ratio in percent, or null when it cannot be computed.
     *
     * NULL, never 0. A bank with no RWA on file has an UNKNOWN CAR, and 0% is
     * a specific, catastrophic claim about a bank's solvency — it is the one
     * number the screen must never invent. Every caller passes the null
     * through to the view so it can say "Not assessed".
     */
    private function capitalRatioPercent(int|float|null $capitalKobo, int|float|null $rwaKobo): ?float
    {
        if ($capitalKobo === null || $rwaKobo === null || (float) $rwaKobo <= 0.0) {
            return null;
        }

        return round(((float) $capitalKobo / (float) $rwaKobo) * 100, 2);
    }

    /**
     * Kobo → Naira, preserving null.
     */
    private function naira(int|float|null $kobo): ?float
    {
        return $kobo === null ? null : round((float) $kobo / 100, 2);
    }

    /**
     * The capital a stress run consumes, and what the balance sheet looks like
     * afterwards, at every confidence level the engine genuinely computed.
     *
     * THE DEFECT (WP-08). Both the ICAAP screen and the stress report used to
     * invent scenario names ('Severe Recession', 'Oil Price Shock', 'Cyber
     * Attack + Market Crash', ...) and subtract hardcoded CAR drops of 3.5,
     * 2.1, 2.8, 5.2 and 1.6 percentage points, optionally scaled by an
     * invented `factor`. Those drops were independent of the bank's capital,
     * its RWA and its portfolio mix — the same five numbers appeared for every
     * tenant — and no bank had ever defined those scenarios. They are gone.
     *
     * What is reported instead is arithmetic on stored quantities only:
     *
     *     capital impact = the run's aggregate VaR at confidence level c
     *     capital after  = total qualifying capital - capital impact
     *     CAR after      = capital after / total RWA * 100
     *     shortfall      = max(0, (minimum CAR / 100) * total RWA - capital after)
     *     verdict        = CAR after >= minimum CAR
     *
     * One row per confidence level the run stored, each labelled with the
     * level it came from, rather than one row per invented scenario name.
     * Anything that cannot be computed is null and stays null.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function stressImpactRows(SimulationRun $stressRun, IcaapAssessment $assessment, float $minimumCar): \Illuminate\Support\Collection
    {
        $aggregate = $stressRun->aggregate_result;

        if (! $aggregate) {
            return collect();
        }

        $capitalKobo = $assessment->total_qualifying_capital_kobo;
        $rwaKobo = $assessment->total_rwa_kobo;

        $requiredCapitalKobo = ($rwaKobo !== null && (float) $rwaKobo > 0)
            ? ($minimumCar / 100) * (float) $rwaKobo
            : null;

        $rows = collect();

        foreach (self::STRESS_VAR_COLUMNS as $column => $confidence) {
            $lossKobo = $aggregate->{$column};

            if ($lossKobo === null) {
                continue;
            }

            $capitalAfterKobo = $capitalKobo === null
                ? null
                : (float) $capitalKobo - (float) $lossKobo;

            $carAfter = $this->capitalRatioPercent($capitalAfterKobo, $rwaKobo);

            $shortfallKobo = ($requiredCapitalKobo !== null && $capitalAfterKobo !== null)
                ? max(0.0, $requiredCapitalKobo - $capitalAfterKobo)
                : null;

            $rows->push((object) [
                'confidence' => $confidence,
                'basis' => 'Aggregate VaR at '.rtrim(rtrim(number_format($confidence, 1), '0'), '.').'% confidence',
                'capital_impact' => $this->naira($lossKobo),
                'capital_after' => $this->naira($capitalAfterKobo),
                'car_after' => $carAfter,
                'shortfall' => $this->naira($shortfallKobo),
                'meets_minimum' => $carAfter === null ? null : $carAfter >= $minimumCar,
            ]);
        }

        return $rows;
    }

    /**
     * Build visualization data for a lognormal distribution.
     *
     * WP-08 note on the mu fallback below. When a scenario has no stored
     * severity_mu this uses log(mean) WITHOUT the -sigma^2/2 correction that
     * lognormalParametersFromMoments() applies, so the curve drawn for such a
     * scenario has a mean of mean * exp(sigma^2/2) rather than mean.
     *
     * That is deliberate and it is left alone. MonteCarloService::runSimulation
     * uses the identical fallback (`log(max($scenario->expected_loss_per_event_kobo ?? 1e8, 1))`,
     * sigma 1.5) when a scenario has no stored parameters, and this chart's job
     * is to show the distribution the engine will actually draw from — not a
     * different, better one. Correcting it here alone would put the picture and
     * the simulation out of step, which is how the two halves of this screen
     * disagreed in the first place. The fallback belongs to the engine and has
     * to be fixed there; the parameters WRITTEN by this controller are already
     * correct, so any scenario created or edited since WP-08 never reaches it.
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
