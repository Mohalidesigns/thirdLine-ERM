<?php

namespace App\Presenters\Bcms;

use App\Enums\Bcms\ActivationLevel;
use App\Enums\Bcms\IncidentLogEntryType;
use App\Enums\Bcms\IncidentSeverity;
use App\Models\Bcms\Aar;
use App\Models\Bcms\Alert;
use App\Models\Bcms\AlertRecipient;
use App\Models\Bcms\CorrectiveAction;
use App\Models\Bcms\DrSystem;
use App\Models\Bcms\Finding;
use App\Models\Bcms\Incident;
use App\Models\Bcms\IncidentNotification;
use App\Models\Bcms\Plan;
use App\Models\Bcms\Process;
use App\Models\Bcms\Site;
use App\Models\BusinessUnit;
use App\Models\User;
use App\Services\Bcms\Emns\RollCallService;
use App\Services\Bcms\Incidents\IncidentService;
use App\Services\Bcms\Incidents\NotificationService;
use App\Services\Bcms\Incidents\PirService;
use App\Support\Bcms\IncidentClock;
use Illuminate\Support\Facades\Auth;

/**
 * What the incident-declaration form, the crisis room, the notification log
 * and the stand-down gate draw. Standard §1: a controller resolves and
 * authorises; this shapes the payload, so `Inertia::render` never computes
 * anything.
 *
 * EVERY ACTION URL IS SERVER-BUILT (`ModuleActionUrlRouteKeyTest`) — nothing
 * here hands the client a numeric id to splice into a route itself.
 */
class IncidentPresenter
{
    public function __construct(
        private IncidentService $incidents,
        private NotificationService $notifications,
        private PirService $pir,
    ) {}

    /** @return array<string, mixed> */
    public function declareForm(): array
    {
        $user = Auth::user();
        $orgId = $user?->organization_id;

        return [
            'severity_bands' => $this->severityBands(),
            'activation_levels' => $this->activationLevels(),
            'processes' => Process::query()->where('organization_id', $orgId)
                ->orderBy('name')->get(['id', 'name', 'code'])
                ->map(fn (Process $p) => ['id' => $p->getKey(), 'name' => $p->name, 'code' => $p->code])->all(),
            'business_units' => BusinessUnit::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn ($u) => ['id' => $u->getKey(), 'name' => $u->name])->all(),
            'sites' => Site::query()->where('organization_id', $orgId)->where('is_active', true)
                ->orderBy('name')->get(['id', 'name'])
                ->map(fn (Site $s) => ['id' => $s->getKey(), 'name' => $s->name])->all(),
            // ADR 0017: a plan listed here is one a user can pick to activate
            // on declaration — offering one they cannot see is the same
            // cross-unit leak `visibleTo()` closes everywhere else this
            // module lists a plan (Gate 1 re-gate defect 9).
            'plans' => Plan::query()->where('organization_id', $orgId)
                ->where('status', 'approved')
                ->visibleTo($user)
                ->get(['id', 'uuid', 'title', 'plan_type', 'business_unit_id'])
                ->map(fn (Plan $p) => [
                    'id' => $p->getKey(), 'title' => $p->title, 'plan_type' => $p->plan_type->value,
                    'business_unit_id' => $p->business_unit_id,
                    'activation_criteria' => $p->content['activation_criteria'] ?? null,
                ])->all(),
            'store_url' => route('bcms.incidents.store'),
        ];
    }

    /**
     * The four bands' default trigger text (`incident-declaration.md` §2) —
     * `IncidentService::suggestSeverity()`'s own wording, restated here as
     * static reference copy because that method takes live inputs this form
     * does not yet collect one-for-one. No tenant override exists in
     * `config('bcms.php')` today, so every band is tagged `is_default` —
     * true, not a placeholder pretending one might not be.
     *
     * @return list<array<string, mixed>>
     */
    private function severityBands(): array
    {
        $triggers = [
            IncidentSeverity::Sev1->value => 'A critical service is down or forecast down past its BIA RTO, a life-safety impact exists, an Open Banking service is past its 30-minute threshold, or a personal-data breach carries high risk to data subjects.',
            IncidentSeverity::Sev2->value => 'A prioritised activity is disrupted but inside its RTO, or running on a documented workaround, contained to one site or business line.',
            IncidentSeverity::Sev3->value => 'Contained to one system with no customer impact and no data loss.',
            IncidentSeverity::Sev4->value => 'A minor, single-user or single-workstation issue with no measurable service impact.',
        ];

        return collect(IncidentSeverity::cases())->map(fn (IncidentSeverity $s) => [
            'value' => $s->value,
            'label' => $s->label(),
            'trigger' => $triggers[$s->value],
            'is_default' => true,
            'default_activation_level' => $s->defaultActivationLevel()->value,
        ])->all();
    }

    /** @return list<array{value: string, label: string}> */
    private function activationLevels(): array
    {
        return collect(ActivationLevel::cases())->map(fn (ActivationLevel $a) => [
            'value' => $a->value,
            'label' => $a->label(),
        ])->all();
    }

    /** @return array<string, mixed> */
    public function crisisRoom(Incident $incident): array
    {
        // Gate 1 re-gate defect 8: `planActivations.keptActiveEntry` added
        // so the `plan_activations` map below does not lazy-load it once
        // per activation.
        $incident->loadMissing([
            'entries.loggedBy:id,name', 'tasks.owner:id,name',
            'planActivations.plan:id,title', 'planActivations.activatedBy:id,name',
            'planActivations.keptActiveEntry',
        ]);

        $user = Auth::user();
        $orgId = $user?->organization_id;

        // The same alert Emns/Alert.jsx already reads, scoped to this
        // incident (crisis-room.md §7) — read-only here, the roll-call
        // console itself stays Phase 7's screen, not duplicated.
        $rollCallAlert = Alert::query()
            ->where('incident_id', $incident->getKey())
            ->whereNotNull('dispatched_at')
            ->latest('dispatched_at')
            ->first();

        return [
            'incident' => $this->incidentSummary($incident),
            'can' => [
                'manage' => $user?->can('bcms.incident.manage') === true,
                'notify' => $user?->can('bcms.incident.notify') === true,
                // ADR 0020 Amendment 2 rule 2: withdrawing a LIVE obligation
                // needs `bcms.incident.notify`, not `.manage` — a distinct
                // flag from `notify` above so a "reassess this obligation"
                // control, wherever one is built, does not have to know that
                // "notify" happens to be the withdrawal permission too.
                'withdraw' => $user?->can('bcms.incident.notify') === true,
                'view_notifications' => $user?->can('bcms.incident.view') === true,
                'activate_plan' => $user?->can('bcms.plan.activate') === true,
                'dispatch_alert' => $user?->can('bcms.alert.compose') === true,
                'life_safety' => $user?->can('bcms.alert.life_safety') === true,
                'export' => $user?->can('bcms.report.export') === true,
            ],
            'severity_bands' => $this->severityBands(),
            'activation_levels' => $this->activationLevels(),
            'entry_types' => collect(IncidentLogEntryType::cases())->map(fn (IncidentLogEntryType $t) => [
                'value' => $t->value,
                'label' => $t->label(),
                'requires_options_and_rationale' => $t->requiresOptionsAndRationale(),
            ])->all(),
            // ADR 0017 (Gate 1 re-gate defect 9): the same visibility rule
            // `declareForm()`'s own plan list now carries.
            'plans' => Plan::query()->where('organization_id', $orgId)->where('status', 'approved')
                ->visibleTo($user)
                ->get(['id', 'uuid', 'title', 'plan_type'])
                ->map(fn (Plan $p) => [
                    'id' => $p->getKey(), 'uuid' => $p->uuid, 'title' => $p->title, 'plan_type' => $p->plan_type->value,
                    'activate_url' => route('bcms.plans.activate', $p),
                ])
                ->all(),
            'roll_call' => $rollCallAlert === null ? null : array_merge(
                app(RollCallService::class)->summary($rollCallAlert),
                ['alert_url' => route('bcms.alerts.show', $rollCallAlert)],
            ),
            'review' => (function () use ($incident) {
                $aar = $incident->reviews()->first();

                return [
                    'exists' => $aar !== null,
                    // `bcms.incidents.review.show`, not `bcms.aars.show` — see
                    // `IncidentReviewController`'s own docblock for why the PIR
                    // does not reuse the exercise AAR's screen this phase.
                    'show_url' => $aar !== null ? route('bcms.incidents.review.show', $incident) : null,
                    'final' => $aar?->status === 'final',
                ];
            })(),
            'closed_by' => $incident->status?->isTerminal() && $incident->updated_by
                ? User::query()->find($incident->updated_by)?->name
                : null,
            'countdown_tiles' => $this->computeCountdownTiles($incident),
            'alerts' => Alert::query()->where('incident_id', $incident->getKey())
                ->orderByDesc('created_at')
                ->get(['id', 'uuid', 'title', 'status', 'severity', 'is_simulation', 'dispatched_at'])
                ->map(fn (Alert $a) => [
                    'uuid' => $a->uuid,
                    'title' => $a->title,
                    'status' => $a->status,
                    'severity' => $a->severity->value,
                    'is_simulation' => (bool) $a->is_simulation,
                    'dispatched_at' => $a->dispatched_at?->toIso8601String(),
                    'show_url' => route('bcms.alerts.show', $a),
                ])->values()->all(),
            'reportability' => [
                'cbn' => $this->notifications->reportabilityStatus($incident, 'cbn'),
                'personal_data' => $this->notifications->reportabilityStatus($incident, 'personal_data'),
            ],
            // Gate 1 re-gate defect 8: `$incident->entries()` (a fresh
            // query) bypassed the `entries.loggedBy` eager load above and
            // re-queried the whole relation from scratch, N+1-ing
            // `loggedBy` on top — `$incident->entries` (the loaded
            // collection) reads the same rows already in memory.
            'entries' => $incident->entries->sortByDesc('logged_at')->values()->map(fn ($e) => [
                'id' => $e->getKey(),
                'logged_at' => $e->logged_at?->toIso8601String(),
                'entry_type' => $e->entry_type->value,
                'content' => $e->content,
                'logged_by' => $e->loggedBy?->name,
                'supersedes_entry_id' => $e->supersedes_entry_id,
            ])->values()->all(),
            'tasks' => $incident->tasks->map(fn ($t) => [
                'id' => $t->getKey(),
                'title' => $t->title,
                'status' => $t->status,
                'owner' => $t->owner?->name,
                'due_at' => $t->due_at?->toIso8601String(),
                'complete_url' => route('bcms.incidents.tasks.complete', ['incident' => $incident, 'task' => $t]),
            ])->values()->all(),
            'plan_activations' => $incident->planActivations->map(fn ($a) => [
                'id' => $a->getKey(),
                'plan' => $a->plan?->title,
                'activated_by' => $a->activatedBy?->name,
                'activated_at' => $a->activated_at?->toIso8601String(),
                'deactivated_at' => $a->deactivated_at?->toIso8601String(),
                // ADR 0020 Amendment 4 — read live from the activation and
                // its decision-log entry, never copied onto the AAR row.
                'is_kept_active' => $a->isKeptActive(),
                'kept_active_rationale' => $a->keptActiveEntry?->content,
            ])->values()->all(),
            'metrics' => $this->computeMetrics($incident),
            'urls' => [
                'log_store' => route('bcms.incidents.log.store', $incident),
                'tasks_store' => route('bcms.incidents.tasks.store', $incident),
                'classify' => route('bcms.incidents.classify', $incident),
                'regrade' => route('bcms.incidents.regrade', $incident),
                'notifications' => route('bcms.incidents.notifications.index', $incident),
                'stand_down' => route('bcms.incidents.stand-down-form', $incident),
                'review_start' => route('bcms.incidents.review.start', $incident),
                'live_metrics' => route('bcms.incidents.live-metrics', $incident),
                'alerts_store' => route('bcms.alerts.store'),
            ],
        ];
    }

    /**
     * The crisis-room poll's own payload (`IncidentController::liveMetrics()`,
     * polled every 5 seconds) — a FIXED, SMALL SHAPE, never the whole
     * `crisisRoom()` payload (Gate 2 review #1 defect 10). `entries`,
     * `tasks` and `plan_activations` are deliberately absent: the frontend
     * already holds them from the initial render and appends new rows
     * itself, and shipping all three afresh every 5 seconds made the poll's
     * cost grow with the incident's own history.
     *
     * CONSTANT QUERY COUNT REGARDLESS OF LOG SIZE. Every figure below is an
     * aggregate `COUNT` or a single indexed lookup (`ORDER BY … LIMIT 1`,
     * the "one MAX for latest" contract) — nothing here loads a collection
     * whose size tracks the incident's history.
     *
     * @return array<string, mixed>
     */
    public function liveMetrics(Incident $incident): array
    {
        $metrics = $this->computeMetrics($incident);

        // The same alert crisisRoom()'s own roll_call block reads, scoped to
        // this incident — here reduced to the two counts the contract asks
        // for, not the full recipient roster `RollCallService::summary()`
        // would eager-load.
        $rollCallAlert = Alert::query()
            ->where('incident_id', $incident->getKey())
            ->whereNotNull('dispatched_at')
            ->latest('dispatched_at')
            ->first(['id']);

        $rollCallExpected = 0;
        $rollCallResponded = 0;

        if ($rollCallAlert !== null) {
            $rollCallExpected = AlertRecipient::query()->where('alert_id', $rollCallAlert->getKey())->count();
            $rollCallResponded = AlertRecipient::query()->where('alert_id', $rollCallAlert->getKey())
                ->whereNotNull('acknowledged_at')->count();
        }

        $latestEntry = $incident->entries()
            ->orderByDesc('logged_at')->orderByDesc('id')
            ->first(['id', 'logged_at']);

        return [
            'metrics' => $metrics,
            'countdown_tiles' => $this->computeCountdownTiles($incident),
            'counts' => [
                // Same value `metrics.decisions_logged`/`open_tasks` already
                // carry — read from there rather than querying twice.
                'entries' => $metrics['decisions_logged'],
                'tasks_open' => $metrics['open_tasks'],
                'tasks_done' => $incident->tasks()->where('status', 'complete')->count(),
                'plans_active' => $incident->planActivations()->whereNull('deactivated_at')->count(),
                'roll_call_responded' => $rollCallResponded,
                'roll_call_expected' => $rollCallExpected,
            ],
            'latest_entry_id' => $latestEntry?->getKey(),
            'latest_entry_at' => $latestEntry?->logged_at?->toIso8601String(),
            'status' => $incident->status?->value,
        ];
    }

    /** @return array{open_tasks: int, decisions_logged: int} */
    private function computeMetrics(Incident $incident): array
    {
        return [
            'open_tasks' => $incident->tasks()->whereNotIn('status', ['complete', 'cancelled'])->count(),
            'decisions_logged' => $incident->entries()->count(),
        ];
    }

    /**
     * The open-obligation countdown tiles — a small, bounded set (one row
     * per regulator per obligation), queried directly rather than filtered
     * from a loaded collection so `liveMetrics()` never has to load the
     * whole notification history to build it.
     *
     * @return list<array<string, mixed>>
     */
    private function computeCountdownTiles(Incident $incident): array
    {
        return IncidentNotification::query()
            ->where('incident_id', $incident->getKey())
            // Mirrors `IncidentNotification::isOpen()` at the query level
            // (submitted_at null AND withdrawn_at null — ADR 0020 Amendment 2).
            ->whereNull('submitted_at')
            ->whereNull('withdrawn_at')
            ->orderBy('awareness_at')
            ->get()
            ->map(fn (IncidentNotification $n) => [
                'id' => $n->getKey(),
                'regulator' => $n->regulator->value,
                'regulator_label' => $n->regulator->label(),
                'kind_label' => $n->kind->label(),
                'due_at' => $n->due_at?->toIso8601String(),
                'is_overdue' => $n->isOverdue(),
            ])->values()->all();
    }

    /** @return array<string, mixed> */
    public function notificationLog(Incident $incident): array
    {
        $rows = $incident->notifications()->orderBy('awareness_at')->orderBy('sequence')->get();

        // A plain PHP grouping loop rather than ->groupBy()->map() — the
        // nested-Collection generic that chain produces is a documented
        // Larastan false positive ("contains unresolvable type") that a
        // type-hint on the inner closure does not resolve; a loop is exactly
        // as clear here and gives static analysis nothing to lose track of.
        $byObligation = [];

        foreach ($rows as $row) {
            $byObligation[$row->regulator->value.'|'.$row->basis_clause_ref][] = $row;
        }

        $groups = [];

        foreach ($byObligation as $obligationRows) {
            $first = $obligationRows[0];

            $groups[] = [
                'regulator' => $first->regulator->value,
                'regulator_label' => $first->regulator->label(),
                'basis_clause_ref' => $first->basis_clause_ref,
                'is_open' => collect($obligationRows)->contains(fn (IncidentNotification $n) => $n->isOpen()),
                'submissions' => array_values(array_map(fn (IncidentNotification $n) => [
                    'id' => $n->getKey(),
                    'kind' => $n->kind->value,
                    'kind_label' => $n->kind->label(),
                    'sequence' => $n->sequence,
                    'due_at' => $n->due_at?->toIso8601String(),
                    'submitted_at' => $n->submitted_at?->toIso8601String(),
                    'submitted_by' => $n->submittedBy?->name,
                    'reference' => $n->reference,
                    'content_snapshot' => $n->content_snapshot,
                    'is_overdue' => $n->isOverdue(),
                    // Amendment 2: a withdrawn row is shown in full, never
                    // filtered out of this register.
                    'withdrawn_at' => $n->withdrawn_at?->toIso8601String(),
                    'withdrawn_by' => $n->withdrawnBy?->name,
                ], $obligationRows)),
            ];
        }

        $user = Auth::user();

        return [
            'incident' => $this->incidentSummary($incident),
            'obligations' => $groups,
            'notification_kinds' => collect(\App\Enums\Bcms\NotificationKind::cases())->map(fn ($k) => [
                'value' => $k->value, 'label' => $k->label(), 'repeats' => $k->repeats(),
            ])->all(),
            'regulators' => collect(\App\Enums\Bcms\NotificationRegulator::cases())->map(fn ($r) => [
                'value' => $r->value, 'label' => $r->label(),
            ])->all(),
            'can' => [
                'notify' => $user?->can('bcms.incident.notify') === true,
                'manage' => $user?->can('bcms.incident.manage') === true,
                'export' => $user?->can('bcms.report.export') === true,
            ],
            'record_url' => route('bcms.incidents.notifications.store', $incident),
            'classify_url' => route('bcms.incidents.notifications.classify', $incident),
            'export_url' => route('bcms.incidents.notifications.export', $incident),
            'crisis_room_url' => route('bcms.incidents.crisis-room', $incident),
        ];
    }

    /** @return array<string, mixed> */
    public function standDown(Incident $incident): array
    {
        return [
            'incident' => $this->incidentSummary($incident),
            'checklist' => $this->incidents->standDownChecklist($incident),
            // Only activations still needing a disposition (ADR 0020
            // Amendment 4) — one already recorded as kept active does not
            // reappear asking for one again.
            'plan_activations' => $incident->planActivations()
                ->whereNull('deactivated_at')->whereNull('kept_active_entry_id')
                ->with('plan:id,title')->get()
                ->map(fn ($a) => ['id' => $a->getKey(), 'plan' => $a->plan?->title])->all(),
            // `AlertController::store()` now accepts `incident_id` (Gate 2
            // review #1 defect 13), so every prior SitRep dispatched from
            // this incident is findable by that column — one row per
            // distinct audience, pre-selected on the all-clear form (spec
            // §2's "a checklist of those audiences, pre-selected"). Stays
            // empty, and the dispatch checkbox hides itself, only when this
            // incident genuinely sent nobody an earlier communication.
            'audience_groups' => Alert::query()
                ->where('incident_id', $incident->getKey())
                ->whereNotNull('dispatched_at')
                ->whereNotNull('audience_rule')
                ->orderBy('id')
                ->get(['id', 'title', 'audience_rule'])
                ->unique(fn (Alert $a) => json_encode($a->audience_rule))
                ->map(fn (Alert $a) => [
                    'alert_id' => $a->getKey(),
                    'label' => $a->title,
                    'audience_rule' => $a->audience_rule,
                ])->values()->all(),
            'submit_url' => route('bcms.incidents.stand-down', $incident),
            'crisis_room_url' => route('bcms.incidents.crisis-room', $incident),
            'notifications_url' => route('bcms.incidents.notifications.index', $incident),
        ];
    }

    /** @return array<string, mixed> */
    public function index(): array
    {
        $user = Auth::user();
        $orgId = $user?->organization_id;

        // ADR 0017: `Incident` uses `ScopedToOrgHierarchy` — an org filter
        // alone is the tenant boundary, not the business-unit one (Gate 2
        // review #1 defect 9). Without `->visibleTo()` a user in another
        // business unit saw every incident in the list even though opening
        // one by uuid already 404s them.
        $incidents = Incident::query()->where('organization_id', $orgId)
            ->visibleTo($user)
            ->orderByDesc('declared_at')
            ->paginate(25);

        return [
            'incidents' => $incidents->through(fn (Incident $i) => [
                'uuid' => $i->uuid,
                'reference' => $i->reference,
                'title' => $i->title,
                'severity' => $i->severity?->value,
                'status' => $i->status?->value,
                'declared_at' => $i->declared_at?->toIso8601String(),
                'closed_at' => $i->closed_at?->toIso8601String(),
                'is_exercise' => (bool) $i->is_exercise,
                'show_url' => route('bcms.incidents.crisis-room', $i),
            ]),
            'declare_url' => route('bcms.incidents.declare-form'),
        ];
    }

    /**
     * The post-incident review — `pir-post-incident-review.md`, a delta on
     * the AAR builder computed by `PirService`, never re-derived here.
     *
     * @return array<string, mixed>
     */
    public function review(Incident $incident, Aar $aar): array
    {
        $user = Auth::user();
        $conditions = $this->pir->conditions($aar);
        $qr = (array) $aar->quantitative_results;

        // Same two-permission conjunction the spec's §1 permission note
        // requires for a PIR specifically: an exercise-only `bcms.aar.*`
        // grant is not sufficient to manage/approve incident-shaped
        // evidence that may carry personal data.
        $canManage = $user?->can('bcms.aar.manage') === true && $user->can('bcms.incident.manage') === true;
        $canApprove = $user?->can('bcms.aar.approve') === true && $user->can('bcms.incident.manage') === true;

        // A4 (code review #3 advisory): the same separation-of-duties rule
        // `finalise()` enforces (`PirService::pirApproverAllowed()`) — the
        // permission conjunction above says nothing about WHO ran this
        // incident's response, so a permission holder barred by that rule
        // saw a Finalise control `finalise()` would then refuse.
        $approverBarredReason = null;

        if ($canApprove && $user !== null) {
            $approval = $this->pir->pirApproverAllowed($aar, $user);
            $canApprove = $approval['allowed'];
            $approverBarredReason = $approval['allowed'] ? null : $approval['reason'];
        }

        return [
            // Gate 1 re-gate defect 7 — the pinned contract: the DECLARATION
            // estimate stays on `incident.estimated_impact_minor` (unchanged
            // meaning); the PIR-CONFIRMED figure ships once, on
            // `aar.realised_loss_minor` below, never duplicated under a
            // second key.
            'incident' => $this->incidentSummary($incident) + [
                'incident_type' => $incident->incident_type,
                'business_unit' => $incident->businessUnit?->name,
                'site' => $incident->site?->name,
                'declared_by' => $incident->declaredBy?->name,
                'estimated_impact_minor' => $incident->estimated_impact_minor,
                // A bare id, never a URL — same convention `Findings/
                // Index.jsx` already uses for `erm_issue_id`: a viewer
                // without loss-register access must never be handed a
                // cross-module link that 403s/404s for them.
                'erm_loss_event_id' => $incident->erm_loss_event_id,
            ],
            'aar' => [
                'id' => $aar->getKey(),
                'uuid' => $aar->uuid,
                'status' => $aar->status,
                'summary' => $aar->summary,
                'what_worked' => $aar->what_worked,
                'what_failed' => $aar->what_failed,
                'quantitative_results' => $qr,
                // Null until confirmed at finalisation (Gate 1 re-gate
                // defect 7's pinned contract) — `PirService::finalise()` is
                // the one write path, under `bcms.aar.approve` +
                // `bcms.incident.manage`.
                'realised_loss_minor' => $qr['realised_loss_minor'] ?? null,
                'participant_feedback' => $aar->participant_feedback,
                'ai_generated' => (bool) $aar->ai_generated,
                'ai_draft_generated_at' => $aar->ai_draft_generated_at?->toIso8601String(),
                'approved_by' => $aar->approver?->name,
                'approved_at' => $aar->approved_at?->toIso8601String(),
                'distributed_at' => $aar->distributed_at?->toIso8601String(),
                'iso_clause_ref' => $aar->iso_clause_ref,
            ],
            'plan_sections' => $qr['plan_sections'] ?? [],
            'conditions' => $conditions,
            'all_conditions_met' => collect($conditions)->every(fn (array $c) => $c['met']),
            // Section 3 (spec §2) — decision/escalation entries only, matching
            // the eight-condition gate's own definition of "a relevant entry",
            // collapsed to the last 10 the same way the AAR builder collapses
            // its exercise timeline.
            'timeline' => [
                // A7 (code review #3 advisory): `loggedBy` eager-loaded —
                // ten entries otherwise ran ten extra queries for a name
                // each, the same N+1 shape defect 8 already closed elsewhere
                // on this presenter.
                'entries' => $incident->entries()->whereIn('entry_type', ['decision', 'escalation'])
                    ->with('loggedBy:id,name')
                    ->orderByDesc('logged_at')->limit(10)->get()
                    ->map(fn ($e) => [
                        'id' => $e->getKey(),
                        'logged_at' => $e->logged_at?->toIso8601String(),
                        'entry_type' => $e->entry_type->value,
                        'content' => $e->content,
                        'logged_by' => $e->loggedBy?->name,
                    ])->values()->all(),
                'total' => $incident->entries()->whereIn('entry_type', ['decision', 'escalation'])->count(),
            ],
            'findings' => Finding::query()->where('aar_id', $aar->getKey())
                ->with('correctiveActions.owner:id,name')->get()->map(fn (Finding $f) => [
                    'uuid' => $f->uuid,
                    'reference' => $f->reference,
                    'classification' => $f->classification?->value,
                    'severity' => $f->severity?->value,
                    'iso_clause_ref' => $f->iso_clause_ref,
                    'status' => $f->status,
                    'description' => $f->description,
                    'store_action_url' => route('bcms.actions.store', $f),
                    'actions' => $f->correctiveActions->map(fn (CorrectiveAction $a) => [
                        'uuid' => $a->uuid,
                        'reference' => $a->reference,
                        'title' => $a->title,
                        'owner' => $a->owner?->name,
                        'due_date' => $a->due_date?->toDateString(),
                        'status' => $a->status?->value,
                    ])->values()->all(),
                ])->values()->all(),
            // No `PirAiDrafter` exists yet (phase-10-notes.md, "explicitly
            // deferred") — the tenth section always renders as unavailable
            // rather than a button calling a capability that is not wired.
            'ai' => ['available' => false, 'unavailable_reason' => 'Post-incident AI drafting is not enabled in this deployment.'],
            'options' => [
                'users' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            ],
            'can' => [
                'manage' => $canManage,
                'approve' => $canApprove,
                'export' => $user?->can('bcms.report.export') === true,
                'finding_manage' => $user?->can('bcms.finding.manage') === true,
            ],
            // A4: why `can.approve` is false for someone who otherwise holds
            // both permissions — null whenever the permission conjunction
            // itself is what's missing, so the screen does not print a
            // separation-of-duties message at someone who simply lacks the
            // grant.
            'approver_barred_reason' => $approverBarredReason,
            'urls' => [
                'update' => route('bcms.aars.update', $aar),
                'finalise' => route('bcms.incidents.review.finalise', $incident),
                'reopen' => route('bcms.aars.reopen', $aar),
                'distribute' => route('bcms.aars.distribute', $aar),
                // `ai_draft` DELIBERATELY ABSENT (Gate 1 re-gate defect 6):
                // that route runs exercise-AI synthesis (`AAR_SYNTHESIS`,
                // always on) which bypassed `post_incident_learning` (off by
                // default) entirely for a PIR — refused server-side in
                // `AarController::aiDraft()`/`AarAiDrafter::draft()` too, but
                // the URL is not shipped at all so there is nothing to post
                // to in the first place.
                'export' => route('bcms.incidents.review.export', $incident),
                'raise_finding' => route('bcms.findings.store'),
                'crisis_room' => route('bcms.incidents.crisis-room', $incident),
            ],
        ];
    }

    /**
     * `dr-failover-failback-record.md` — the read-only evidence assembly for
     * a REAL invocation, never a DR test (ADR 0020 §4: the actuals live in
     * the PIR's `quantitative_results` and `bcms_plan_activations`; this
     * screen writes nothing to `bcms_dr_tests` and has no store route at
     * all). One incident, every DR system whose `failover_runbook_plan_id`
     * matches a plan this incident actually activated — the spec's own
     * "reached per-incident, per-system" scope, assembled here as one page
     * per incident rather than one route per system, because
     * `bcms_plan_activations` already ties every affected system back to
     * this one incident and a picker between them would only recreate what
     * this query already knows.
     *
     * Corrective actions have no per-system column on `bcms_findings` (the
     * spec's own "no new column" rule extends here by the same reasoning it
     * states for the timeline match) so they are listed once, incident-wide,
     * rather than guessed at per system.
     *
     * EVERY *_URL IS SERVER-BUILT (`ModuleActionUrlRouteKeyTest`).
     *
     * @return array<string, mixed>
     */
    public function drInvocation(Incident $incident): array
    {
        $incident->loadMissing([
            'entries.loggedBy:id,name',
            'planActivations.plan:id,title',
            'planActivations.activatedBy:id,name',
        ]);

        $activatedPlanIds = $incident->planActivations->pluck('plan_id')->unique()->values()->all();

        $systems = $activatedPlanIds === []
            ? collect()
            : DrSystem::query()->whereIn('failover_runbook_plan_id', $activatedPlanIds)
                ->with(['runbook:id,title', 'drSite:id,name'])
                ->orderBy('name')->get();

        $aar = $incident->reviews()->first();
        $qr = $aar === null ? [] : (array) $aar->quantitative_results;
        $perSystemResults = (array) ($qr['dr_invocations'] ?? []);

        $user = Auth::user();

        // Gate 1 re-gate defect 8: `$incident->entries()` (a fresh query,
        // twice) ran per system in the map below, N+1-ing both the query
        // itself and the `loggedBy` eager load above. `$incident->entries`
        // (already loaded) is filtered/sorted in PHP once per system
        // instead — the incident's whole decision log is a bounded, small
        // collection (not the thing that scales here; the system count is).
        $allEntries = $incident->entries;

        return [
            'incident' => $this->incidentSummary($incident),
            'has_invocation' => $systems->isNotEmpty(),
            'pir' => [
                'exists' => $aar !== null,
                'is_draft' => $aar !== null && $aar->status !== 'final',
                'is_final' => $aar?->status === 'final',
                'url' => $aar !== null ? route('bcms.incidents.review.show', $incident) : null,
            ],
            'systems' => $systems->map(function (DrSystem $system) use ($incident, $perSystemResults, $allEntries) {
                $activation = $incident->planActivations->firstWhere('plan_id', $system->failover_runbook_plan_id);
                // A7 (code review #3 advisory): case-insensitive — an
                // officer typing a decision-log entry mid-incident does not
                // reliably match a system name's stored case, and a
                // case-sensitive `str_contains()` silently dropped the
                // entry from this system's timeline rather than matching it.
                $needle = mb_strtolower($system->name);

                $timeline = $allEntries
                    ->filter(fn ($e) => str_contains(mb_strtolower((string) $e->content), $needle))
                    ->sortByDesc('logged_at')->values();

                $communications = $allEntries
                    ->filter(fn ($e) => $e->entry_type === IncidentLogEntryType::Communication
                        && str_contains(mb_strtolower((string) $e->content), $needle))
                    ->sortByDesc('logged_at')->values();

                $sysQr = (array) ($perSystemResults[$system->uuid] ?? []);
                $failback = (array) ($sysQr['failback'] ?? []);

                return [
                    'uuid' => $system->uuid,
                    'name' => $system->name,
                    'dr_site' => $system->drSite?->name,
                    'dr_system_url' => route('bcms.dr-systems.tests.index', $system),
                    'authorisation' => $activation === null ? null : [
                        'activated_by' => $activation->activatedBy?->name,
                        'activated_at' => $activation->activated_at?->toIso8601String(),
                        'activation_reason' => $activation->activation_reason,
                        'runbook_title' => $system->runbook?->title,
                    ],
                    'timeline' => $timeline->map(fn ($e) => [
                        'id' => $e->getKey(),
                        'logged_at' => $e->logged_at?->toIso8601String(),
                        'entry_type' => $e->entry_type->value,
                        'content' => $e->content,
                        'logged_by' => $e->loggedBy?->name,
                    ])->values()->all(),
                    'recovery' => [
                        'rto_target_hours' => $system->rto_target_hours,
                        'rpo_target_minutes' => $system->rpo_target_minutes,
                        'outage_actual_minutes' => $sysQr['outage_actual_minutes'] ?? null,
                        'data_loss_actual_minutes' => $sysQr['data_loss_actual_minutes'] ?? null,
                        'measured_how' => $sysQr['measured_how'] ?? null,
                    ],
                    'failback' => [
                        // null = not yet known (PIR draft/absent); false = an
                        // explicit "did not fail back" fact; true = it happened.
                        'occurred' => $failback['occurred'] ?? null,
                        // The one datetime `quantitative_results` carries
                        // with no Eloquent cast (`AarService::update()`
                        // normalises it on write; normalised again here in
                        // case the row predates that fix or was written by
                        // another path — a seeder, an import).
                        'at' => IncidentClock::utc($failback['at'] ?? null)?->toIso8601String(),
                        'data_lost_note' => $failback['data_lost_note'] ?? null,
                    ],
                    'integrity_verification' => $sysQr['integrity_verification'] ?? null,
                    'communications' => $communications->map(fn ($e) => [
                        'id' => $e->getKey(),
                        'logged_at' => $e->logged_at?->toIso8601String(),
                        'content' => $e->content,
                        'logged_by' => $e->loggedBy?->name,
                    ])->values()->all(),
                ];
            })->values()->all(),
            'corrective_actions' => Finding::query()->where('incident_id', $incident->getKey())
                ->whereNull('dr_test_id')
                ->with('correctiveActions.owner:id,name')->get()->map(fn (Finding $f) => [
                    'uuid' => $f->uuid,
                    'reference' => $f->reference,
                    'description' => $f->description,
                    'status' => $f->status,
                    'actions' => $f->correctiveActions->map(fn (CorrectiveAction $a) => [
                        'uuid' => $a->uuid,
                        'reference' => $a->reference,
                        'title' => $a->title,
                        'owner' => $a->owner?->name,
                        'status' => $a->status?->value,
                    ])->values()->all(),
                ])->values()->all(),
            'can' => [
                'export' => $user?->can('bcms.report.export') === true,
            ],
            'incident_url' => route('bcms.incidents.crisis-room', $incident),
            'notification_log_url' => route('bcms.incidents.notifications.index', $incident),
        ];
    }

    /** @return array<string, mixed> */
    private function incidentSummary(Incident $incident): array
    {
        return [
            'uuid' => $incident->uuid,
            'reference' => $incident->reference,
            'title' => $incident->title,
            'severity' => $incident->severity?->value,
            'severity_label' => $incident->severity?->label(),
            'activation_level' => $incident->activation_level?->value,
            'activation_level_label' => $incident->activation_level?->label(),
            'status' => $incident->status?->value,
            'status_label' => $incident->status?->label(),
            'declared_at' => $incident->declared_at?->toIso8601String(),
            'detected_at' => $incident->detected_at?->toIso8601String(),
            'closed_at' => $incident->closed_at?->toIso8601String(),
            'is_exercise' => (bool) $incident->is_exercise,
        ];
    }
}
