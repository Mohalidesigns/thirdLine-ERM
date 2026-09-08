<?php

namespace App\Http\Controllers\Bcms;

use App\Enums\Bcms\CorrectiveActionStatus;
use App\Enums\Bcms\FindingClassification;
use App\Enums\Bcms\FindingSource;
use App\Enums\Bcms\IsoClauseRef;
use App\Http\Controllers\Controller;
use App\Models\Bcms\CorrectiveAction;
use App\Models\Bcms\Finding;
use App\Models\Bcms\Plan;
use App\Models\Bcms\Process;
use App\Models\User;
use App\Services\Bcms\Findings\CorrectiveActionService;
use App\Services\Bcms\Findings\FindingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The findings and corrective-action register — the cross-track contract of
 * Orchestration §5, and the screen that makes it usable.
 *
 * FILTERABLE BY OWNER, STATUS, SOURCE AND DUE DATE, with overdue highlighted.
 * That list is not decoration: the register's only job is to make an action
 * somebody has to take today findable by the person who has to take it, and a
 * register sorted by creation date buries it under everything already done.
 *
 * VERIFICATION IS A DIFFERENT PERMISSION FROM MANAGEMENT. Clause 10.1 asks
 * whether the action worked, which the person who did it cannot answer about
 * themselves — so `bcms.finding.verify` is separate, and the service refuses the
 * owner by name even if somebody holds both.
 */
class FindingController extends Controller
{
    public function __construct(
        private FindingService $findings,
        private CorrectiveActionService $actions,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('bcms.finding.view');

        $filters = $request->only(['status', 'classification', 'source', 'owner', 'due', 'search']);

        $findings = Finding::query()
            ->with(['correctiveActions.owner:id,name', 'affectedProcess:id,code,name', 'affectedPlan:id,title'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['classification'] ?? null, fn ($q, $v) => $q->where('classification', $v))
            ->when($filters['source'] ?? null, fn ($q, $v) => $q->where('source', $v))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(
                fn ($i) => $i->where('description', 'like', "%{$v}%")->orWhere('reference', 'like', "%{$v}%")
            ))
            ->when($filters['owner'] ?? null, fn ($q, $v) => $q->whereHas('correctiveActions', fn ($i) => $i->where('owner_id', $v)))
            ->when(($filters['due'] ?? null) === 'overdue', fn ($q) => $q->whereHas(
                'correctiveActions',
                fn ($i) => $i->where('status', CorrectiveActionStatus::Overdue->value)
                    ->orWhere(fn ($j) => $j->whereIn('status', ['open', 'in_progress'])
                        ->whereNotNull('due_date')->whereDate('due_date', '<', now()->toDateString()))
            ))
            ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'in_progress' THEN 1 ELSE 2 END")
            ->orderByDesc('id')
            ->paginate(40)
            ->withQueryString();

        return Inertia::render('Bcms/Findings/Index', [
            'findings' => $findings->through(fn (Finding $f) => $this->shape($f)),
            'filters' => $filters,
            'summary' => $this->summary(),
            'options' => [
                'classifications' => array_map(fn (FindingClassification $c) => [
                    'value' => $c->value,
                    'label' => ucfirst(str_replace('_', ' ', $c->value)),
                    'requires_action' => $c->requiresCorrectiveAction(),
                ], FindingClassification::cases()),
                'sources' => array_map(fn (FindingSource $s) => [
                    'value' => $s->value, 'label' => $s->label(),
                ], FindingSource::cases()),
                'clause_refs' => array_map(fn (IsoClauseRef $c) => [
                    'value' => $c->value, 'standard' => $c->standard(),
                ], IsoClauseRef::cases()),
                'processes' => Process::query()->orderBy('code')->get(['id', 'code', 'name']),
                'plans' => Plan::query()->orderBy('title')->get(['id', 'title']),
                'users' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            ],
            'can' => [
                'manage' => $request->user()?->can('bcms.finding.manage') === true,
                'verify' => $request->user()?->can('bcms.finding.verify') === true,
                'accept_risk' => $request->user()?->can('bcms.finding.accept_risk') === true,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('bcms.finding.manage');

        $data = $request->validate([
            'source' => ['required', 'string', 'in:'.implode(',', array_column(FindingSource::cases(), 'value'))],
            'classification' => ['required', 'in:observation,improvement,nonconformity'],
            'description' => ['required', 'string', 'max:5000'],
            'severity' => ['nullable', 'in:low,medium,high,critical'],
            'root_cause' => ['nullable', 'string', 'max:5000'],
            'iso_clause_ref' => ['nullable', 'string', 'in:'.implode(',', IsoClauseRef::values())],
            'affected_process_id' => ['nullable', 'integer', 'exists:bcms_processes,id'],
            'affected_plan_id' => ['nullable', 'integer', 'exists:bcms_plans,id'],
        ]);

        try {
            $finding = $this->findings->raise(
                FindingSource::from($data['source']),
                FindingClassification::from($data['classification']),
                $data['description'],
                null,
                array_intersect_key($data, array_flip(['severity', 'root_cause', 'iso_clause_ref', 'affected_process_id', 'affected_plan_id'])),
                $request->user()?->getKey(),
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Finding {$finding->reference} raised.");
    }

    public function close(Request $request, Finding $finding): RedirectResponse
    {
        Gate::authorize('bcms.finding.manage');

        try {
            $this->findings->close($finding, $request->user()?->getKey());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Finding {$finding->reference} closed.");
    }

    public function acceptRisk(Request $request, Finding $finding): RedirectResponse
    {
        Gate::authorize('bcms.finding.accept_risk');

        $data = $request->validate(['rationale' => ['required', 'string', 'max:2000']]);

        $this->findings->acceptRisk($finding, $data['rationale'], $request->user()?->getKey());

        return back()->with('success', "Risk accepted on {$finding->reference}.");
    }

    /* ------------------------------------------------------------------ */
    /*  Corrective actions */
    /* ------------------------------------------------------------------ */

    public function storeAction(Request $request, Finding $finding): RedirectResponse
    {
        Gate::authorize('bcms.finding.manage');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:250'],
            'description' => ['nullable', 'string', 'max:5000'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'due_date' => ['nullable', 'date'],
            'priority' => ['nullable', 'in:low,medium,high,critical'],
        ]);

        $action = $this->actions->create($finding, $data['title'], $data, $request->user()?->getKey());

        return back()->with('success', "Corrective action {$action->reference} raised.");
    }

    public function completeAction(Request $request, CorrectiveAction $action): RedirectResponse
    {
        Gate::authorize('bcms.finding.manage');

        try {
            $this->actions->complete($action, $request->user()?->getKey());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Marked complete. It still needs verifying by somebody else.');
    }

    public function verifyAction(Request $request, CorrectiveAction $action): RedirectResponse
    {
        Gate::authorize('bcms.finding.verify');

        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        try {
            $this->actions->verify($action, (int) $request->user()->getKey(), $data['note'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Corrective action {$action->reference} verified.");
    }

    public function acceptAction(Request $request, CorrectiveAction $action): RedirectResponse
    {
        Gate::authorize('bcms.finding.accept_risk');

        $data = $request->validate([
            'rationale' => ['required', 'string', 'max:2000'],
            'expires_on' => ['nullable', 'date', 'after:today'],
        ]);

        $this->actions->acceptRisk($action, $data['rationale'], (int) $request->user()->getKey(), $data['expires_on'] ?? null);

        return back()->with('success', 'Risk accepted. It reopens automatically when the acceptance lapses.');
    }

    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private function shape(Finding $finding): array
    {
        return [
            'id' => $finding->getKey(),
            'uuid' => $finding->uuid,
            'reference' => $finding->reference,
            'source' => $finding->source?->value,
            'source_label' => $finding->source?->label(),
            'classification' => $finding->classification?->value,
            'severity' => $finding->severity,
            'description' => $finding->description,
            'root_cause' => $finding->root_cause,
            'iso_clause_ref' => $finding->iso_clause_ref,
            'status' => $finding->status,
            'raised_at' => $finding->raised_at?->toDateString(),
            'process' => $finding->affectedProcess?->name,
            'plan' => $finding->affectedPlan?->title,
            'erm_issue_id' => $finding->erm_issue_id,
            'actions' => $finding->correctiveActions->map(fn (CorrectiveAction $a) => [
                'id' => $a->getKey(),
                // The route key. Corrective actions are addressed by uuid, and
                // a screen that builds a URL from the numeric id links to a 404.
                'uuid' => $a->uuid,
                'reference' => $a->reference,
                'title' => $a->title,
                'owner' => $a->owner?->name,
                'owner_id' => $a->owner_id,
                'due_date' => $a->due_date?->toDateString(),
                'status' => $a->status?->value,
                'priority' => $a->priority,
                'is_overdue' => $a->status === CorrectiveActionStatus::Overdue
                    || ($a->due_date !== null && $a->status?->isOpen() === true && $a->due_date->isPast()),
                'verified_at' => $a->verified_at?->toDateString(),
                'carried_to_occurrence_id' => $a->carried_to_occurrence_id,
            ])->values()->all(),
        ];
    }

    /** @return array<string, int> */
    private function summary(): array
    {
        return [
            'open' => Finding::query()->where('status', 'open')->count(),
            'nonconformities_open' => Finding::query()->where('status', 'open')
                ->where('classification', FindingClassification::Nonconformity->value)->count(),
            'actions_open' => CorrectiveAction::query()->whereIn('status', ['open', 'in_progress'])->count(),
            'actions_overdue' => CorrectiveAction::query()->where('status', CorrectiveActionStatus::Overdue->value)->count(),
            'awaiting_verification' => CorrectiveAction::query()->where('status', CorrectiveActionStatus::Completed->value)->count(),
        ];
    }
}
