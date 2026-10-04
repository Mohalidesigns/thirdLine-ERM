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
                // QA re-gate cycle 3: `incident` and `dr_test` are excluded
                // from this register's own generic "raise a finding" source
                // picker — this form has no `aar_id`/`dr_test_id` field, and
                // `FindingController::store()`'s per-source switch now
                // refuses both without one (D1: a finding raised as
                // `incident`/`dr_test` must actually be tied to the review
                // or test it came from). Those two sources are raised from
                // the PIR screen and the DR-test record instead, which
                // already supply the id. `aar` stays — its `aar_id` is
                // optional by design (`FindingSource`'s own docblock), so
                // this form can still raise one with none.
                'sources' => array_values(array_map(fn (FindingSource $s) => [
                    'value' => $s->value, 'label' => $s->label(),
                ], array_filter(
                    FindingSource::cases(),
                    fn (FindingSource $s) => ! in_array($s, [FindingSource::Incident, FindingSource::DrTest], true)
                ))),
                // The register's own SOURCE FILTER, unlike the raise form
                // above: filtering the list by `incident` or `dr_test` is
                // filtering rows that already exist (raised elsewhere, with
                // their id correctly attached), not raising a new one with
                // no id to give it — so this one carries all eight.
                'filter_sources' => array_map(fn (FindingSource $s) => [
                    'value' => $s->value, 'label' => $s->label(),
                ], FindingSource::cases()),
                'clause_refs' => IsoClauseRef::options(),
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
        $source = FindingSource::from($data['source']);

        // QA gate 1, defect 2: BOTH kinds of `bcms_aars` row withdraw their
        // "Raise a finding" control once `status === 'final'` — the exercise
        // builder (`Aar.jsx`: `can.finding_manage && !isFinal`, gating the
        // same `bcms.findings.store` form this route serves) and the PIR
        // screen (`IncidentPresenter::review()`'s `urls.raise_finding`, only
        // while `$aar->status !== 'final'`) apply the identical rule for the
        // identical reason: `AarService::reopen()` is the one explicit,
        // audited way to unlock a finalised report for more evidence, and a
        // control that raises a finding straight past that is exactly what
        // the withdrawn button was for. Nothing found in the AAR builder
        // spec, the clause map or the exercise test suite treats a
        // finalised EXERCISE AAR differently — the button is withdrawn there
        // too — so the same refusal applies to both, not only the PIR this
        // gap was reported against. Checked BEFORE the source/record
        // integrity checks below so a finalised AAR is refused for being
        // finalised, not for whatever else might be wrong with the request.
        if ($aarRecord !== null && $aarRecord->status === 'final') {
            return back()->withErrors([
                'aar_id' => 'That report has already been finalised and cannot have a new finding raised against it. '
                    .'Reopen it first.',
            ])->withInput();
        }

        // Code review gate 2, defect 1: the two-FK design (this class's own
        // docblock, and `bcms_findings`' migration docblock) means an
        // `aar_id`/`dr_test_id` sent with the WRONG `source` does not fail —
        // it silently produces a malformed row. Two ways this was reachable:
        // `source = aar` with a PIR's `aar_id` was accepted, filing an
        // exercise-clause finding (`iso22301.8.5.report`) against a real
        // incident and satisfying `PirService::conditions()`'s `6_findings`
        // with a finding that has no `incident_id`; `source = dr_test` with
        // only an `aar_id` reached `sourceLink()`, which used
        // `$source->foreignKey()` — `dr_test_id` — with the AAR's own key,
        // either a foreign-key violation or, worse, a silent cross-tenant
        // link if a `dr_tests` row happened to share that id. The record is
        // now derived FROM `$source`, and every mismatch is refused with a
        // validation error on the field that does not belong, before
        // `FindingService::raise()` ever sees it.
        $sourceRecord = null;
        $extraAttributes = [];

        switch ($source) {
            case FindingSource::Aar:
                if ($drTestRecord !== null) {
                    return back()->withErrors([
                        'dr_test_id' => 'A finding raised with source "aar" does not take a DR test id.',
                    ])->withInput();
                }

                // Nullable by design (`FindingSource`'s own docblock: "aar,
                // incident, call_tree_test and dr_test each have a nullable
                // foreign key") — an `aar_id` is not mandatory here, but if
                // one IS sent it must actually be an exercise AAR, not a PIR
                // wearing the same table.
                if ($aarRecord !== null && $aarRecord->isPostIncident()) {
                    return back()->withErrors([
                        'aar_id' => 'That review is a post-incident review, not an exercise after-action report, '
                            .'so a finding cannot be raised against it with source "aar".',
                    ])->withInput();
                }

                $sourceRecord = $aarRecord;
                break;

            case FindingSource::Incident:
                // Unlike `aar`, this route has no bare `incident_id` field —
                // the ONLY way to reach an incident here is through a PIR's
                // `aar_id` — so, for this route, "incident requires a PIR"
                // means `aar_id` is mandatory, not merely type-checked.
                if ($aarRecord === null) {
                    return back()->withErrors([
                        'aar_id' => 'A finding with source "incident" must name the post-incident review it was '
                            .'raised from.',
                    ])->withInput();
                }

                // QA gate 1, defect 1 (original wording preserved — the
                // regate test names this exact message): an exercise AAR
                // (`occurrence_id` set, `incident_id` null) is a valid,
                // visible `Aar` row under `VisibleToUser`, so without this
                // check `$aarRecord->incident` below would silently evaluate
                // to null and the finding would be created anyway as
                // `source = incident`, `incident_id = null`.
                if (! $aarRecord->isPostIncident()) {
                    return back()->withErrors([
                        'aar_id' => 'That review is not a post-incident review, so a finding cannot be raised '
                            .'against it with source "incident".',
                    ])->withInput();
                }

                if ($drTestRecord !== null) {
                    return back()->withErrors([
                        'dr_test_id' => 'A finding raised with source "incident" does not take a DR test id.',
                    ])->withInput();
                }

                // `FindingSource::Incident->foreignKey()` is `incident_id`,
                // not `aar_id` — `sourceLink()` needs the INCIDENT to stamp
                // the right column, and `aar_id` is carried through
                // separately so `PirService::conditions()`'s `6_findings`
                // check (`Finding::where('aar_id', ...)`) can still find it.
                $sourceRecord = $aarRecord->incident;
                $extraAttributes['aar_id'] = $aarRecord->getKey();
                break;

            case FindingSource::DrTest:
                if ($drTestRecord === null) {
                    return back()->withErrors([
                        'dr_test_id' => 'A finding with source "dr_test" must name the DR test it was raised from.',
                    ])->withInput();
                }

                if ($aarRecord !== null) {
                    return back()->withErrors([
                        'aar_id' => 'A finding raised with source "dr_test" does not take an AAR id.',
                    ])->withInput();
                }

                $sourceRecord = $drTestRecord;
                break;

            default:
                // `call_tree_test`, `audit`, `gap_analysis` and
                // `management_review` take no record through this route —
                // none of their FKs has a field here — so an `aar_id` or
                // `dr_test_id` sent alongside one of them is not silently
                // ignored, it is refused.
                if ($aarRecord !== null) {
                    return back()->withErrors([
                        'aar_id' => "A finding raised with source \"{$source->value}\" does not take an AAR id.",
                    ])->withInput();
                }

                if ($drTestRecord !== null) {
                    return back()->withErrors([
                        'dr_test_id' => "A finding raised with source \"{$source->value}\" does not take a DR test id.",
                    ])->withInput();
                }
        }

        try {
            $finding = $this->findings->raise(
                $source,
                FindingClassification::from($data['classification']),
                $data['description'],
                $sourceRecord,
                array_merge(
                    array_intersect_key($data, array_flip(['severity', 'root_cause', 'iso_clause_ref', 'affected_process_id', 'affected_plan_id'])),
                    $extraAttributes,
                ),
                $request->user()?->getKey(),
            );
        } catch (\InvalidArgumentException $e) {
            // Code review D2: a bare `back()->with('error', ...)` carries no
            // error bag, and Inertia treats a redirect with no error bag as
            // success — `RaiseFinding.jsx`'s `onSuccess` reset and closed the
            // form, discarding the officer's typed description with only a
            // toast (easy to miss, and gone on the next navigation) as the
            // only sign anything happened. `resolveClauseRef()`'s refusal
            // (no clause named on a nonconformity) is a validation failure on
            // a specific field, so it is flashed as one, the same shape every
            // other refusal in this method already uses.
            return back()->withErrors(['iso_clause_ref' => $e->getMessage()])->withInput();
        }

        // Code review A5: `linkObjectiveToFinding()` writes into
        // `quantitative_results['objectives']`, a shape the exercise AAR
        // builder owns (Phase 9's per-objective scoring); a PIR's
        // `quantitative_results` holds its own PIR metrics instead
        // (`readyToFinalisePir()`'s fixture shape), so a direct POST of
        // `objective_text` against a PIR's `aar_id` overwrote it with
        // `objectives: []`. Only an exercise AAR — `! isPostIncident()` — is
        // in scope for this link.
        if ($aarRecord !== null && ! $aarRecord->isPostIncident() && ($data['objective_text'] ?? null) !== null) {
            $this->aar->linkObjectiveToFinding($aarRecord, $data['objective_text'], $finding->reference);
        }

        // Code review A3: `existingFor()` folds a repeat into the row it
        // already raised rather than duplicating it (`FindingService`'s own
        // "idempotent on its source" contract) — `wasRecentlyCreated` is
        // Eloquent's own flag for exactly this, false on a row `raise()`
        // fetched rather than inserted, so the flash message says which one
        // happened instead of "raised" every time regardless.
        $verb = $finding->wasRecentlyCreated ? 'raised' : 'already raised';

        return back()->with('success', "Finding {$finding->reference} {$verb}.");
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
