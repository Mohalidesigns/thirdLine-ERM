<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\QuantificationScenario;
use App\Models\Risk;
use App\Models\RiskCategory;
use App\Models\SimulationRun;
use App\Services\Quantification\IcaapService;
use App\Services\Quantification\QuantificationDashboardService;
use App\Services\Quantification\QuantificationReportService;
use App\Services\Quantification\QuantificationSettingsService;
use App\Services\Quantification\ScenarioLibrary;
use App\Services\Quantification\ScenarioService;
use App\Services\Quantification\SimulationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

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
        private readonly QuantificationSettingsService $settings,
        private readonly QuantificationDashboardService $dashboards,
    ) {}

    /**
     * Authorise against the record's own policy and answer with the tenant id
     * the rest of the method needs.
     *
     * QuantificationScenarioPolicy, SimulationRunPolicy and
     * IcaapAssessmentPolicy all carry the tenant check that used to be written
     * out here by hand, so the permission and the boundary are asked about in
     * one place and the answer is the same from a screen, a form request or a
     * console command.
     */
    private function allow(string $ability, mixed $subject): int
    {
        Gate::authorize($ability, $subject);

        return TenantContext::organizationId();
    }

    /**
     * Quantification dashboard.
     *
     * Every figure comes from QuantificationDashboardService, pinned by
     * Characterisation/QuantificationDashboardTest.
     */
    public function dashboard()
    {
        return Inertia::render('Quantification/Dashboard', array_merge(
            $this->dashboards->figures(),
            ['recentSimulations' => $this->recentRuns()],
        ));
    }

    /**
     * The five most recent runs, presented as the results list presents them —
     * one aggregate read each, and the VaR levels labelled for the columns the
     * engine stores.
     *
     * @return list<array<string, mixed>>
     */
    private function recentRuns(): array
    {
        return SimulationRun::where('organization_id', TenantContext::organizationId())
            ->with('results')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn (SimulationRun $run) => $this->simulations->toListRow($run))
            ->all();
    }

    /**
     * The register's own category vocabulary.
     *
     * `cbn_risk_category` is a free string on the scenario, and the create form
     * has always offered the tenant's RiskCategory names for it.
     *
     * @return list<string>
     */
    private function categoryNames(int $orgId): array
    {
        return RiskCategory::where('organization_id', $orgId)
            ->orderBy('name')->pluck('name')->all();
    }

    /**
     * Everything both scenario forms need besides the values themselves.
     *
     * @return array<string, mixed>
     */
    private function scenarioFormOptions(int $orgId): array
    {
        return [
            'risks' => Risk::where('organization_id', $orgId)
                ->where('status', 'active')
                ->orderBy('risk_code')
                ->get(['id', 'risk_code', 'title'])
                ->all(),
            'categories' => $this->categoryNames($orgId),
            'distributions' => ScenarioService::SUPPORTED_SEVERITY_DISTRIBUTIONS,
        ];
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

        // Presented through ScenarioService, which maps the table's columns to
        // the form's vocabulary. The Blade table read that vocabulary straight
        // off the model, where none of it exists.
        $scenarios->through(fn (QuantificationScenario $scenario) => $this->scenarios->toListRow($scenario));

        return Inertia::render('Quantification/Scenarios/Index', [
            'scenarios' => $scenarios,
            'categories' => $this->categoryNames($orgId),
            'filters' => $request->only(['search', 'status', 'risk_category']),
        ]);
    }

    /**
     * Show form for creating a new scenario.
     */
    public function createScenario()
    {
        Gate::authorize('create', QuantificationScenario::class);

        $orgId = TenantContext::organizationId();

        return Inertia::render('Quantification/Scenarios/Create', array_merge(
            $this->scenarioFormOptions($orgId),
            ['initial' => [
                'name' => '',
                'description' => '',
                'risk_category' => '',
                'linked_risk_id' => '',
                'distribution_type' => ScenarioService::SUPPORTED_SEVERITY_DISTRIBUTIONS[0],
                'frequency_per_year' => '',
                'mean' => '',
                'std_dev' => '',
                'min_loss' => '',
                'max_loss' => '',
            ]],
        ));
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
        Gate::authorize('create', QuantificationScenario::class);

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
        $orgId = $this->allow('view', $scenario);

        $scenario->load(['riskRegister']);

        // Get simulation runs for this scenario
        $simulations = SimulationRun::where('organization_id', $orgId)
            ->whereJsonContains('scenario_ids', $scenario->id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return Inertia::render('Quantification/Scenarios/Show', [
            'scenario' => $scenario->only(['id', 'scenario_reference', 'name', 'description', 'status']),
            'values' => $this->scenarios->toFormValues($scenario),
            'simulations' => $simulations->map(fn (SimulationRun $run) => $run->only([
                'id', 'simulation_reference', 'status', 'iterations', 'completed_at',
            ]))->values(),
            'distributionVisualization' => $this->scenarios->distributionVisualization($scenario),
        ]);
    }

    /**
     * Update a scenario.
     */
    public function updateScenario(Request $request, QuantificationScenario $scenario)
    {
        $this->allow('update', $scenario);

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

        // The settings screen's whole purpose. Until Phase 5.2 this form
        // hardcoded 10,000 iterations, a one-year horizon and 95/99/99.5, so
        // the one setting that did persist reached nothing.
        return Inertia::render('Quantification/Simulate', [
            // Presented, so the picker states each scenario's real calibration.
            // The Blade version's subtitle read `risk_category`,
            // `distribution_type` and `mean` off the model, none of which are
            // columns, so every row offered "· · Mean: ₦0".
            'scenarios' => $scenarios->map(fn (QuantificationScenario $scenario) => $this->scenarios->toListRow($scenario))->values(),
            'defaults' => $this->settings->simulationDefaults($orgId),
            'iterationChoices' => QuantificationSettingsService::ITERATION_CHOICES,
            'horizonChoices' => QuantificationSettingsService::HORIZON_CHOICES,
            'confidenceChoices' => QuantificationSettingsService::CONFIDENCE_CHOICES,
        ]);
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
        Gate::authorize('create', SimulationRun::class);

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
        $this->allow('cancel', $simulation);

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

        $results = $query->with('results')->orderByDesc('created_at')->paginate(25)->withQueryString();

        $results->through(fn (SimulationRun $run) => $this->simulations->toListRow($run));

        return Inertia::render('Quantification/Results/Index', [
            'results' => $results,
            'filters' => $request->only('status'),
        ]);
    }

    /**
     * Display results for a specific simulation run.
     *
     * The three chart series come from SimulationService and read only the
     * percentiles the run stored.
     */
    public function showResults(SimulationRun $simulation)
    {
        $this->allow('view', $simulation);

        return Inertia::render('Quantification/Results/Show', array_merge(
            [
                'result' => $this->simulations->toListRow($simulation),
                'percentiles' => $simulation->percentiles,
                'expectedShortfall' => $simulation->expected_shortfall,
                'maxLoss' => $simulation->max_loss,
                'contributions' => collect($simulation->scenario_contributions)->values(),
            ],
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
        return Inertia::render('Quantification/Icaap', $this->icaap->report());
    }

    /**
     * Pre-built scenario library.
     * View expects $libraryScenarios (collection of objects with name, risk_category, etc.)
     */
    public function library(ScenarioLibrary $library)
    {
        // Each template says whether this organisation already holds it, so a
        // preparer is not offered an import that would file a duplicate.
        $templates = $library->all()->map(function (object $template) use ($library) {
            $existing = $library->importedScenario($template);

            return array_merge((array) $template, [
                'imported' => $existing === null
                    ? null
                    : ['id' => $existing->id, 'scenario_reference' => $existing->scenario_reference],
            ]);
        })->values();

        return Inertia::render('Quantification/Library', [
            'libraryScenarios' => $templates,
            'canImport' => Gate::allows('import', QuantificationScenario::class),
        ]);
    }

    /**
     * Quantification settings.
     *
     * Every field on this screen is now one QuantificationSettingsService
     * writes. The six that configured nothing — a pinned seed, a target CAR, a
     * countercyclical buffer and a green/amber/red band — are gone rather than
     * relocated; the note on that service says why.
     */
    public function settings()
    {
        return Inertia::render('Quantification/Settings', [
            'settings' => $this->settings->forDisplay(),
            'iterationChoices' => QuantificationSettingsService::ITERATION_CHOICES,
            'horizonChoices' => QuantificationSettingsService::HORIZON_CHOICES,
            'confidenceChoices' => QuantificationSettingsService::CONFIDENCE_CHOICES,
            'minimumCarGuidance' => [
                'national' => (float) config('quantification.default_minimum_car'),
                'international' => (float) config('quantification.international_or_dsib_minimum_car'),
            ],
        ]);
    }

    /**
     * Update quantification settings.
     */
    public function updateSettings(Request $request)
    {
        $this->settings->save($request->validate($this->settings->rules()));

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

        return Inertia::render('Quantification/Reports/Index', compact('completedSimulations'));
    }

    /**
     * Capital Adequacy Summary — the latest ICAAP condensed to CAR, tier
     * breakdown, Pillar 1 requirement, Pillar 2A/2B demand and headroom.
     */
    public function capitalAdequacyReport()
    {
        return Inertia::render('Quantification/Reports/CapitalAdequacy', $this->reports->capitalAdequacy());
    }

    /**
     * Stress Testing Report — the capital impact of the run deliberately bound
     * to the latest ICAAP assessment, and nothing else.
     */
    public function stressTestingReport()
    {
        return Inertia::render('Quantification/Reports/StressTesting', $this->reports->stressTesting());
    }

    /**
     * Risk Contribution Analysis — where the modelled loss sits, by risk type
     * and by business unit.
     */
    public function riskContributionReport()
    {
        return Inertia::render('Quantification/Reports/RiskContribution', $this->reports->riskContribution());
    }

    /**
     * Regulatory Compliance Pack — one-page capital, KRI, loss-event and issue
     * snapshot aligned to CBN ORMS expectations.
     */
    public function regulatoryPack()
    {
        return Inertia::render('Quantification/Reports/RegulatoryPack', $this->reports->regulatoryPack());
    }

    /**
     * Edit a quantification scenario.
     */
    public function editScenario(QuantificationScenario $scenario)
    {
        $orgId = $this->allow('update', $scenario);

        return Inertia::render('Quantification/Scenarios/Edit', array_merge(
            $this->scenarioFormOptions($orgId),
            [
                'scenario' => $scenario->only(['id', 'scenario_reference', 'name']),
                'initial' => $this->scenarios->toFormValues($scenario),
            ],
        ));
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
        Gate::authorize('import', QuantificationScenario::class);

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
