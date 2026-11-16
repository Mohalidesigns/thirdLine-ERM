<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Http\Requests\IncidentRequest;
use App\Models\BusinessProcess;
use App\Models\BusinessUnit;
use App\Models\Control;
use App\Models\Incident;
use App\Models\IncidentAction;
use App\Models\IncidentNotification;
use App\Models\Risk;
use App\Models\User;
use App\Services\IncidentService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IncidentController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private IncidentService $incidents,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Incident::class);

        $query = Incident::query()
            ->with(['owner:id,name', 'businessUnit:id,name', 'reporter:id,name'])
            ->withCount([
                'actions',
                'actions as open_actions_count' => fn ($q) => $q->open(),
                'notifications as outstanding_notifications_count' => fn ($q) => $q->outstanding(),
            ])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->severity, fn ($q, $s) => $q->where('severity', $s))
            ->when($request->incident_type, fn ($q, $t) => $q->where('incident_type', $t))
            ->when($request->business_unit_id ?? $request->entity_id, fn ($q, $e) => $q->where('business_unit_id', $e))
            ->when($request->boolean('near_miss_only'), fn ($q) => $q->where('near_miss', true))
            ->when($request->boolean('reportable_only'), fn ($q) => $q->reportable())
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('title', 'like', "%{$s}%")
                ->orWhere('incident_ref', 'like', "%{$s}%")));

        return Inertia::render('Incidents/Index', [
            'incidents' => $query->orderByDesc('occurred_at')->orderByDesc('id')->paginate(15)->withQueryString(),
            'filters' => $request->only([
                'status', 'severity', 'incident_type', 'business_unit_id', 'entity_id', 'near_miss_only', 'reportable_only', 'search',
            ]),
            'statuses' => Incident::STATUSES,
            'severities' => Incident::SEVERITIES,
            'types' => Incident::TYPES,
            'entities' => BusinessUnit::orderBy('name')->get(['id', 'name']),
            'stats' => [
                'open' => Incident::open()->count(),
                'near_misses' => Incident::where('near_miss', true)->count(),
                'notification_due' => IncidentNotification::outstanding()->count(),
                'notification_breached' => IncidentNotification::outstanding()
                    ->whereNotNull('due_at')->where('due_at', '<', now())->count(),
            ],
            'lossByBaselEventType' => $this->incidents->lossByBaselEventType(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Incident::class);

        return Inertia::render('Incidents/Create', [
            'types' => Incident::TYPES,
            'baselEventTypes' => Incident::BASEL_EVENT_TYPES,
            'severities' => Incident::SEVERITIES,
            'entities' => BusinessUnit::orderBy('name')->get(['id', 'name']),
            'processes' => BusinessProcess::orderBy('name')->get(['id', 'name']),
            'users' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'controls' => Control::orderBy('control_code')->get(['id', 'control_code as control_ref', 'name as title']),
            'risks' => Risk::orderBy('risk_code')->get(['id', 'risk_code as code', 'title']),
        ]);
    }

    public function store(IncidentRequest $request): RedirectResponse
    {
        $this->authorize('create', Incident::class);

        $incident = $this->incidents->report($request->validated(), $request->user());

        return redirect()
            ->route('risk.incidents.show', $incident)
            ->with('success', "Incident {$incident->incident_ref} recorded.");
    }

    public function show(Request $request, Incident $incident): Response
    {
        $this->authorize('view', $incident);

        $incident->load([
            'owner:id,name', 'investigator:id,name', 'reporter:id,name', 'closer:id,name',
            'businessUnit:id,name', 'process:id,name',
            'actions.owner:id,name', 'actions.verifier:id,name',
            'timeline.actor:id,name',
            'notifications.notifier:id,name',
        ]);

        $linkedControls = Control::whereIn('id', $incident->control_failure_ids ?? [])
            ->get(['id', 'control_code as control_ref', 'name as title']);
        $linkedRisks = Risk::whereIn('id', $incident->risk_ids ?? [])
            ->get(['id', 'risk_code as code', 'title']);

        return Inertia::render('Incidents/Show', [
            'incident' => $incident,
            'linkedControls' => $linkedControls,
            'linkedRisks' => $linkedRisks,
            'users' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'controls' => Control::orderBy('control_code')->get(['id', 'control_code as control_ref', 'name as title']),
            'actionTypes' => IncidentAction::TYPES,
            'can' => [
                'update' => $request->user()->can('update', $incident),
                'triage' => $request->user()->can('triage', $incident),
                'investigate' => $request->user()->can('investigate', $incident),
                'close' => $request->user()->can('close', $incident),
                'reopen' => $request->user()->can('reopen', $incident),
                'notify' => $request->user()->can('notify', $incident),
            ],
        ]);
    }

    public function update(IncidentRequest $request, Incident $incident): RedirectResponse
    {
        $this->authorize('update', $incident);

        $incident->update(collect($request->validated())->except([
            'gross_loss_minor', 'recovery_minor', 'currency', 'control_failure_ids', 'risk_ids',
        ])->all());

        $this->incidents->refreshNotifications($incident->fresh());

        return back()->with('success', 'Incident updated.');
    }

    public function triage(Request $request, Incident $incident): RedirectResponse
    {
        $this->authorize('triage', $incident);

        $validated = $request->validate([
            'severity' => ['required', 'in:'.implode(',', Incident::SEVERITIES)],
            'incident_type' => ['required', 'in:'.implode(',', Incident::TYPES)],
            'basel_event_type' => ['nullable', 'in:'.implode(',', Incident::BASEL_EVENT_TYPES)],
            'owner_id' => ['nullable', 'exists:users,id'],
            'investigator_id' => ['nullable', 'exists:users,id'],
            'business_unit_id' => ['nullable', 'exists:business_units,id'],
            'entity_id' => ['nullable', 'exists:business_units,id'],
            'process_id' => ['nullable', 'exists:business_processes,id'],
            'near_miss' => ['nullable', 'boolean'],
        ]);

        if (isset($validated['entity_id']) && ! isset($validated['business_unit_id'])) {
            $validated['business_unit_id'] = $validated['entity_id'];
        }

        $this->incidents->triage($incident, $validated, $request->user());

        return back()->with('success', 'Incident triaged.');
    }

    public function progress(Request $request, Incident $incident): RedirectResponse
    {
        $this->authorize('investigate', $incident);

        $validated = $request->validate([
            'action' => ['required', 'in:investigate,contain,remediate'],
            'note' => ['required_if:action,contain', 'nullable', 'string', 'max:2000'],
            'root_cause' => ['nullable', 'string', 'max:4000'],
            'root_cause_rich' => ['nullable'],
            'root_cause_category' => ['nullable', 'string', 'max:120'],
            'contributing_factors' => ['nullable', 'array'],
            'contributing_factors.*' => ['string', 'max:255'],
            'lessons_learned' => ['nullable', 'string', 'max:4000'],
            'lessons_learned_rich' => ['nullable'],
        ]);

        match ($validated['action']) {
            'investigate' => $this->incidents->startInvestigation($incident, $request->user()),
            'contain' => $this->incidents->contain($incident, $request->user(), $validated['note'] ?? ''),
            default => $this->incidents->markRemediated($incident, $request->user(), $validated),
        };

        return back()->with('success', 'Incident updated.');
    }

    public function recordLoss(Request $request, Incident $incident): RedirectResponse
    {
        $this->authorize('update', $incident);

        $validated = $request->validate([
            'currency' => ['required', 'string', 'size:3'],
            'gross_loss_minor' => ['required', 'integer', 'min:0'],
            'recovery_minor' => ['nullable', 'integer', 'min:0'],
        ]);

        $this->incidents->recordLoss($incident, $validated);

        return back()->with('success', 'Loss recorded.');
    }

    public function close(Request $request, Incident $incident): RedirectResponse
    {
        $this->authorize('close', $incident);

        $validated = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        $this->incidents->close($incident, $request->user(), $validated['note'] ?? null);

        return back()->with('success', 'Incident closed.');
    }

    public function reopen(Request $request, Incident $incident): RedirectResponse
    {
        $this->authorize('reopen', $incident);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        $this->incidents->reopen($incident, $request->user(), $validated['reason']);

        return back()->with('success', 'Incident reopened.');
    }

    // ── Actions ──────────────────────────────────────────────────────────

    public function storeAction(Request $request, Incident $incident): RedirectResponse
    {
        $this->authorize('update', $incident);

        $validated = $request->validate([
            'action_type' => ['required', 'in:'.implode(',', IncidentAction::TYPES)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:4000'],
            'owner_id' => ['nullable', 'exists:users,id'],
            'due_at' => ['nullable', 'date'],
            'is_mandatory' => ['nullable', 'boolean'],
        ]);

        $this->incidents->addAction($incident, $validated);

        return back()->with('success', 'Action raised.');
    }

    public function updateAction(Request $request, Incident $incident, IncidentAction $action): RedirectResponse
    {
        $this->authorize('update', $incident);
        abort_unless($action->incident_id === $incident->id, 404);

        $validated = $request->validate([
            'action' => ['required', 'in:complete,verify,cancel'],
            'evidence_id' => ['nullable', 'integer'],
            'reason' => ['required_if:action,cancel', 'nullable', 'string', 'max:1000'],
        ]);

        match ($validated['action']) {
            'complete' => $this->incidents->completeAction($action, $request->user(), $validated['evidence_id'] ?? null),
            'verify' => $this->incidents->verifyAction($action, $request->user()),
            default => $this->incidents->cancelAction($action, $request->user(), $validated['reason'] ?? ''),
        };

        return back()->with('success', 'Action updated.');
    }

    // ── Regulatory notification ──────────────────────────────────────────

    public function refreshNotifications(Incident $incident): RedirectResponse
    {
        $this->authorize('update', $incident);

        $this->incidents->refreshNotifications($incident);

        return back()->with('success', 'Notification obligations recomputed.');
    }

    public function recordNotification(Request $request, Incident $incident, IncidentNotification $notification): RedirectResponse
    {
        $this->authorize('notify', $incident);
        abort_unless($notification->incident_id === $incident->id, 404);

        $validated = $request->validate([
            'notification_reference' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->incidents->recordNotification(
            $notification,
            $request->user(),
            $validated['notification_reference'],
            $validated['notes'] ?? null,
        );

        return back()->with('success', 'Regulatory notification recorded.');
    }

    public function waiveNotification(Request $request, Incident $incident, IncidentNotification $notification): RedirectResponse
    {
        $this->authorize('notify', $incident);
        abort_unless($notification->incident_id === $incident->id, 404);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        $this->incidents->waiveNotification($notification, $request->user(), $validated['reason']);

        return back()->with('success', 'Notification stood down with a recorded reason.');
    }

    // ── Linkage ──────────────────────────────────────────────────────────

    public function linkControls(Request $request, Incident $incident): RedirectResponse
    {
        $this->authorize('update', $incident);

        $validated = $request->validate([
            'control_ids' => ['required', 'array', 'max:50'],
            'control_ids.*' => ['integer', 'exists:controls,id'],
        ]);

        $linked = $this->incidents->linkControlFailures($incident, $validated['control_ids']);

        return back()->with('success', "{$linked} control failure(s) linked.");
    }
}
