<?php

namespace App\Http\Controllers\Bcms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bcms\ClassifyBcmsIncidentRequest;
use App\Http\Requests\Bcms\DeclareBcmsIncidentRequest;
use App\Http\Requests\Bcms\RegradeBcmsIncidentRequest;
use App\Http\Requests\Bcms\StandDownBcmsIncidentRequest;
use App\Http\Requests\Bcms\StoreBcmsIncidentLogRequest;
use App\Http\Requests\Bcms\StoreBcmsIncidentTaskRequest;
use App\Models\Bcms\Incident;
use App\Models\Bcms\IncidentTask;
use App\Presenters\Bcms\IncidentPresenter;
use App\Services\Bcms\Incidents\IncidentService;
use App\Services\Bcms\Incidents\NotificationService;
use App\Support\Bcms\IncidentClock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Declaration, the crisis room and stand-down — `plans/bcms/prompts/
 * PHASE-10-incident-crisis-itdr.md`, `docs/bcms/screens/{incident-
 * declaration,crisis-room,incident-stand-down}.md`.
 *
 * A CONTROLLER RESOLVES, AUTHORISES AND DELEGATES (standard §1). Every
 * computed fact on these three screens — the severity suggestion, the
 * countdown tiles, the closure checklist — is `IncidentPresenter`'s and
 * `IncidentService`'s, never assembled here.
 */
class IncidentController extends Controller
{
    public function __construct(
        private IncidentService $incidents,
        private NotificationService $notifications,
        private IncidentPresenter $presenter,
    ) {}

    public function index(): Response
    {
        Gate::authorize('bcms.incident.view');

        return Inertia::render('Bcms/Incidents/Index', $this->presenter->index());
    }

    public function declareForm(): Response
    {
        Gate::authorize('bcms.incident.declare');

        return Inertia::render('Bcms/Incidents/Declare', $this->presenter->declareForm());
    }

    public function store(DeclareBcmsIncidentRequest $request): RedirectResponse
    {
        try {
            $incident = $this->incidents->declare($request->validated(), $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bcms.incidents.crisis-room', $incident)
            ->with('success', "Incident {$incident->reference} declared.");
    }

    public function crisisRoom(Incident $incident): Response
    {
        Gate::authorize('bcms.incident.view');

        return Inertia::render('Bcms/Incidents/CrisisRoom', $this->presenter->crisisRoom($incident));
    }

    /**
     * The crisis room's 5-second poll. A FIXED, SMALL SHAPE, never the whole
     * `crisisRoom()` payload — see `IncidentPresenter::liveMetrics()` for the
     * exact contract (Gate 2 review #1 defect 10).
     */
    public function liveMetrics(Incident $incident): \Illuminate\Http\JsonResponse
    {
        Gate::authorize('bcms.incident.view');

        return response()->json($this->presenter->liveMetrics($incident));
    }

    public function storeLog(StoreBcmsIncidentLogRequest $request, Incident $incident): RedirectResponse
    {
        try {
            // Gate 1 re-gate defect 3: a closed/cancelled incident does not
            // gain new manual log entries (crisis-room.md §3, incident-
            // stand-down.md "Already closed").
            $this->incidents->assertNotTerminal($incident);
            $this->incidents->log($incident, $request->user(), $request->validated());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Logged.');
    }

    public function storeTask(StoreBcmsIncidentTaskRequest $request, Incident $incident): RedirectResponse
    {
        try {
            $this->incidents->addTask($incident, $request->validated());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Task added.');
    }

    public function completeTask(Incident $incident, IncidentTask $task): RedirectResponse
    {
        Gate::authorize('bcms.incident.manage');

        try {
            $this->incidents->completeTask($task);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Task completed.');
    }

    public function classify(ClassifyBcmsIncidentRequest $request, Incident $incident): RedirectResponse
    {
        $data = $request->validated();

        try {
            // Gate 1 re-gate defect 3: a closed/cancelled incident's
            // reportability is settled by stand-down; it does not reopen.
            $this->incidents->assertNotTerminal($incident);

            if ($data['answer'] === 'yes') {
                $regulator = $data['question'] === 'personal_data' ? 'ndpc' : 'cbn';
                $this->notifications->classify(
                    $incident,
                    $request->user(),
                    $regulator,
                    isset($data['awareness_at']) ? IncidentClock::utc($data['awareness_at']) : null,
                    $data['awareness_reason'] ?? null,
                );
            } elseif ($data['answer'] === 'no') {
                // ADR 0020 Amendment 2 rule 2: withdrawing a LIVE obligation
                // needs `bcms.incident.notify`, not only `.manage` — the
                // request's own `authorize()` cannot decide this without
                // querying the obligation itself, so the check sits here,
                // scoped to the one branch that can actually withdraw a row.
                // "No, nothing was ever open" stays on `.manage` alone.
                if ($this->notifications->hasLiveObligation($incident, $data['question'])) {
                    Gate::authorize('bcms.incident.notify');
                }

                $this->notifications->reassessNotReportable($incident, $request->user(), $data['question'], $data['reason']);
            }
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Reportability recorded.');
    }

    public function regrade(RegradeBcmsIncidentRequest $request, Incident $incident): RedirectResponse
    {
        $data = $request->validated();

        try {
            $this->incidents->regrade($incident, $request->user(), $data['severity'], $data['reason'], $data['activation_level'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Severity updated.');
    }

    public function standDownForm(Incident $incident): Response
    {
        Gate::authorize('bcms.incident.manage');

        return Inertia::render('Bcms/Incidents/StandDown', $this->presenter->standDown($incident));
    }

    public function standDown(StandDownBcmsIncidentRequest $request, Incident $incident): RedirectResponse
    {
        try {
            $this->incidents->standDown($incident, $request->user(), $request->validated());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bcms.incidents.crisis-room', $incident)
            ->with('success', 'Incident stood down.');
    }

    /**
     * `dr-failover-failback-record.md` — read-only. No store route exists for
     * this screen (ADR 0020 §4): nothing here is ever written to
     * `bcms_dr_tests`. Gated on the CONJUNCTION of `bcms.incident.view` and
     * `bcms.dr.view` per the spec's own permission note (§1) — a user who
     * cannot see the DR register should not see its systems' invocation
     * evidence either, even though they can see this incident.
     */
    public function drInvocation(Incident $incident): Response
    {
        Gate::authorize('bcms.incident.view');
        Gate::authorize('bcms.dr.view');

        return Inertia::render('Bcms/Incidents/DrInvocation', $this->presenter->drInvocation($incident));
    }
}
