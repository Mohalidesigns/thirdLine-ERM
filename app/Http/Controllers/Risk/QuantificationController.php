<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\IcaapAssessment;
use App\Models\QuantificationScenario;
use App\Models\QuantificationSetting;
use App\Models\Risk;
use App\Models\RiskCategory;
use App\Models\SimulationRun;
use App\Services\Quantification\IcaapService;
use App\Services\Quantification\QuantificationReportService;
use App\Services\Quantification\ScenarioLibrary;
use App\Services\Quantification\ScenarioService;
use App\Services\Quantification\SimulationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;

class QuantificationController extends Controller
{
    /**
     * The capital arithmetic, shared by the ICAAP screen and the four reports.
     *
     * `naira()`, `capitalRatioPercent()`, the two resolvers and
     * `stressImpactRows()` all moved to IcaapService in Phase 5.2; the four
     * report assemblers moved to QuantificationReportService, which reads the
     * same arithmetic so the reports and the screen cannot drift apart.
     */
    public function __construct(
        private readonly IcaapService $icaap,
        private readonly QuantificationReportService $reports,
        private readonly ScenarioService $scenarios,
        private readonly SimulationService $simulations,
    ) {}

    /**
     * Every route-model-bound record on this controller is checked against the
     * tenant by hand; the policies land later in Phase 5.2.
     */
    private function authorizeTenant(?int $organizationId, string $message = 'Unauthorized.'): int
    {
        $orgId = TenantContext::organizationId();

        abort_unless($organizationId === $orgId, 403, $message);

        return $orgId;
    }

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
        $minimumCar = $this->icaap->resolveMinimumCar($latestIcaap, $orgId);

        $capitalAdequacyRatio = $this->icaap->capitalRatioPercent(
            $latestIcaap?->total_qualifying_capital_kobo,
            $latestIcaap?->total_rwa_kobo,
        );
        $carReported = ($latestIcaap !== null && $latestIcaap->car_actual !== null)
            ? round((float) $latestIcaap->car_actual, 2)
            : null;
        $carBasis = $capitalAdequacyRatio !== null ? 'computed from capital / RWA' : 'as reported';
        $capitalAdequacyRatio ??= $carReported;

        $tier1 = $this->icaap->naira($latestIcaap?->tier1_capital_kobo);
        $tier2 = $this->icaap->naira($latestIcaap?->tier2_capital_kobo);
        $totalCapital = $this->icaap->naira($latestIcaap?->total_qualifying_capital_kobo);

        // Pillar 2A add-on (credit + market + operational + other) and the
        // Pillar 2B stress buffer, named for the columns they read.
        $pillar2aCapital = $latestIcaap
            ? round((($latestIcaap->pillar2a_credit_kobo ?? 0) + ($latestIcaap->pillar2a_market_kobo ?? 0) + ($latestIcaap->pillar2a_operational_kobo ?? 0) + ($latestIcaap->pillar2a_other_kobo ?? 0)) / 100, 2)
            : null;
        $pillar2bCapital = $this->icaap->naira($latestIcaap?->pillar2b_stress_buffer_kobo);

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
                $this->icaap->naira($latestIcaap?->pillar2a_credit_kobo) ?? 0,
                $this->icaap->naira($latestIcaap?->pillar2a_market_kobo) ?? 0,
                $this->icaap->naira($latestIcaap?->pillar2a_operational_kobo) ?? 0,
                $this->icaap->naira($latestIcaap?->pillar2a_other_kobo) ?? 0,
                $this->icaap->naira($latestIcaap?->pillar2b_stress_buffer_kobo) ?? 0,
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
     *
     * The form's Naira-and-moments vocabulary is mapped to the table's columns
     * by ScenarioService, which owns the lognormal conversion both write paths
     * share.
     */
    public function storeScenario(Request $request)
    {
        $scenario = $this->scenarios->create(
            $request->validate($this->scenarios->rules()),
        );

        return redirect()->route('risk.quantification.show-scenario', $scenario)
            ->with('success', "Scenario {$scenario->scenario_reference} has been created.");
    }

    /**
     * Display a scenario.
     */
    public function showScenario(QuantificationScenario $scenario)
    {
        $orgId = $this->authorizeTenant($scenario->organization_id, 'Unauthorized access to this scenario.');

        $scenario->load(['riskRegister']);

        // Get simulation runs for this scenario
        $simulations = SimulationRun::where('organization_id', $orgId)
            ->whereJsonContains('scenario_ids', $scenario->id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $distributionVisualization = $this->scenarios->distributionVisualization($scenario);

        return view('risk.quantification.show-scenario', compact('scenario', 'simulations', 'distributionVisualization'));
    }

    /**
     * Update a scenario.
     */
    public function updateScenario(Request $request, QuantificationScenario $scenario)
    {
        $this->authorizeTenant($scenario->organization_id, 'Unauthorized access to this scenario.');

        $this->scenarios->update(
            $scenario,
            $request->validate($this->scenarios->rules(forUpdate: true)),
        );

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
     *
     * The seed, the job hand-off and the reference sequence are
     * SimulationService's; what stays here is the tenant check on the selected
     * scenarios, which the `exists:` rule cannot make.
     */
    public function runSimulation(Request $request)
    {
        $validated = $request->validate($this->simulations->rules());

        if (! $this->simulations->scenariosBelongToOrganization($validated['scenario_ids'])) {
            return back()->with('error', 'One or more selected scenarios are invalid.');
        }

        $simulation = $this->simulations->launch($validated, $request->user());

        return redirect()->route('risk.quantification.show-results', $simulation)
            ->with('success', "Simulation {$simulation->simulation_reference} is running. This page updates as it progresses.");
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
        $this->authorizeTenant($simulation->organization_id);

        if (! $this->simulations->requestCancellation($simulation, $request->user())) {
            return back()->with('error', 'That simulation has already finished.');
        }

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
     *
     * The three chart series come from SimulationService and read only the
     * percentiles the run stored.
     */
    public function showResults(SimulationRun $simulation)
    {
        $this->authorizeTenant($simulation->organization_id, 'Unauthorized access to this simulation.');

        return view('risk.quantification.show-results', array_merge(
            ['result' => $simulation],
            $this->simulations->resultCharts($simulation),
        ));
    }

    /**
     * ICAAP — capital adequacy, the pillars and the stress reconciliation.
     *
     * Every figure comes from IcaapService, pinned by
     * Characterisation/IcaapCharacterisationTest.
     */
    public function icaap()
    {
        return view('risk.quantification.icaap', $this->icaap->report());
    }

    /**
     * Pre-built scenario library.
     * View expects $libraryScenarios (collection of objects with name, risk_category, etc.)
     */
    public function library(ScenarioLibrary $library)
    {
        return view('risk.quantification.library', ['libraryScenarios' => $library->all()]);
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
     * Capital Adequacy Summary — the latest ICAAP condensed to CAR, tier
     * breakdown, Pillar 1 requirement, Pillar 2A/2B demand and headroom.
     */
    public function capitalAdequacyReport()
    {
        return view('risk.quantification.reports.capital-adequacy', $this->reports->capitalAdequacy());
    }

    /**
     * Stress Testing Report — the capital impact of the run deliberately bound
     * to the latest ICAAP assessment, and nothing else.
     */
    public function stressTestingReport()
    {
        return view('risk.quantification.reports.stress-testing', $this->reports->stressTesting());
    }

    /**
     * Risk Contribution Analysis — where the modelled loss sits, by risk type
     * and by business unit.
     */
    public function riskContributionReport()
    {
        return view('risk.quantification.reports.risk-contribution', $this->reports->riskContribution());
    }

    /**
     * Regulatory Compliance Pack — one-page capital, KRI, loss-event and issue
     * snapshot aligned to CBN ORMS expectations.
     */
    public function regulatoryPack()
    {
        return view('risk.quantification.reports.regulatory-pack', $this->reports->regulatoryPack());
    }

    /**
     * Edit a quantification scenario.
     */
    public function editScenario(QuantificationScenario $scenario)
    {
        $orgId = $this->authorizeTenant($scenario->organization_id);

        $risks = Risk::where('organization_id', $orgId)->orderBy('risk_code')->get();
        $categories = RiskCategory::where('organization_id', $orgId)->orderBy('name')->get();

        return view('risk.quantification.create-scenario', compact('scenario', 'risks', 'categories'));
    }

    /**
     * Import a library template into this organisation's scenario register.
     *
     * The template's provenance is written into the scenario's own description
     * — see ScenarioLibrary — so a parameter that later feeds a Monte Carlo run
     * and an ICAAP add-on can still say where it came from.
     */
    public function importLibrary(Request $request, string $libraryId, ScenarioLibrary $library)
    {
        $template = $library->find($libraryId);

        if ($template === null) {
            return redirect()->route('risk.quantification.library')
                ->with('error', 'Library scenario not found.');
        }

        $existing = $library->importedScenario($template);

        if ($existing !== null) {
            return redirect()->route('risk.quantification.show-scenario', $existing)
                ->with('success', "Scenario \"{$template->name}\" is already in your register ({$existing->scenario_reference}).");
        }

        $reference = $library->nextReference();

        $scenario = QuantificationScenario::create($library->attributesFor($template, $reference));

        return redirect()->route('risk.quantification.show-scenario', $scenario)
            ->with('success', "Scenario {$reference} imported from library.");
    }
}
