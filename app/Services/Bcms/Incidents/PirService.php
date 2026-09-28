<?php

namespace App\Services\Bcms\Incidents;

use App\Enums\Bcms\IncidentLogEntryType;
use App\Enums\Bcms\IsoClauseRef;
use App\Models\Bcms\Aar;
use App\Models\Bcms\Finding;
use App\Models\Bcms\Incident;
use App\Models\Bcms\PlanActivation;
use App\Models\Bcms\PlanSection;
use App\Models\User;
use App\Services\Bcms\BcmsSettings;
use App\Services\Bcms\Exercises\AarService;
use App\Services\Bcms\Integration\ErmBridge;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The post-incident review — an `Aar` row with `incident_id` set (ADR 0020
 * §1), reusing the shared table, `FindingService` and `CorrectiveActionService`
 * verbatim (criterion 9: no second findings/CAPA register). This class holds
 * only what genuinely differs from the exercise AAR (clause map §4.2's delta
 * table), coordinated by READING `AarService`, never editing it:
 *
 *  - no observer scores, no injects — those two of the base screen's eleven
 *    conditions have no PIR equivalent;
 *  - "plan versus actual" (per activated plan section) replaces "objectives
 *    versus outcomes";
 *  - the timeline is `bcms_incident_log`, not `bcms_exercise_timeline`;
 *  - the clause stamp is `iso22320.incident_response`, NEVER
 *    `iso22301.8.5.report` — stamping a real incident 8.5 would put it in the
 *    exercise-programme evidence pack and overstate the testing programme
 *    (ADR 0020, adopted from the analyst verbatim);
 *  - `carried_to_occurrence_id` is never written from here — that column is
 *    the exercise engine's alone.
 *
 * THE GATE IS EIGHT CONDITIONS, NOT ELEVEN, and the screen is required to say
 * which three are dropped and why (clause map §4.2's closing paragraph) —
 * that copy lives on the frontend; this class is the live computation the
 * copy describes.
 */
class PirService
{
    public function __construct(
        private AarService $aarService,
        private ErmBridge $ermBridge,
        private BcmsSettings $settings,
    ) {}

    public function ensureDraftFor(Incident $incident): Aar
    {
        return $this->aarService->ensureDraftForIncident($incident);
    }

    /**
     * A3 (code review #3 advisory): whether `finalise()`'s ERM mirror
     * attempted a write and it failed, so `IncidentReviewController::
     * finalise()` can flash a warning rather than leave the officer
     * believing the confirmed figure reached the loss register. Read
     * immediately after `finalise()` on the SAME `PirService` instance —
     * this proxies `ErmBridge`'s own per-call flag, reset on every
     * `mirrorRealisedLoss()` call.
     */
    public function mirrorFailed(): bool
    {
        return $this->ermBridge->lastMirrorFailed();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Aar $aar, array $attributes, ?User $by = null): Aar
    {
        if (! $aar->isPostIncident()) {
            throw new InvalidArgumentException('This is not a post-incident review.');
        }

        // Same frozen-on-final rule and the same allowed-field set as the
        // base screen; PIR-specific extras (plan-section verdicts, the
        // tenth section) live inside `quantitative_results`, which is
        // already one of them. `dr_invocations.*.failback.at` — the one
        // datetime this JSON blob carries with no Eloquent cast — is
        // normalised inside `AarService::update()` itself
        // (see the comment there): this method has no caller of its own
        // (`bcms.aars.update` reaches `AarService::update()` directly,
        // per this class's own docblock and `IncidentReviewController`'s),
        // so normalising here would not be on the path a real request
        // takes.
        return $this->aarService->update($aar, $attributes, $by);
    }

    /**
     * The eight-condition gate (clause map §4.2). Live — the same method
     * renders the checklist and is called again inside `finalise()`.
     *
     * @return list<array{key: string, label: string, met: bool, message: string}>
     */
    public function conditions(Aar $aar): array
    {
        if (! $aar->isPostIncident()) {
            throw new InvalidArgumentException('This is not a post-incident review.');
        }

        $incident = $aar->incident;
        $qr = (array) $aar->quantitative_results;
        $out = [];

        $out[] = $this->condition(
            '1_lifecycle', 'The incident has a recorded detection, declaration and closure',
            $incident !== null && $incident->detected_at !== null && $incident->declared_at !== null && $incident->closed_at !== null,
            'The incident record is missing a detection, declaration or closure timestamp.',
        );

        $out[] = $this->condition(
            '2_narrative', 'Summary, what worked and what did not are all recorded',
            trim((string) $aar->summary) !== '' && trim((string) $aar->what_worked) !== '' && trim((string) $aar->what_failed) !== '',
            '"What did not work" empty on anything but a clean recovery is not credible.',
        );

        $timelineCount = $incident === null ? 0 : $incident->entries()
            ->whereIn('entry_type', ['decision', 'escalation'])
            ->count();
        $out[] = $this->condition(
            '3_timeline', 'The decision log has at least one decision or escalation entry',
            $timelineCount > 0,
            'The decision log has no decision or escalation entry yet.',
        );

        $planSections = collect($qr['plan_sections'] ?? []);
        $out[] = $this->condition(
            '4_plan_sections', 'Every activated plan section has a held/did-not-hold verdict',
            $planSections->isEmpty() || $planSections->every(fn (array $s) => filled($s['verdict'] ?? null)),
            'One or more activated plan sections have no recorded verdict yet.',
        );

        $out[] = $this->condition(
            '5_dispositioned', 'Every "did not hold" verdict is linked to a finding or has a reason',
            $planSections->every(fn (array $s) => ($s['verdict'] ?? null) !== 'did_not_hold'
                || filled($s['finding_reference'] ?? null) || filled($s['disposition_note'] ?? null)),
            'A plan section that did not hold under a real event has no linked finding and no disposition note.',
        );

        $findingExists = Finding::query()->where('aar_id', $aar->getKey())->exists();
        $anyFailed = $planSections->contains(fn (array $s) => ($s['verdict'] ?? null) === 'did_not_hold');
        $out[] = $this->condition(
            '6_findings', 'A plan section that did not hold has a finding',
            ! $anyFailed || $findingExists,
            'A plan section did not hold and no finding has been raised for it.',
        );

        $metrics = (array) ($qr['metrics'] ?? []);
        $required = ['time_to_detect_minutes', 'time_to_declare_minutes', 'time_to_activate_minutes'];
        $notMeasured = collect($qr['not_measured'] ?? [])->pluck('metric')->all();
        $out[] = $this->condition(
            '7_metrics', 'The core incident-response timings are recorded, or explained as not measured',
            collect($required)->every(fn ($k) => array_key_exists($k, $metrics) || in_array($k, $notMeasured, true)),
            'One or more of time-to-detect, time-to-declare or time-to-activate is missing and not listed as not measured.',
        );

        $obligationsOpen = app(NotificationService::class)->overdueOrOpenCount($incident ?? new Incident);
        $out[] = $this->condition(
            '8_obligations', 'Every regulatory obligation is submitted or reassessed as not owed',
            $obligationsOpen === 0,
            "{$obligationsOpen} regulatory obligation(s) still open — the same gate stand-down already applied.",
        );

        return $out;
    }

    /** @return array{key: string, label: string, met: bool, message: string} */
    private function condition(string $key, string $label, bool $met, string $message): array
    {
        return ['key' => $key, 'label' => $label, 'met' => $met, 'message' => $met ? '' : $message];
    }

    /**
     * Populate the "plan versus actual" section from the plans this incident
     * activated, merged against whatever verdicts are already recorded.
     *
     * A FINAL REPORT IS FROZEN, NOT RE-DERIVED (same family as Phase 9's
     * `AarService::refreshComputedSections()` guard — see
     * `docs/bcms/phase-9-notes.md` §8 item 1).
     *
     * ADVISORY A9 (Gate 1 re-gate): NEVER PERSISTS FROM A READ. The merge is
     * applied to `$aar` IN MEMORY ONLY (`forceFill()`, no `save()`) — the
     * caller passes this SAME `$aar` instance on to the presenter, which
     * sees the merge without anything being written. Before this, every
     * `IncidentReviewController::show()` call — a GET — wrote the merge with
     * `forceFill()->saveQuietly()`, both bypassing the audit trail
     * `saveQuietly()` is named for skipping and touching the row on a
     * request that should not write at all. `start()` is the one EXPLICIT
     * action that persists the initial merge (`$persist = true`), through a
     * normal, audited `update()`.
     *
     * `PlanActivation.plan_id` already names the SPECIFIC, immutable Plan
     * row this incident activated — approving a new version of a plan
     * creates a new `bcms_plans` row rather than editing the approved one in
     * place (`Phase3PlanBuilderTest::an_approved_version_is_immutable_and_
     * still_printable`), so `PlanSection::whereIn('plan_id', $planIds)`
     * below already reads the sections as they stood AT ACTIVATION, not
     * whatever the plan looks like today — there is no separate "current
     * version" this could drift onto.
     *
     * @return list<array<string, mixed>>
     */
    public function refreshPlanSections(Aar $aar, bool $persist = false): array
    {
        if ($aar->status === 'final') {
            $qr = (array) $aar->quantitative_results;

            return (array) ($qr['plan_sections'] ?? []);
        }

        $incident = $aar->incident;

        if ($incident === null) {
            return [];
        }

        $planIds = PlanActivation::query()->where('incident_id', $incident->getKey())->pluck('plan_id');
        $sections = PlanSection::query()->whereIn('plan_id', $planIds)->get();

        $existing = collect((array) $aar->quantitative_results)->get('plan_sections', []);
        $existingByKey = collect($existing)->keyBy('section_id');

        $rows = $sections->map(function (PlanSection $s) use ($existingByKey) {
            $prior = $existingByKey->get($s->getKey(), []);

            return array_merge($prior, [
                'section_id' => $s->getKey(),
                'title' => $s->title,
                'verdict' => $prior['verdict'] ?? null,
                'note' => $prior['note'] ?? null,
                'finding_reference' => $prior['finding_reference'] ?? null,
                'disposition_note' => $prior['disposition_note'] ?? null,
            ]);
        })->values()->all();

        $qr = (array) $aar->quantitative_results;
        $qr['plan_sections'] = $rows;

        if ($persist) {
            $aar->update(['quantitative_results' => $qr]);
        } else {
            $aar->forceFill(['quantitative_results' => $qr]);
        }

        return $rows;
    }

    /**
     * Display labels for the two computed timings (compliance check,
     * 2026-09-25, `phase-10-notes.md` "Compliance check: PIR timing
     * metrics"). `declared_at` and `activated_at` are the moments BCMS
     * RECORDED a declaration/activation, not the moment a crisis team
     * actually decided something by telephone — "(as recorded)" says so
     * wherever the payload carries a label, rather than leaving the figure
     * to read as the decision moment itself. Written into
     * `quantitative_results.metric_labels` on every `refreshMetrics()` call,
     * present whether or not the metric ended up `not_measured`, so a
     * reason sits next to the same label the value would have used.
     *
     * @var array<string, string>
     */
    public const METRIC_LABELS = [
        'time_to_declare_minutes' => 'Detection to declaration (as recorded, whole minutes, rounded down)',
        'time_to_activate_minutes' => 'Declaration to first plan activation (as recorded, whole minutes, rounded down)',
    ];

    /**
     * Gap 3 — condition 7's three timings, computed rather than left for a
     * human to type into a read-only field. `IncidentReviewController::
     * start()` persists this once; `show()` refreshes it in memory on every
     * read, the same split `refreshPlanSections()` uses, so a late-recorded
     * `declared_at` or a plan activated after the review was opened is
     * reflected right up to finalisation.
     *
     * THREE TIMESTAMPS, THREE MEANINGS (`Incident`'s own docblock: "the
     * regulatory clock runs from detection, the crisis team's response time
     * from declaration"):
     *
     *  - `time_to_detect_minutes` is NEVER computed. BCMS stores no
     *    independently recorded moment the incident actually began —
     *    `detected_at` IS the earliest fact this product has, so there is
     *    nothing to measure a detection lag against. Always `not_measured`.
     *  - `time_to_declare_minutes` = `declared_at` minus `detected_at`.
     *  - `time_to_activate_minutes` = the incident's first `bcms_plan_
     *    activations` row (by `activated_at`) minus `declared_at`.
     *
     * A missing input is a missing metric — `not_measured` plus a named
     * reason, never a zero standing in for "nothing recorded yet"
     * (standard §5). Compliance check, 2026-09-25, added three more rules
     * `not_measured` also has to cover, all in `wholeMinutesBetween()` or
     * below:
     *
     *  1. **Whole minutes, never a float.** Carbon 3.13.2's `diffInMinutes()`
     *     returns a signed float (`12.4833…`) — this method rounds down from
     *     seconds instead, so the stored value matches its own `int` type.
     *  2. **A negative interval is never a metric.** `declare()` enforces
     *     `detected_at <= declared_at` and an activation is created after
     *     its incident, so a negative value can only come from legacy,
     *     seeded or hand-edited data — it still goes to `not_measured`,
     *     naming both recorded times, never silently as a metric.
     *  3. **No `created_at` fallback for a missing `declared_at`.** ADR
     *     0020's fallback exists to START A REGULATORY CLOCK, where an early
     *     start is the safe error; inside a metric labelled "declare" the
     *     same substitution would be an unlabelled proxy for a fact the
     *     record does not have. `not_measured` names the gap instead.
     *
     * @return array<string, int>
     */
    public function refreshMetrics(Aar $aar, bool $persist = false): array
    {
        if ($aar->status === 'final') {
            $qr = (array) $aar->quantitative_results;

            return (array) ($qr['metrics'] ?? []);
        }

        $incident = $aar->incident;

        if ($incident === null) {
            return [];
        }

        $qr = (array) $aar->quantitative_results;
        $metrics = (array) ($qr['metrics'] ?? []);
        $ours = ['time_to_detect_minutes', 'time_to_declare_minutes', 'time_to_activate_minutes'];
        $notMeasured = collect($qr['not_measured'] ?? [])
            ->reject(fn (array $n) => in_array($n['metric'] ?? null, $ours, true))
            ->values();

        foreach ($ours as $key) {
            unset($metrics[$key]);
        }

        // Examiner-appropriate wording (compliance check table) — no column
        // names, and never implying the value was zero.
        $notMeasured->push([
            'metric' => 'time_to_detect_minutes',
            'reason' => 'Not measured. The system records when the incident was detected, not when it '
                .'began, so the time taken to detect it cannot be calculated. If the start time is '
                .'known, state it in the review and in the regulatory report.',
        ]);

        if ($incident->detected_at === null) {
            $notMeasured->push([
                'metric' => 'time_to_declare_minutes',
                'reason' => 'Not measured. The incident record has no detection time.',
            ]);
        } elseif ($incident->declared_at === null) {
            // Rule 3 — no `created_at` fallback inside the metric.
            $notMeasured->push([
                'metric' => 'time_to_declare_minutes',
                'reason' => 'Not measured. The incident record has no declaration time. The time the '
                    .'record was created is not used in its place.',
            ]);
        } else {
            $minutes = $this->wholeMinutesBetween($incident->detected_at, $incident->declared_at);

            if ($minutes === null) {
                // Rule 2 — a negative interval never reaches `metrics`.
                $notMeasured->push([
                    'metric' => 'time_to_declare_minutes',
                    'reason' => sprintf(
                        'Not measured. The recorded times are out of order: declaration %s is earlier '
                            .'than detection %s. Check the incident record.',
                        $this->formatWithTenantTimezone($incident->declared_at, $incident->organization_id),
                        $this->formatWithTenantTimezone($incident->detected_at, $incident->organization_id),
                    ),
                ]);
            } else {
                $metrics['time_to_declare_minutes'] = $minutes;
            }
        }

        $firstActivation = PlanActivation::query()
            ->where('incident_id', $incident->getKey())
            ->orderBy('activated_at')
            ->first();

        if ($incident->declared_at === null) {
            // Not in the compliance table (which only names "no activation"
            // and "out of order" for this metric) — added for the same
            // reason as `time_to_declare_minutes`'s own missing-input case:
            // a metric with no start time is not measurable, and saying so
            // beats a silent absence.
            $notMeasured->push([
                'metric' => 'time_to_activate_minutes',
                'reason' => 'Not measured. The incident record has no declaration time. The time the '
                    .'record was created is not used in its place.',
            ]);
        } elseif ($firstActivation === null) {
            $notMeasured->push([
                'metric' => 'time_to_activate_minutes',
                'reason' => 'Not measured. No continuity plan was activated during this incident.',
            ]);
        } else {
            $minutes = $this->wholeMinutesBetween($incident->declared_at, $firstActivation->activated_at);

            if ($minutes === null) {
                $notMeasured->push([
                    'metric' => 'time_to_activate_minutes',
                    'reason' => sprintf(
                        'Not measured. The recorded times are out of order: activation %s is earlier '
                            .'than declaration %s. Check the incident record.',
                        $this->formatWithTenantTimezone($firstActivation->activated_at, $incident->organization_id),
                        $this->formatWithTenantTimezone($incident->declared_at, $incident->organization_id),
                    ),
                ]);
            } else {
                $metrics['time_to_activate_minutes'] = $minutes;
            }
        }

        $qr['metrics'] = $metrics;
        $qr['not_measured'] = $notMeasured->values()->all();
        $qr['metric_labels'] = self::METRIC_LABELS;

        if ($persist) {
            $aar->update(['quantitative_results' => $qr]);
        } else {
            $aar->forceFill(['quantitative_results' => $qr]);
        }

        return $metrics;
    }

    /**
     * Whole minutes, rounded down from seconds — never the float
     * `diffInMinutes()` returns on Carbon 3.13.2 (compliance check rule 1).
     * Null if `$end` is before `$start`: a negative interval must never
     * reach a metric (rule 2); the caller sends it to `not_measured` naming
     * both recorded times instead.
     */
    private function wholeMinutesBetween(Carbon $start, Carbon $end): ?int
    {
        $seconds = (float) $start->diffInSeconds($end, false);

        if ($seconds < 0.0) {
            return null;
        }

        return (int) floor($seconds / 60);
    }

    /**
     * A1 — an out-of-order reason quotes two stored instants, and a bare
     * `toDateTimeString()` prints them in UTC with no marker saying so, which
     * reads as local wall-clock time to whoever is looking at it. Rendered in
     * the tenant's own BCMS timezone (`BcmsSettings::timezone()`, which
     * itself falls back to `config('bcms.defaults.timezone')` when the
     * tenant has never saved one — never `now()`'s process timezone), with
     * the abbreviation or offset shown so the two figures are never
     * ambiguous about which zone they are in.
     */
    private function formatWithTenantTimezone(Carbon $moment, ?int $organizationId): string
    {
        return $moment->copy()->setTimezone($this->settings->timezone($organizationId))->format('Y-m-d H:i:s T');
    }

    /**
     * The person who ran the incident response should not be the sole
     * signer of the review that judges it (`pir-post-incident-review.md`
     * §1) — the same principle `AarService::approverAllowed()` applies to an
     * exercise's facilitator, run here against the incident's own actors
     * instead: `incident.declared_by`, and anyone who logged a `decision`
     * entry in the incident's own decision log. There is no PIR equivalent
     * of `occurrence.facilitator_id` (no single "ran it" role exists on a
     * real incident the way a facilitator role names one on an exercise),
     * so both people the spec names are checked, and — unlike the exercise
     * rule's ladder-level threshold, which has no PIR equivalent either —
     * this is always blocking: a real incident has no "low enough stakes"
     * tier that would make the separation of duties optional.
     *
     * Deliberately NOT added to `AarService::approverAllowed()` itself: this
     * class is built to coordinate with `AarService` by READING it, never
     * editing it (class docblock).
     *
     * PUBLIC (A4, code review #3 advisory): `IncidentPresenter::review()`
     * reads this too, so `can.approve` reflects the same rule `finalise()`
     * enforces — a barred approver must not see a Finalise control that
     * `finalise()` would then refuse, which is a worse experience than not
     * offering it at all and tells them nothing about WHY until they try.
     *
     * @return array{allowed: bool, reason: ?string}
     */
    public function pirApproverAllowed(Aar $aar, User $approver): array
    {
        $incident = $aar->incident;

        if ($incident === null) {
            return ['allowed' => true, 'reason' => null];
        }

        $ranTheResponse = (int) $incident->declared_by === (int) $approver->getKey()
            || $incident->entries()
                ->where('entry_type', IncidentLogEntryType::Decision->value)
                ->where('logged_by', $approver->getKey())
                ->exists();

        if (! $ranTheResponse) {
            return ['allowed' => true, 'reason' => null];
        }

        return [
            'allowed' => false,
            'reason' => 'You declared this incident or logged a decision during its response. A '
                .'different approver must finalise this review — the person who ran the response '
                .'should not be the sole signer of the review that judges it.',
        ];
    }

    /**
     * @param  int  $realisedLossMinor  The PIR-CONFIRMED realised loss, in
     *                                  minor currency units (0 = confirmed no
     *                                  realised loss) — REQUIRED, per the
     *                                  finalise contract (Gate 1 re-gate
     *                                  defect 7). Stored on the review, never
     *                                  guessed from the declaration-time
     *                                  `estimated_impact_minor`, which the
     *                                  officer set in the first two minutes
     *                                  of the incident and this review's own
     *                                  conditions do not hold anyone to.
     */
    public function finalise(Aar $aar, User $by, int $realisedLossMinor): Aar
    {
        if (! $aar->isPostIncident()) {
            throw new InvalidArgumentException('This is not a post-incident review.');
        }

        if ($realisedLossMinor < 0) {
            throw new InvalidArgumentException('The realised loss cannot be negative.');
        }

        return DB::transaction(function () use ($aar, $by, $realisedLossMinor) {
            // Advisory A15: lock the PIR row before re-checking its status —
            // without this, two concurrent finalisations could both pass
            // "not already final" and both mirror a loss event.
            /** @var Aar $aar */
            $aar = Aar::query()->whereKey($aar->getKey())->lockForUpdate()->firstOrFail();

            if ($aar->status === 'final') {
                throw new InvalidArgumentException('This review is already final.');
            }

            // Gap 3: freshen condition 7's timings right before the gate
            // checks them and they freeze — a plan activated moments ago, or
            // a `declared_at` corrected since the review was opened, must be
            // what gets locked in, not a stale in-memory snapshot from the
            // GET that rendered the finalise button.
            $this->refreshMetrics($aar, persist: true);
            $aar->refresh();

            $approval = $this->pirApproverAllowed($aar, $by);

            if (! $approval['allowed']) {
                throw new InvalidArgumentException((string) $approval['reason']);
            }

            $unmet = array_values(array_filter($this->conditions($aar), fn (array $c) => ! $c['met']));

            if ($unmet !== []) {
                throw new InvalidArgumentException(
                    'This review cannot be finalised: '.implode(' ', array_column($unmet, 'message'))
                );
            }

            $qr = (array) $aar->quantitative_results;
            $qr['realised_loss_minor'] = $realisedLossMinor;
            $qr['realised_loss_confirmed_by'] = $by->getKey();
            $qr['realised_loss_confirmed_at'] = now()->toIso8601String();

            $aar->update([
                'status' => 'final',
                'iso_clause_ref' => IsoClauseRef::Iso22320_incident->value,
                'approved_by' => $by->getKey(),
                'approved_at' => now(),
                'updated_by' => $by->getKey(),
                'quantitative_results' => $qr,
            ]);

            // Gate 1 re-gate defect 7: mirror the PIR-CONFIRMED realised
            // loss — never the declaration-time estimate — into the ERM
            // loss-event register THROUGH `LossEventService::report()`/
            // `amend()` (see `ErmBridge::mirrorRealisedLoss()`'s own
            // docblock for why a direct `LossEvent` write, as this class
            // used before, was wrong for this).
            $incident = $aar->incident;

            if ($incident !== null) {
                $this->ermBridge->mirrorRealisedLoss($incident, $realisedLossMinor, $by);
            }

            return $aar->refresh();
        });
    }
}
