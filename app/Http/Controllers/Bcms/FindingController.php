<?php

namespace App\Http\Controllers\Bcms;

use App\Enums\Bcms\CorrectiveActionStatus;
use App\Enums\Bcms\FindingClassification;
use App\Enums\Bcms\FindingSeverity;
use App\Enums\Bcms\FindingSource;
use App\Enums\Bcms\IsoClauseRef;
use App\Http\Controllers\Controller;
use App\Models\Bcms\Aar;
use App\Models\Bcms\CorrectiveAction;
use App\Models\Bcms\Finding;
use App\Models\Bcms\Plan;
use App\Models\Bcms\Process;
use App\Models\User;
use App\Rules\Bcms\VisibleToUser;
use App\Services\Bcms\Exercises\AarService;
use App\Services\Bcms\Findings\CorrectiveActionDueDateCalculator;
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
        private AarService $aar,
        private CorrectiveActionDueDateCalculator $dueDates,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('bcms.finding.view');

        $filters = $request->only(['status', 'classification', 'source', 'owner', 'due', 'search']);

        // ADR 0017 Amendment 2: Finding was the one anchor `ScopedToOrgHierarchy`
        // covers that no list scoped — a Kano holder of bcms.finding.view read
        // every Lagos finding, description and clause ref included, from this
        // screen and never needed a uuid. `visibleTo()` is the same scope the
        // record route now enforces at binding; the list gets it too.
        $findings = Finding::query()
            ->visibleTo($request->user())
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
            'summary' => $this->summary($request->user()),
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
                // Both pickers name a specific process or plan to attach a
                // finding to; a Kano user is not choosing among Lagos's, same
                // reasoning as the list above. `users` stays unscoped — a
                // tenant-wide roster of colleagues, not another division's
                // continuity data (ADR 0017 §7, "out of scope, stated so it
                // is not read as an oversight").
                'processes' => Process::query()->visibleTo($request->user())->orderBy('code')->get(['id', 'code', 'name']),
                'plans' => Plan::query()->visibleTo($request->user())->orderBy('title')->get(['id', 'title']),
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
            // ADR 0017 §7: these were a bare `exists:table,id` — a cross-tenant
            // existence oracle, not merely a cross-unit one, over whatever a
            // finding is filed against. `VisibleToUser` is the tenant- and
            // unit-scoped analogue of standard §4's `Rule::exists(...)
            // ->where('organization_id', ...)`.
            'affected_process_id' => ['nullable', 'integer', new VisibleToUser(Process::class)],
            'affected_plan_id' => ['nullable', 'integer', new VisibleToUser(Plan::class)],
            // Phase 9: the AAR builder's inline "Raise a finding" form (clause
            // map §3.1) posts here — the *existing* route, nothing new — with
            // the AAR it was raised from and, where it is against a specific
            // scored objective, that objective's snapshotted text so its
            // low-score disposition (condition 6) can point at the finding
            // this creates.
            'aar_id' => ['nullable', 'integer', new VisibleToUser(Aar::class)],
            'objective_text' => ['nullable', 'string', 'max:500'],
            // BCMS Phase 10: a breached DR test's own "Raise a finding"
            // control (`dr-test-record.md` §2) posts here, `source =
            // dr_test`, matching `Findings/Index.jsx`'s existing raise form
            // rather than a second route — `App\Enums\Bcms\FindingSource::
            // DrTest`'s foreign key is `dr_test_id`.
            'dr_test_id' => ['nullable', 'integer', new VisibleToUser(\App\Models\Bcms\DrTest::class)],
        ]);

        $aarRecord = isset($data['aar_id']) ? Aar::query()->find($data['aar_id']) : null;
        $drTestRecord = isset($data['dr_test_id']) ? \App\Models\Bcms\DrTest::query()->find($data['dr_test_id']) : null;
        $sourceRecord = $aarRecord ?? $drTestRecord;

        try {
            $finding = $this->findings->raise(
                FindingSource::from($data['source']),
                FindingClassification::from($data['classification']),
                $data['description'],
                $sourceRecord,
                array_intersect_key($data, array_flip(['severity', 'root_cause', 'iso_clause_ref', 'affected_process_id', 'affected_plan_id'])),
                $request->user()?->getKey(),
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($aarRecord !== null && ($data['objective_text'] ?? null) !== null) {
            $this->aar->linkObjectiveToFinding($aarRecord, $data['objective_text'], $finding->reference);
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

        // The finding→CAPA due-date rule (clause map §3.3): an action arising
        // from an exercise AAR with no due date supplied gets the computed
        // one — severity default, floored and capped against the next
        // occurrence of the same definition — rather than an open-ended action
        // with nothing to chase it.
        if (($data['due_date'] ?? null) === null && $finding->source?->value === 'aar') {
            $occurrence = $finding->aar?->occurrence;
            $severity = $finding->severity ?? FindingSeverity::Medium;

            if ($occurrence !== null) {
                $data['due_date'] = $this->dueDates->forOccurrence($occurrence, $severity)->toDateString();
            }
        }

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
            'severity' => $finding->severity?->value,
            'severity_label' => $finding->severity?->label(),
            'description' => $finding->description,
            'root_cause' => $finding->root_cause,
            'iso_clause_ref' => $finding->iso_clause_ref,
            'status' => $finding->status,
            'raised_at' => $finding->raised_at?->toDateString(),
            'process' => $finding->affectedProcess?->name,
            'plan' => $finding->affectedPlan?->title,
            'erm_issue_id' => $finding->erm_issue_id,
            // Built here rather than from the row's numeric `id`: `Finding`
            // route-binds on its `uuid` (HasBcmsUuid), and a client posting
            // the id it was handed 404s. One home for the URL means a
            // route-key change can never break this screen again (the TPRM
            // Intake Queue defect, 2026-09-13).
            'store_action_url' => route('bcms.actions.store', $finding),
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
    /**
     * `CorrectiveAction` is derived (ADR 0017 §2, `orgAnchorPath(): 'finding'`)
     * and carries no unit column of its own to scope on directly, so its three
     * counts reach the same filter through `whereHas('finding', ...)` —
     * `Finding::visibleQuery()` rather than the magic `->visibleTo()` scope
     * call, because a closure handed a bare `Builder` is exactly the case
     * `visibleQuery()`'s own docblock exists for.
     */
    private function summary(?User $user): array
    {
        return [
            'open' => Finding::query()->visibleTo($user)->where('status', 'open')->count(),
            'nonconformities_open' => Finding::query()->visibleTo($user)->where('status', 'open')
                ->where('classification', FindingClassification::Nonconformity->value)->count(),
            'actions_open' => CorrectiveAction::query()
                ->whereHas('finding', fn ($q) => Finding::visibleQuery($q, $user))
                ->whereIn('status', ['open', 'in_progress'])->count(),
            'actions_overdue' => CorrectiveAction::query()
                ->whereHas('finding', fn ($q) => Finding::visibleQuery($q, $user))
                ->where('status', CorrectiveActionStatus::Overdue->value)->count(),
            'awaiting_verification' => CorrectiveAction::query()
                ->whereHas('finding', fn ($q) => Finding::visibleQuery($q, $user))
                ->where('status', CorrectiveActionStatus::Completed->value)->count(),
        ];
    }
}
