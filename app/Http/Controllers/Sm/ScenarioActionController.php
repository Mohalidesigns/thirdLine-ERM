<?php

namespace App\Http\Controllers\Sm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sm\StoreScenarioActionRequest;
use App\Http\Requests\Sm\UpdateScenarioActionRequest;
use App\Models\Sm\Scenario;
use App\Models\Sm\ScenarioAction;
use App\Presenters\Sm\ScenarioWorkspacePresenter;
use App\Services\Sm\ScenarioActionService;
use App\Services\Sm\ScenarioRefused;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for Scenario Actions, Action Tracker, and Issue Promotion (ADR 0027 Amendment 3 §A3.5).
 */
class ScenarioActionController extends Controller
{
    public function __construct(
        private readonly ScenarioActionService $actionService
    ) {}

    /**
     * Enterprise Action Tracker across all scenarios (spec 13).
     */
    public function index(Request $request, ScenarioWorkspacePresenter $presenter): Response
    {
        Gate::authorize('viewAny', Scenario::class);

        $orgId = (int) $request->user()->organization_id;

        return Inertia::render('Sm/Actions/Index', $presenter->presentActionTracker($request, $orgId));
    }

    /**
     * Store a new scenario action.
     */
    public function store(StoreScenarioActionRequest $request, Scenario $scenario): RedirectResponse
    {
        Gate::authorize('manageActions', $scenario);

        try {
            $this->actionService->createAction(
                $scenario,
                $request->validated(),
                $request->user()
            );
        } catch (ScenarioRefused $e) {
            return back()->with('error', $e->flash());
        }

        return back()->with('success', 'Scenario action created.');
    }

    /**
     * Update an existing scenario action.
     */
    public function update(UpdateScenarioActionRequest $request, Scenario $scenario, ScenarioAction $action): RedirectResponse
    {
        Gate::authorize('manageActions', $scenario);

        if ((int) $action->sm_scenario_id !== (int) $scenario->id) {
            abort(404);
        }

        try {
            $this->actionService->updateAction(
                $action,
                $request->validated(),
                $request->user()
            );
        } catch (ScenarioRefused $e) {
            return back()->with('error', $e->flash());
        }

        return back()->with('success', 'Scenario action updated.');
    }

    /**
     * Delete an existing scenario action.
     */
    public function destroy(Request $request, Scenario $scenario, ScenarioAction $action): RedirectResponse
    {
        Gate::authorize('manageActions', $scenario);

        if ((int) $action->sm_scenario_id !== (int) $scenario->id) {
            abort(404);
        }

        try {
            $this->actionService->deleteAction($action, $request->user());
        } catch (ScenarioRefused $e) {
            return back()->with('error', $e->flash());
        }

        return back()->with('success', 'Scenario action deleted.');
    }

    /**
     * Promote an action to an enterprise Issue.
     */
    public function promote(Request $request, Scenario $scenario, ScenarioAction $action): RedirectResponse
    {
        Gate::authorize('manageActions', $scenario);

        if ((int) $action->sm_scenario_id !== (int) $scenario->id) {
            abort(404);
        }

        try {
            $issue = $this->actionService->promoteToIssue($action, $request->user());
        } catch (ScenarioRefused $e) {
            return back()->with('error', $e->flash());
        }

        return back()->with('success', "Action promoted to enterprise Issue {$issue->issue_reference}.");
    }
}
