<?php

namespace App\Http\Controllers\Sm;

use App\Grids\GridRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sm\SearchRisksRequest;
use App\Http\Requests\Sm\StoreScenarioRequest;
use App\Http\Requests\Sm\UpdateFrequencyRequest;
use App\Http\Requests\Sm\UpdateImpactRequest;
use App\Http\Requests\Sm\UpdateInsuranceRequest;
use App\Http\Requests\Sm\UpdateScenarioAllocationsRequest;
use App\Http\Requests\Sm\UpdateScenarioCausesRequest;
use App\Http\Requests\Sm\UpdateScenarioRequest;
use App\Http\Requests\Sm\UpdateScorecardRequest;
use App\Http\Requests\Sm\UpdateVariantsRequest;
use App\Models\Risk;
use App\Models\Sm\Scenario;
use App\Presenters\GridPresenter;
use App\Presenters\Sm\ScenarioCreatePresenter;
use App\Presenters\Sm\ScenarioIndexPresenter;
use App\Presenters\Sm\ScenarioWorkspacePresenter;
use App\Services\Sm\AllocationService;
use App\Services\Sm\CauseService;
use App\Services\Sm\ScenarioQuantitativeService;
use App\Services\Sm\ScenarioRefused;
use App\Services\Sm\ScenarioService;
use App\Services\Sm\ScenarioVariantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Scenario Modelling & Analysis — the read and edit surfaces (ADR 0027 §A1.11,
 * specs 00–04, 06–13).
 *
 * THIN, per standard §1: a controller resolves, authorises and hands a Presenter or a
 * Service the job. `Inertia::render` takes the result; it does not compute it. Every
 * payload here comes from `App\Presenters\Sm`, every write from `App\Services\Sm`, and
 * every validation string from a Form Request.
 *
 * LIFECYCLE RULES LIVE HERE AND ANSWER WITH A FLASH, not a 403 (standard §3, §A1.8).
 * `ScenarioRefused` carries the exact sentence, so the rule is testable without HTTP and
 * the wording cannot drift between the service and the screen. The precedent §A1.8 names
 * is `Risk\LossEventController::updateStatus()`.
 *
 * The governance writes — status, sign-offs, notes — are in
 * `ScenarioGovernanceController`, because they answer to three different permissions
 * (`scenario.validate`, `scenario.sign_off`, `scenario.note`) and §A1.10 keeps those
 * authorities separate on purpose.
 */
class ScenarioController extends Controller
{
    /** The eight live tabs in Phase 3 (specs 01, 10, 11; ADR 0027 Amendment 3). */
    private const TABS = ['overview', 'qualitative', 'frequency', 'impact', 'insurance', 'scorecard', 'variants', 'actions'];

    public function __construct(
        private readonly ScenarioService $scenarios,
        private readonly CauseService $causes,
        private readonly AllocationService $allocations,
        private readonly ScenarioQuantitativeService $quantitativeService,
        private readonly ScenarioVariantService $variantService,
    ) {}

    /**
     * The work queue (spec 00).
     *
     * `grid` is the shared `GridPresenter` over `ScenariosGrid` — standard §8's "any list"
     * primitive, which is also what makes the CSV/XLSX export, saved views, the column
     * chooser and the server-side paging exist without a line of new code. The export is
     * gated by `ScenariosGrid::permission()` through `can:view-grid,grid`, i.e. by
     * `scenario.view` and NOT by `scenario.run_export` — that one is reserved for Phase 5's
     * LS-workbook and board-pack documents (spec 00 §3.5).
     */
    public function index(Request $request, GridPresenter $grid, ScenarioIndexPresenter $presenter): Response
    {
        Gate::authorize('scenario.view');

        return Inertia::render('Sm/Scenarios/Index', array_merge(
            $presenter->present($request->user()),
            [
                'grid' => fn () => $grid->present(
                    GridRegistry::resolve('scenarios'),
                    $request,
                    $request->user()
                ),
            ]
        ));
    }

    /**
     * The create form — four fields, and NO reference (spec 04).
     *
     * The reference is assigned on save only: showing `SM-2026-014` before it exists is
     * displaying a figure nothing produced (standard §5), and `max + 1` means two designers
     * creating at once would both be shown a number one of them never gets.
     */
    public function create(ScenarioCreatePresenter $presenter): Response
    {
        Gate::authorize('scenario.create');

        return Inertia::render('Sm/Scenarios/Create', $presenter->present());
    }

    /**
     * Create the scenario and give it its reference.
     *
     * A reference collision retries up to three times inside `ScenarioService` and then
     * FLASHES "We could not assign a reference. Please try again." — never a 500, and never
     * a silently different reference (addendum-2).
     */
    public function store(StoreScenarioRequest $request): RedirectResponse
    {
        try {
            $scenario = $this->scenarios->create($request->scenarioAttributes(), $request->user());
        } catch (ScenarioRefused $e) {
            return back()->withInput()->with('error', $e->flash());
        }

        // Spec 04 §6.1: the redirect lands on the workspace at `?tab=overview`, and the
        // flash speaks the reference for the FIRST time — "the first moment the reference is
        // spoken or shown anywhere, and the server's string, not the client's".
        return to_route('scenario.show', ['scenario' => $scenario, 'tab' => 'overview'])
            ->with('success', "{$scenario->reference} created.");
    }

    /**
     * The workspace (spec 01, 02, 03).
     *
     * ONLY THE ACTIVE TAB'S PAYLOAD SHIPS (spec 01 §4): the Qualitative payload — the
     * 23-code checklist plus every business-unit option plus the allocation lines — is the
     * largest thing on the page, and a user landing on Overview at 100 kbps should not pay
     * for it. `qualitativeSummary` is the one exception, because the sign-off modal is
     * shell-level and can be opened from either tab.
     */
    public function show(Request $request, Scenario $scenario, ScenarioWorkspacePresenter $presenter): Response
    {
        Gate::authorize('view', $scenario);

        $requested = (string) $request->query('tab', 'overview');
        $tab = in_array($requested, self::TABS, true) ? $requested : 'overview';

        // Spec 01 §5.1: an unknown or deferred `?tab=` is IGNORED and resolves to overview,
        // so a bookmark from a later phase or a hand-typed URL lands somewhere useful rather
        // than 404ing. When what was asked for was not overview, the shell says so once —
        // silently showing a different section is how a user concludes the link is broken.
        //
        // `now()`, not `flash()`: this is a page render rather than a redirect, so the message
        // belongs to THIS response only. `flash()` would carry it into the next request too and
        // the user would read the same sentence twice.
        if ($tab !== $requested && $requested !== '' && $requested !== 'overview') {
            session()->now('info', "That section isn't part of this release yet. Showing Overview & Governance.");
        }

        $notesBefore = $request->query('notes_before');

        return Inertia::render('Sm/Scenarios/Workspace', $presenter->present(
            $scenario,
            $request->user(),
            $tab,
            is_scalar($notesBefore) && $notesBefore !== '' ? (int) $notesBefore : null
        ));
    }

    /**
     * Correction 1 §C1.4: a REDIRECT, with no screen behind it.
     *
     * The workspace is tabbed and `scenario.show` already carries `?tab=`, so a separate edit
     * page would be a second rendering of one form with its own validation path — the
     * divergence risk standard §10 warns about, since no test in this repository executes
     * JavaScript. The route STAYS REGISTERED rather than being dropped:
     * `RouteAuthorizationTest` and `PermissionCatalogCoversRoutesTest` continue to see it,
     * `permission:scenario.update` stays declared against a real route, and a bookmark or an
     * emailed `/edit` link resolves instead of 404ing.
     */
    public function edit(Request $request, Scenario $scenario): RedirectResponse
    {
        Gate::authorize('update', $scenario);

        $tab = (string) $request->query('tab', 'overview');

        return to_route('scenario.show', [
            'scenario' => $scenario,
            'tab' => in_array($tab, self::TABS, true) ? $tab : 'overview',
        ]);
    }

    /**
     * The PARTIAL update both tabs post to (spec 01 §6.1).
     *
     * Only the keys the Form Request validated are written, so a Qualitative-shaped save
     * cannot null `background`, `rationale` or any governance field. Standard §6's
     * write-path rule in its most dangerous form: the columns exist, the write "succeeds"
     * with a 200, and the data is gone.
     */
    public function update(UpdateScenarioRequest $request, Scenario $scenario): RedirectResponse
    {
        try {
            $this->scenarios->update($scenario, $request->scenarioAttributes(), $request->user());
        } catch (ScenarioRefused $e) {
            return back()->with('error', $e->flash());
        }

        return back()->with('success', 'Saved.');
    }

    /**
     * The Qualitative tab's causes (spec 03 §5.6 — save region one of three).
     *
     * `side` and `cause_group` are DERIVED FROM `code` by `CauseService` and never accepted
     * from the request: a client posting them is how a row arrives `side = external` with
     * `code = people_other` (§A1.4).
     */
    public function updateCauses(UpdateScenarioCausesRequest $request, Scenario $scenario): RedirectResponse
    {
        try {
            $this->scenarios->refuseIfReadOnly($scenario);
            $this->causes->sync($scenario, $request->causes());
        } catch (ScenarioRefused $e) {
            return back()->with('error', $e->flash());
        }

        return back()->with('success', 'Saved.');
    }

    /**
     * The Qualitative tab's allocation (spec 03 §5.6 — save region two of three).
     *
     * THE SUM-TO-100 RULE IS DELIBERATELY NOT ENFORCED HERE. §A1.5 puts it at the
     * `in_design → designed` TRANSITION "and not on every draft save — a draft legitimately
     * sums to 60% mid-entry, and refusing to save it pushes users into one big form".
     * Patched spec 03 §5.2 is explicit that saving 60% must SUCCEED and that "a screen that
     * refused to save 60% would be enforcing a stricter rule than the server has". The 422 on
     * key `allocations` is produced by `TransitionScenarioRequest`.
     */
    public function updateAllocations(UpdateScenarioAllocationsRequest $request, Scenario $scenario): RedirectResponse
    {
        try {
            $this->scenarios->refuseIfReadOnly($scenario);
            $this->allocations->sync($scenario, $request->allocationLines(), $request->user()->id);
        } catch (ScenarioRefused $e) {
            return back()->with('error', $e->flash());
        }

        return back()->with('success', 'Saved.');
    }

    /**
     * Update COSO Control Scorecard (spec 06, ADR 0027 Amendment 2 §A2.2).
     */
    public function updateScorecard(UpdateScorecardRequest $request, Scenario $scenario): RedirectResponse
    {
        try {
            $this->quantitativeService->updateScorecard($scenario, $request->validated(), $request->user());
        } catch (ScenarioRefused $e) {
            return back()->with('error', $e->flash());
        }

        return back()->with('success', 'Scorecard saved.');
    }

    /**
     * Update Frequency Drivers (spec 07, ADR 0027 Amendment 2 §A2.3).
     */
    public function updateFrequency(UpdateFrequencyRequest $request, Scenario $scenario): RedirectResponse
    {
        try {
            $this->quantitativeService->updateFrequency($scenario, $request->validated()['drivers'], $request->user());
        } catch (ScenarioRefused $e) {
            return back()->with('error', $e->flash());
        }

        return back()->with('success', 'Frequency drivers saved.');
    }

    /**
     * Update Impact Categories and Criteria (spec 08, ADR 0027 Amendment 2 §A2.4).
     */
    public function updateImpact(UpdateImpactRequest $request, Scenario $scenario): RedirectResponse
    {
        try {
            $this->quantitativeService->updateImpact($scenario, $request->validated()['types'] ?? [], $request->user());
        } catch (ScenarioRefused $e) {
            return back()->with('error', $e->flash());
        }

        return back()->with('success', 'Impact assessment saved.');
    }

    /**
     * Update Insurance Netting Terms (spec 09, ADR 0027 Amendment 2 §A2.5).
     */
    public function updateInsurance(UpdateInsuranceRequest $request, Scenario $scenario): RedirectResponse
    {
        try {
            $this->quantitativeService->updateInsurance($scenario, $request->validated()['coverages'], $request->user());
        } catch (ScenarioRefused $e) {
            return back()->with('error', $e->flash());
        }

        return back()->with('success', 'Insurance terms saved.');
    }

    /**
     * Save the severe-but-plausible variant deck (spec 10, ADR 0027 Amendment 3 §A3.2).
     */
    public function updateVariants(UpdateVariantsRequest $request, Scenario $scenario): RedirectResponse
    {
        Gate::authorize('update', $scenario);

        try {
            $this->variantService->saveVariants(
                $scenario,
                $request->validated('variants'),
                $request->user()
            );
        } catch (ScenarioRefused $e) {
            return back()->with('error', $e->flash());
        }

        return back()->with('success', 'Scenario variants saved.');
    }

    /**
     * Executive Presentation Deck View (spec 12, ADR 0027 Amendment 3 §A3.5).
     */
    public function deck(Request $request, Scenario $scenario, ScenarioWorkspacePresenter $presenter): Response
    {
        Gate::authorize('view', $scenario);

        return Inertia::render('Sm/Scenarios/DeckView', $presenter->presentDeck(
            $scenario,
            $request->user()
        ));
    }

    /**
     * The linked-risk type-ahead (spec 02 §5.7) — an ON-DEMAND ENDPOINT, not a shipped list.
     *
     * "A register is thousands of rows and shipping it would cost 60 KB+ — five seconds at
     * 100 kbps for a list most users never open." Minimum two characters, capped at twenty
     * results, and nothing is fetched until the user types.
     *
     * TENANCY AND NODE SCOPE BOTH APPLY. `visibleTo()` is the WP-00 rule
     * `NodeScopeEnforcementTest` exists to enforce: a user pinned to a branch sees their
     * subtree and not a sibling's, and the register is the sharpest case for it because a row
     * here names an exposure. `url` is null for a caller without `risk.view`, so the combobox
     * renders the reference as plain text rather than a link that 403s (spec 02 §5.7).
     */
    public function riskSearch(SearchRisksRequest $request): JsonResponse
    {
        $user = $request->user();
        $term = $request->term();

        $risks = Risk::query()
            ->visibleTo($user)
            ->where(function ($query) use ($term) {
                // Escaped, on the house pattern (`QueryShaper`: `addcslashes($operand, '%_\\')`):
                // a term is a literal substring to match, not a pattern, so a `%` typed into a
                // type-ahead must not turn into a table scan over every row that happens to
                // contain anything.
                $escaped = addcslashes($term, '%_\\');

                $query->where('risk_code', 'like', "%{$escaped}%")
                    ->orWhere('title', 'like', "%{$escaped}%");
            })
            ->orderBy('risk_code')
            ->limit(SearchRisksRequest::RESULT_CAP)
            ->get(['id', 'risk_code', 'title']);

        $canOpen = $user->can('risk.view');

        return response()->json([
            'data' => $risks->map(fn (Risk $risk) => [
                'id' => (int) $risk->id,
                'risk_code' => (string) $risk->risk_code,
                'title' => (string) $risk->title,
                'url' => $canOpen ? route('risk.register.show', $risk) : null,
            ])->all(),
            // True when the cap was reached, so the screen can say "showing the first 20"
            // rather than implying the register has no more matches. Not specced; harmless
            // and cheaper than a second count query. [verify at integration]
            'capped' => $risks->count() >= SearchRisksRequest::RESULT_CAP,
        ]);
    }
}
