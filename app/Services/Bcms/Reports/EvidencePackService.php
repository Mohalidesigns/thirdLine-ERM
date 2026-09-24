<?php

namespace App\Services\Bcms\Reports;

use App\Enums\Bcms\IsoClauseRef;
use App\Models\Bcms\AuditLog;
use App\Models\Bcms\ClauseRef;
use App\Models\User;
use App\Services\Bcms\Compliance\ClauseComplianceMatrixService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use ThirdLine\Platform\Tenancy\TenantContext;
use ThirdLine\Reporting\DocumentRenderer;

/**
 * The ISO 22301 / CBN / DORA regulator evidence pack —
 * `docs/bcms/screens/evidence-pack-export.md`, phase-11-spec §3.
 *
 * EVERY PACK IS BUILT FROM STORED ROWS AND RECOMPUTES NOTHING (§3.1,
 * `EvidenceExport`'s own rule, applied here verbatim). This service calls
 * `ClauseComplianceMatrixService` — the SAME service the compliance matrix
 * screen renders — so the two surfaces cannot disagree about whether a given
 * clause is green.
 *
 * FAILURES ARE EXPORTED AS PROMINENTLY AS SUCCESSES. A pack that silently
 * omitted 9.2 because this system holds nothing for it would be the false
 * completeness criterion 12 exists to catch. Every section renders, red or
 * amber included, with the honest sentence in place of an artefact.
 *
 * NO SECOND SCORER, NO `bcms_report_runs` TABLE. Generation is recorded as a
 * `bcms_audit_logs` row with `event = pack.exported` (ADR 0021 §4) — that is
 * the entire "export log".
 */
class EvidencePackService
{
    public const FRAMEWORKS = ['iso22301', 'cbn_csf', 'cbn_open_banking', 'dora'];

    /** DORA is a crosswalk view over existing ISO refs — no `dora` export pack key exists (§3.4). */
    private const DORA_CROSSWALK = [
        IsoClauseRef::Iso22301_8_5_exercise,
        IsoClauseRef::Iso22301_8_4_3,
        IsoClauseRef::Iso22301_8_5_report,
        IsoClauseRef::Iso22320_incident,
        IsoClauseRef::Iso22301_8_2_2,
        IsoClauseRef::Iso22318_supply_chain,
    ];

    /**
     * Code review #3, D1: `bcms_corrective_actions.status` is a six-value
     * lifecycle (`CorrectiveActionStatus`) — open/in_progress/completed/
     * verified/overdue/accepted_risk — and only "completed" is stamped by a
     * dated column (`completed_at`) this pack can bound. "In progress" has
     * no date marker at all; "verified"/"accepted_risk" have their own
     * dates (`verified_at`/`accepted_at`) but the pack's own examiner
     * question (§3.3 item 5) only asks whether the action is open or done,
     * not the fuller workflow state — derived as `open`/`completed` from
     * `completed_at <= $asOf` alone, current state for the rest.
     */
    private const CORRECTIVE_ACTION_STATUS_REASON = 'Status is shown as open/completed only, derived from the '
        .'recorded completion date; the detailed workflow state (in progress, verified, accepted risk, overdue) '
        .'is read as currently recorded, not reconstructed for the period.';

    /**
     * Code review #4, E1: `bcms_findings.status` is `open|in_progress|
     * closed|accepted_risk` — `FindingService::close()` (:165-169) AND
     * `::acceptRisk()` (:179-184) both stamp `closed_at`, confirmed by
     * reading the write path directly, so `closed_at !== null && closed_at
     * <= $asOf` correctly derives open/closed EITHER way a finding was
     * shut. "In progress" has no date marker, and "accepted risk" vs a
     * plain "closed" is not distinguishable from `closed_at` alone — the
     * SAME open/completed-only shape `CORRECTIVE_ACTION_STATUS_REASON`
     * already states for corrective actions, restated for the finding
     * itself. Advisory A1, folded into the SAME line rather than a second
     * one: `description` is live content with no dated history either
     * (edited after `$asOf`, no trace) — "keep it minimal", one printed
     * reason covering both.
     */
    private const FINDING_CURRENT_STATE_REASON = 'Status is shown as open/closed only, derived from the recorded '
        .'closure date; the detailed classification (in progress, accepted risk) and the description text are '
        .'read as currently recorded, not reconstructed for the period.';

    public function __construct(private readonly ClauseComplianceMatrixService $matrix) {}

    /**
     * The preview: section count and how many of the eleven mandatory
     * records fall inside this framework, green/amber/red.
     *
     * @return array<string, mixed>
     */
    public function preview(string $framework): array
    {
        $sections = $this->sectionsFor($framework, now());

        $mandatory = collect($sections)->filter(fn (array $s) => $s['mandatory']);

        return [
            'framework' => $framework,
            'section_count' => count($sections),
            'mandatory_count' => $mandatory->count(),
            'mandatory_green' => $mandatory->where('state', 'green')->count(),
            'mandatory_amber' => $mandatory->where('state', 'amber')->count(),
            'mandatory_red' => $mandatory->where('state', 'red')->count(),
            // The RCSA-style sync/queue threshold, restated for section count
            // rather than row count (evidence-pack-export.md §2).
            'synchronous' => count($sections) <= 25,
        ];
    }

    /**
     * REPRODUCIBILITY (phase-11-spec §6 criterion 2): calling this twice over
     * the same period, with no write in between, returns an identical array
     * — this IS "section content"; `generate()`'s `generatedAt`/`generatedBy`
     * are stamped onto the PDF layout after this returns and never appear
     * here, so nothing needs stripping to compare two calls with `===`.
     * Every per-clause sufficiency read this composes over
     * (`ClauseComplianceMatrixService`) orders its own "latest row" and
     * "sort by X" queries with a primary-key tiebreaker for exactly this
     * reason — an ORDER BY on a non-unique column alone lets the database
     * return either side of a tie on a re-run.
     *
     * @param  ?Carbon  $asOf  B2 (gate 1 code review #1): the pack's own
     *                         `$to` date — threaded into
     *                         `ClauseComplianceMatrixService::build()` so
     *                         every "in the period"/"in the cycle"/"inside
     *                         its frequency" sufficiency rule answers
     *                         against the PERIOD THE PACK IS FOR, not
     *                         always today. A pack whose cover prints
     *                         "period: 2024-01-01 to 2024-12-31" must not
     *                         silently answer those rows against the
     *                         generation date — defaults to `now()` for a
     *                         caller that does not pass one (there is none
     *                         left in this codebase; `preview()` and
     *                         `generate()` both pass one explicitly).
     * @param  ?Carbon  $periodStart  R3 (gate 1 code review #2): the pack's
     *                                own `$from` date, so the "in the
     *                                period" rules (communication, AAR,
     *                                review) answer against `[$from, $asOf]`
     *                                rather than `$asOf`'s calendar year —
     *                                `null` (the default, used by every
     *                                caller that has no explicit `$from`,
     *                                i.e. `preview()`) falls back to
     *                                `$asOf`'s own calendar year inside
     *                                `ClauseComplianceMatrixService`, which
     *                                is the pre-R3 behaviour, preserved for
     *                                a caller with no real period.
     * @return list<array<string, mixed>>
     */
    /**
     * QA ruling on the `build()` programme picker: `bcms_programme_
     * obligations` carries no dated applicability history, so WHAT each
     * obligation currently says (`applies`, its rationale) cannot be
     * reconstructed for a past period, only WHICH programme governs it
     * (`ClauseComplianceMatrixService::programmeAsOf()`, which now bounds
     * that). Kept as its own standalone entry point (a `build()` of its
     * own) for a caller that wants ONLY this flag; `generate()` does NOT
     * call it — code review #3, A2 — it reads the SAME `build()`
     * `sectionsFor()` already runs, via `sectionsAndObligationsFor()`
     * below, rather than triggering a second one just for one boolean.
     */
    public function obligationsCurrentState(?Carbon $asOf = null, ?Carbon $periodStart = null): bool
    {
        $built = $this->matrix->build($asOf, $periodStart);

        return (bool) ($built['obligations_current_state'] ?? false);
    }

    public function sectionsFor(string $framework, ?Carbon $asOf = null, ?Carbon $periodStart = null): array
    {
        return $this->sectionsAndObligationsFor($framework, $asOf, $periodStart)['sections'];
    }

    /**
     * Code review #3, A2: ONE `build()` call shared by `sectionsFor()`'s
     * own flat-list contract (unchanged — still compared byte-for-byte by
     * the reproducibility tests, via the `sectionsFor()` wrapper above) and
     * `generate()`'s own need for the pack-level `obligations_current_state`
     * flag alongside it.
     *
     * @return array{sections: list<array<string, mixed>>, obligationsCurrentState: bool}
     */
    private function sectionsAndObligationsFor(string $framework, ?Carbon $asOf, ?Carbon $periodStart): array
    {
        $built = $this->matrix->build($asOf, $periodStart);
        $obligationsCurrentState = (bool) ($built['obligations_current_state'] ?? false);

        if ($built['empty_programme'] ?? true) {
            return ['sections' => [], 'obligationsCurrentState' => $obligationsCurrentState];
        }

        $allRows = collect($built['sections'])->flatten(1);

        if ($framework === 'dora') {
            $codes = collect(self::DORA_CROSSWALK)->map(fn (IsoClauseRef $r) => $r->value);

            return ['sections' => $allRows->whereIn('code', $codes)->values()->all(), 'obligationsCurrentState' => $obligationsCurrentState];
        }

        $packCodes = ClauseRef::query()->get(['code', 'export_packs'])
            ->filter(fn (ClauseRef $c) => in_array($framework, (array) ($c->export_packs ?? []), true))
            ->pluck('code');

        return [
            'sections' => $allRows->whereIn('code', $packCodes)->sortBy('code')->values()->all(),
            'obligationsCurrentState' => $obligationsCurrentState,
        ];
    }

    /**
     * Assemble and render one framework's pack for a period as a PDF, and
     * log the export. `$from`/`$to` scope the period stated on the cover;
     * every clause section reads terminal rows as of `$to` (B2, gate 1 code
     * review #1 — `sectionsFor()` now threads `$to` into
     * `ClauseComplianceMatrixService::build()` as `$asOf`, so a pack for a
     * PAST period genuinely differs from a live one, which is what makes a
     * re-run over the SAME period still reproduce the same content: nothing
     * written after `$to` can move a row that only ever looks at `$to` or
     * earlier).
     *
     * R3 (gate 1 code review #2): `$to` is the END of its own day
     * (23:59:59.999), not midnight — a bare `Carbon::parse($to)` reads as
     * 00:00, which silently drops everything dated on the last day of the
     * period from every "on or before `$asOf`" read. `$from` is likewise
     * the START of its day, so the two bounds together read as
     * `[$from 00:00:00, $to 23:59:59.999]` everywhere this pack composes
     * over a genuine period (`sectionsFor()`'s three "in the period" rules,
     * `cbnContent()`'s `dr_tests`/`incidents`); `drTestReport()` and the
     * majority of `ClauseComplianceMatrixService`'s own checks are
     * "as of `$to`" existence reads with no lower bound by design (see that
     * service's own docblock) and use `$periodEnd` alone.
     */
    public function generate(string $framework, string $from, string $to, User $actor): string
    {
        $periodEnd = Carbon::parse($to)->endOfDay();
        $periodStart = Carbon::parse($from)->startOfDay();
        $asOf = $periodEnd;
        $fromDate = $periodStart;
        // A2: one `build()`, not two — `sectionsFor($framework, $asOf,
        // $fromDate)` plus a separate `obligationsCurrentState($asOf,
        // $fromDate)` used to trigger the matrix twice for one export.
        $sectionsAndObligations = $this->sectionsAndObligationsFor($framework, $asOf, $fromDate);
        $sections = $sectionsAndObligations['sections'];
        $renderer = app(DocumentRenderer::class);
        $organization = \App\Models\Organization::query()->find(TenantContext::organizationId());

        $bytes = $renderer->pdf('reports.pdf.bcms-evidence-pack', [
            // The master layout (`reports.pdf.layout`) reserves `sections`
            // for its own table-of-contents rendering — `PackExporter`'s own
            // comment names this exact collision. This pack's per-clause
            // rows are shipped as `packSections` instead, and `title`/
            // `branding`/`generatedAt` are the layout's other unconditional
            // requirements (a 500 on every export until this was added —
            // never exercised end to end, per phase-11-notes.md's own
            // "verify at integration" flag on this generator).
            'title' => $this->frameworkLabel($framework),
            'subtitle' => "Evidence pack \u{2014} {$from} to {$to}",
            'branding' => $renderer->branding($organization),
            'generatedAt' => now(),
            'generatedBy' => $actor->name,
            'periodAsAt' => $asOf,
            'periodLabel' => "{$from} to {$to}",
            'framework' => $framework,
            'framework_label' => $this->frameworkLabel($framework),
            'from' => $from,
            'to' => $to,
            'packSections' => $sections,
            'obligationsCurrentState' => $sectionsAndObligations['obligationsCurrentState'],
            'organization' => $organization,
            // B15 (gate 1 code review #1, criterion 2's own examiner test —
            // "show me your last DR test report and the corrective actions
            // arising"): the last DR test as of the period end, and its
            // full corrective-action chain, on every framework (the spec's
            // examiner walkthrough does not name one framework only).
            'drTestReport' => $this->drTestReport($asOf),
            // §3.3: the CBN CSF pack's own examiner order — the DR/failover
            // register with targets vs last actuals and overdue systems
            // named (item 1), DR/failover tests for the period with
            // actual-vs-target and breaches (item 2), and the incident
            // register with notification timings against the 24-hour
            // window (item 4). Items 3 (drill/cyber AARs), 6 (board
            // reporting) and 7 (vendor continuity) are already the standard
            // clause sections above (8.5.report, 9.3, the supply-chain
            // row) — not duplicated here as a second copy.
            'cbnContent' => $framework === 'cbn_csf' ? $this->cbnContent($fromDate, $asOf) : null,
        ]);

        $this->logExport($framework, $from, $to, $sections, $actor);

        return $bytes;
    }

    /**
     * The last DR test as of `$asOf`, with its corrective-action chain — the
     * finding carries `dr_test_id` (spec's own examiner-walkthrough note),
     * so the chain is reached directly, never a second lookup path.
     *
     * R3/A6 (gate 1 code review #2): the finding lookup now carries an
     * explicit order (`raised_at`/`id` desc — the most recently raised
     * finding against this test, tie-broken by id) rather than an
     * unordered `first()`, which let a re-run pick either finding when a DR
     * test carried more than one; corrective-action ageing is computed
     * against `$asOf`, never `now()` — a pack for a past period must not
     * report today's age for an action that was still open at period end.
     *
     * Code review #3, D1: the FINDING lookup and the CORRECTIVE ACTION set
     * were both still unbounded — a finding raised, or an action opened,
     * after `$asOf` still counted toward a past pack. `raised_at <= $asOf`
     * (null-safe) and `created_at <= $asOf` close that. `completed_at`/
     * `age_days` are rendered ONLY when `completed_at <= $asOf` — an
     * action completed AFTER the period end is reported as still OPEN at
     * period end, with its age measured to `$asOf`, exactly as if the
     * closure had not happened yet (which, as of that date, it had not).
     * `status` is derived `open`/`completed` from that same boolean — see
     * `CORRECTIVE_ACTION_STATUS_REASON`'s own docblock for why the fuller
     * workflow state cannot be, and is labelled `current_state` instead.
     *
     * @return array<string, mixed>|null
     */
    public function drTestReport(Carbon $asOf): ?array
    {
        $test = \App\Models\Bcms\DrTest::query()
            ->where('test_date', '<=', $asOf)
            ->orderByDesc('test_date')->orderByDesc('id')->first();

        if ($test === null) {
            return null;
        }

        $finding = \App\Models\Bcms\Finding::query()->where('dr_test_id', $test->getKey())
            ->where(fn ($q) => $q->whereNull('raised_at')->orWhere('raised_at', '<=', $asOf))
            ->orderByDesc('raised_at')->orderByDesc('id')->first();

        return [
            'test_type' => $test->test_type?->value,
            'test_date' => $test->test_date?->toDateString(),
            'rto_actual_minutes' => $test->rto_actual_minutes,
            'rpo_actual_minutes' => $test->rpo_actual_minutes,
            'met_objectives' => $test->met_objectives,
            'rollback_required' => (bool) $test->rollback_required,
            'notes' => $test->notes,
            'finding' => $finding === null ? null : (function () use ($finding, $asOf) {
                $closedByAsOf = $finding->closed_at !== null && $finding->closed_at->lte($asOf);

                return [
                    'reference' => $finding->reference,
                    'description' => $finding->description,
                    'status' => $closedByAsOf ? 'closed' : 'open',
                    'current_state' => true,
                    'current_state_reason' => self::FINDING_CURRENT_STATE_REASON,
                ];
            })(),
            'corrective_actions' => $finding === null ? [] : $finding->correctiveActions()
                ->where('created_at', '<=', $asOf)
                ->orderBy('id')
                ->get()
                ->map(function (\App\Models\Bcms\CorrectiveAction $a) use ($asOf) {
                    $completedByAsOf = $a->completed_at !== null && $a->completed_at->lte($asOf);

                    return [
                        'title' => $a->title,
                        'status' => $completedByAsOf ? 'completed' : 'open',
                        'due_date' => $a->due_date?->toDateString(),
                        'completed_at' => $completedByAsOf ? $a->completed_at->toDateString() : null,
                        // Ageing, the same fact the spec's §3.3 item 5 asks
                        // for — never invented for a closed action, and
                        // measured "as of `$asOf`" (the pack's own period
                        // end), never `now()` — a re-run of a past-period
                        // pack must not report a larger age each day it is
                        // regenerated. An action completed AFTER `$asOf` is
                        // treated as still open AT `$asOf`, so it ages too.
                        'age_days' => $completedByAsOf ? null : $a->due_date?->diffInDays($asOf, false),
                        'current_state' => true,
                        'current_state_reason' => self::CORRECTIVE_ACTION_STATUS_REASON,
                    ];
                })
                ->all(),
        ];
    }

    /**
     * §3.3's `cbn_csf` examiner-order content this pack does not already
     * carry as a standard clause section — see `generate()`'s own comment
     * for which items are deliberately not duplicated here.
     *
     * R3 (gate 1 code review #2): `dr_systems` is a REGISTER SNAPSHOT — the
     * table carries only `last_test_date`/`next_test_due` pointer columns,
     * not a history of what "next due" read on any past day — so it cannot
     * be reconstructed for a past period and is reported, and labelled, as
     * current state as at generation time (`dr_systems_as_of`), the same
     * way the board pack labels its own live sections; `dr_tests` and
     * `incidents` genuinely happened inside `[$from, $to]` and ARE bounded
     * by it (`generate()` passes `$to` as the end of its own day, so an
     * incident detected on the last day of the period is included).
     *
     * A6 (gate 1 code review #2): notifications for every incident in the
     * period are fetched in ONE query, grouped by `incident_id`, rather
     * than one query per incident.
     *
     * @return array<string, mixed>
     */
    public function cbnContent(Carbon $from, Carbon $to): array
    {
        $drSystems = \App\Models\Bcms\DrSystem::query()->orderBy('name')->get()
            ->map(fn (\App\Models\Bcms\DrSystem $s) => [
                'name' => $s->name,
                'rto_target_hours' => $s->rto_target_hours,
                'rpo_target_minutes' => $s->rpo_target_minutes,
                'last_test_date' => $s->last_test_date?->toDateString(),
                'last_test_rto_actual_minutes' => $s->last_test_rto_actual_minutes,
                'last_test_met_objectives' => $s->last_test_met_objectives,
                'next_test_due' => $s->next_test_due?->toDateString(),
                'overdue' => $s->next_test_due !== null && $s->next_test_due->isPast(),
            ])
            ->all();

        $drTests = \App\Models\Bcms\DrTest::query()
            ->whereBetween('test_date', [$from, $to])
            ->orderBy('test_date')->orderBy('id')
            ->get()
            ->map(fn (\App\Models\Bcms\DrTest $t) => [
                'test_type' => $t->test_type?->value,
                'test_date' => $t->test_date?->toDateString(),
                'rto_actual_minutes' => $t->rto_actual_minutes,
                'rpo_actual_minutes' => $t->rpo_actual_minutes,
                'met_objectives' => $t->met_objectives,
                'breach' => $t->met_objectives === false,
            ])
            ->all();

        // ADR 0020 §4: a real incident register, never a crisis drill —
        // `is_exercise = false` on every aggregate.
        $incidentModels = \App\Models\Bcms\Incident::query()
            ->where('is_exercise', false)
            ->whereBetween('detected_at', [$from, $to])
            ->orderBy('detected_at')->orderBy('id')
            ->get();

        $notificationsByIncident = \App\Models\Bcms\IncidentNotification::query()
            ->whereIn('incident_id', $incidentModels->pluck('id'))
            ->orderBy('id')
            ->get()
            ->groupBy('incident_id');

        $incidents = $incidentModels
            ->map(function (\App\Models\Bcms\Incident $i) use ($notificationsByIncident) {
                $notifications = ($notificationsByIncident->get($i->getKey()) ?? collect())
                    ->map(fn (\App\Models\Bcms\IncidentNotification $n) => [
                        'regulator' => $n->regulator?->value,
                        'due_at' => $n->due_at?->toIso8601String(),
                        'submitted_at' => $n->submitted_at?->toIso8601String(),
                        // "Against the 24-hour window" (§3.3 item 4) — the
                        // fact of whether the submission beat its OWN
                        // recorded deadline, never a second, re-derived
                        // 24-hour calculation `NotificationService::classify()`
                        // already owns.
                        'within_window' => $n->due_at !== null && $n->submitted_at !== null
                            ? $n->submitted_at->lte($n->due_at)
                            : null,
                    ])
                    ->values()
                    ->all();

                return [
                    'reference' => $i->reference,
                    'title' => $i->title,
                    'detected_at' => $i->detected_at?->toIso8601String(),
                    'declared_at' => $i->declared_at?->toIso8601String(),
                    'status' => $i->status?->value,
                    'notifications' => $notifications,
                ];
            })
            ->all();

        return [
            'dr_systems' => $drSystems,
            'dr_systems_as_of' => now()->toDateString(),
            'dr_systems_label' => 'Current state as at '.now()->toDateString().', not reconstructed for the period — '
                .'the DR/failover register carries only its latest test and next-due date, not a history of what '
                .'"next due" read on any past day.',
            'dr_tests' => $drTests,
            'incidents' => $incidents,
            // Code review #3, A6: `incidents` is bounded to `[$from, $to]`
            // by `detected_at`, but two fields on it are still CURRENT
            // state, unbounded — an incident's own `status` (open/
            // contained/recovering/closed/cancelled) moves after
            // `detected_at`, and a notification's `submitted_at` may not
            // yet have happened as of `$to` and read as of TODAY instead.
            'incidents_current_state_label' => 'Incident status and notification submission timing are shown as '
                .'currently recorded, not reconstructed for the period.',
        ];
    }

    public function filename(string $framework): string
    {
        return \Illuminate\Support\Str::slug($framework.'-evidence-pack-'.now()->format('Y-m-d')).'.pdf';
    }

    /** @param  list<array<string, mixed>>  $sections */
    private function logExport(string $framework, string $from, string $to, array $sections, User $actor): void
    {
        $organizationId = TenantContext::organizationId();

        try {
            AuditLog::query()->create([
                'organization_id' => $organizationId,
                'auditable_type' => 'bcms_report_pack',
                'auditable_id' => (int) $organizationId,
                'event' => 'pack.exported',
                'after' => [
                    'framework' => $framework,
                    'period' => ['from' => $from, 'to' => $to],
                    // A2 (gate 1 code review #1): spec §3.1's own generation
                    // record asks for "the actor, and the parameters
                    // (framework, period, org node) in after" — this pack has
                    // no branch dimension (it is organisation-wide), so the
                    // org node IS the organisation itself, named so a reader
                    // does not have to cross-reference the row's own
                    // `organization_id` column to answer "which node".
                    'org_node' => [
                        'organization_id' => $organizationId,
                        'organization_name' => \App\Models\Organization::query()->find($organizationId)?->name,
                    ],
                    'section_summary' => [
                        'total' => count($sections),
                        'green' => collect($sections)->where('state', 'green')->count(),
                        'amber' => collect($sections)->where('state', 'amber')->count(),
                        'red' => collect($sections)->where('state', 'red')->count(),
                    ],
                ],
                'actor_id' => $actor->getKey(),
                'actor_label' => $actor->name,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Auditing never fails the write (development standard §7).
            Log::error('BCMS evidence pack export could not be logged', [
                'framework' => $framework, 'exception' => get_class($e),
            ]);

            // A2: a failed pack-export audit is counted where BcmsWatchdog
            // already looks for every other failed BCMS audit write
            // (`BcmsAuditable`'s own catch does the same) — a log line
            // nothing reads is indistinguishable from silence.
            try {
                Cache::add(\App\Models\Bcms\AuditLog::AUDIT_FAILURE_CACHE_KEY, 0, now()->addDays(30));
                Cache::increment(\App\Models\Bcms\AuditLog::AUDIT_FAILURE_CACHE_KEY);
            } catch (\Throwable) {
                // Nothing further to do: the Log::error above is the fallback.
            }
        }
    }

    /**
     * The export log: `bcms_audit_logs` rows where `event = pack.exported`,
     * for this tenant. There is no `bcms_report_runs` table (ADR 0021 §4).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function log(): Collection
    {
        return AuditLog::query()
            ->where('auditable_type', 'bcms_report_pack')
            ->where('event', 'pack.exported')
            ->with('actor:id,name')
            ->latest('created_at')
            ->limit(50)
            ->get()
            ->map(fn (AuditLog $row) => $this->logRow($row));
    }

    /** @return array<string, mixed> */
    private function logRow(AuditLog $row): array
    {
        return [
            'framework' => $row->after['framework'] ?? null,
            'period' => $row->after['period'] ?? null,
            'section_summary' => $row->after['section_summary'] ?? null,
            'requested_by' => $row->actor->name ?? $row->actor_label,
            'requested_at' => $row->created_at?->toIso8601String(),
        ];
    }

    private function frameworkLabel(string $framework): string
    {
        return match ($framework) {
            'iso22301' => 'ISO 22301:2019 — full clause bundle',
            'cbn_csf' => 'CBN Risk-Based Cybersecurity Framework',
            'cbn_open_banking' => 'CBN Open Banking Operational Guidelines',
            'dora' => 'DORA benchmark crosswalk',
            default => $framework,
        };
    }
}
