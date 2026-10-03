<?php

namespace App\Services\Bcms\Exercises;

use App\Enums\Bcms\ExerciseOutcome;
use App\Enums\Bcms\IsoClauseRef;
use App\Enums\Bcms\LadderLevel;
use App\Models\Bcms\Aar;
use App\Models\Bcms\CallTreeTest;
use App\Models\Bcms\ExerciseOccurrence;
use App\Models\Bcms\ExerciseScore;
use App\Models\Bcms\Finding;
use App\Models\Bcms\ReadinessTask;
use App\Models\Bcms\TimelineEntry;
use App\Models\User;
use App\Services\Bcms\Reminders\ReminderScheduleBuilder;
use App\Support\Bcms\IncidentClock;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The after-action report — draft through finalisation, the eleven-plus-one
 * condition gate, reopening, and distribution (`phase-9-aar-clause-map.md`
 * §1, §2, ADR 0019 §2).
 *
 * THE GATE IS LIVE, NOT A SUBMIT-TIME SURPRISE. `conditions()` is called on
 * every page load by the controller (aar-builder screen §2/§7) and again,
 * literally, at `finalise()` — the same method computes both, so the screen
 * can never show "ready" when the server would refuse.
 *
 * COMPUTED SECTIONS ARE REFRESHED, NOT TRUSTED FROM LAST TIME.
 * `refreshComputedSections()` re-derives everything the system itself can
 * observe (scope, objective outcomes, attendance, injects, timeline counts,
 * readiness overrides, ladder warnings, `sources[]`) every time the AAR is
 * read or finalised, and leaves untouched what only a human can supply
 * (`what_worked`, `what_failed`, `participant_feedback`, disposition notes,
 * `not_measured[]` reasons). A late-arriving score must not make a stale
 * snapshot lie.
 */
class AarService
{
    /** The AAR-chaser rungs voided on finalise and resumed/re-materialised on reopen (clause map refinement 8). */
    private const AAR_CHASER_TEMPLATES = ['exercise.aar_due', 'exercise.aar_overdue', 'exercise.aar_escalation'];

    public function __construct(
        private ScoringService $scoring,
        private EvidenceService $evidence,
        private CarriedActionService $carriedActions,
        private LadderAdvisor $ladder,
        private ReminderScheduleBuilder $reminders,
    ) {}

    /** The per-exercise-type-family required `metrics` keys (clause map §2.2). */
    private const REQUIRED_METRICS = [
        'FIREDRILL' => ['headcount_expected', 'headcount_actual', 'time_to_assembly_seconds', 'target_seconds', 'unaccounted_resolved'],
        'EVAC' => ['headcount_expected', 'headcount_actual', 'time_to_assembly_seconds', 'target_seconds', 'unaccounted_resolved', 'roll_call_responses'],
        'CALLTREE' => ['nodes_total', 'nodes_reached', 'completion_rate', 'first_attempt_rate', 'deputy_activation_rate', 'total_cascade_minutes'],
        'DRFAILOVER' => ['rto_target_minutes', 'rto_actual_minutes', 'rpo_target_minutes', 'rpo_actual_minutes', 'met_objectives', 'downtime_minutes', 'threshold_breached'],
        'DRFAILBACK' => ['rto_target_minutes', 'rto_actual_minutes', 'rpo_target_minutes', 'rpo_actual_minutes', 'met_objectives', 'failback_performed'],
        'DRTEST' => ['rto_target_minutes', 'rto_actual_minutes', 'rpo_target_minutes', 'rpo_actual_minutes', 'met_objectives', 'data_integrity_verified'],
        'BACKUP' => ['rpo_target_minutes', 'rpo_actual_minutes', 'met_objectives', 'data_integrity_verified'],
        'CRISISSIM' => ['time_to_convene_minutes', 'decisions_logged', 'holding_statement_minutes'],
        'CYBER' => ['time_to_convene_minutes', 'decisions_logged', 'escalation_decision_at', 'regulatory_window_minutes', 'regulatory_window_met'],
        'FUNCTIONAL' => ['time_to_convene_minutes', 'decisions_logged'],
        'TABLETOP' => ['decisions_logged', 'activation_criteria_applied'],
        'PANDEMIC' => ['decisions_logged', 'activation_criteria_applied'],
        'WALKTHRU' => ['plan_steps_tested', 'plan_steps_failed'],
        'ORIENT' => ['plan_steps_tested', 'plan_steps_failed'],
        'SUPPLIER' => ['plan_steps_tested', 'plan_steps_failed', 'vendor_id', 'workaround_executable'],
        'FULLSCALE' => ['time_to_convene_minutes', 'decisions_logged', 'mbco_delivered'],
    ];

    /** Type codes carrying a regulated cadence — an extra refusal (clause map §2.2 closing note). */
    private const CADENCE_TYPES = ['DRFAILOVER', 'DRTEST'];

    /* ------------------------------------------------------------------ */
    /*  Lifecycle */
    /* ------------------------------------------------------------------ */

    /**
     * The draft AAR for an occurrence, creating it (and its computed
     * skeleton) the first time `complete()` reaches it. Idempotent.
     */
    public function ensureDraftFor(ExerciseOccurrence $occurrence): Aar
    {
        $aar = $occurrence->aar;

        if ($aar !== null) {
            return $aar;
        }

        $aar = Aar::query()->create([
            'organization_id' => $occurrence->organization_id,
            'occurrence_id' => $occurrence->getKey(),
            'status' => 'draft',
            'iso_clause_ref' => IsoClauseRef::Iso22301_8_5_report->value,
            'quantitative_results' => [],
            'participant_feedback' => [],
        ]);

        $this->refreshComputedSections($aar->fresh());

        return $aar->refresh();
    }

    /**
     * Save draft fields. Refused on a final AAR — the frozen fields
     * (criterion 8) simply do not update, whatever the caller sends.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Aar $aar, array $attributes, ?User $by = null): Aar
    {
        if ($aar->status === 'final') {
            throw new InvalidArgumentException(
                'This after-action report is final. Its fields are frozen — reopen it to make a correction.'
            );
        }

        $allowed = array_intersect_key($attributes, array_flip([
            'summary', 'what_worked', 'what_failed', 'quantitative_results', 'participant_feedback',
        ]));

        if (isset($allowed['quantitative_results']) && is_array($allowed['quantitative_results'])) {
            $allowed['quantitative_results'] = array_replace(
                (array) $aar->quantitative_results,
                $allowed['quantitative_results'],
            );

            // `dr_invocations.*.failback.at` (post-incident review only —
            // `IncidentPresenter::drInvocation()`) is the one datetime this
            // JSON blob carries with no Eloquent cast to normalise it.
            // Every other timestamp this presenter ships is cast on a
            // model column and travels through `toIso8601String()`; this
            // one is a client-supplied string inside an otherwise opaque
            // array, so it is normalised here — the single write path
            // `quantitative_results` has, for a PIR or an exercise AAR
            // alike — rather than trusted to arrive already in UTC.
            // Inert for an exercise AAR: `dr_invocations` is never set
            // outside a PIR.
            $allowed['quantitative_results'] = $this->normaliseDrFailbackTimes($allowed['quantitative_results']);
        }

        if (isset($allowed['participant_feedback']) && is_array($allowed['participant_feedback'])) {
            $allowed['participant_feedback'] = $this->normaliseFeedback($allowed['participant_feedback']);
        }

        $aar->update(array_merge($allowed, ['updated_by' => $by?->getKey()]));

        return $aar->refresh();
    }

    /**
     * @param  array<string, mixed>  $qr
     * @return array<string, mixed>
     */
    private function normaliseDrFailbackTimes(array $qr): array
    {
        if (! isset($qr['dr_invocations']) || ! is_array($qr['dr_invocations'])) {
            return $qr;
        }

        foreach ($qr['dr_invocations'] as $systemUuid => $result) {
            if (! is_array($result) || ! isset($result['failback']) || ! is_array($result['failback'])) {
                continue;
            }

            $at = $result['failback']['at'] ?? null;

            $qr['dr_invocations'][$systemUuid]['failback']['at'] = $at === null || $at === ''
                ? null
                : IncidentClock::utc($at)?->toIso8601String();
        }

        return $qr;
    }

    /**
     * Normalise a `participant_feedback` payload to the published
     * `bcms.aar.feedback.v1` contract (clause map §2.3): only `schema`,
     * `invited`, `responded`, `questions[]` and `comments[]` survive, and
     * each comment keeps only `text`, `role` and `business_unit` — never a
     * `user_id`, `name` or `email`, whatever the caller sent. This runs for
     * every caller of `update()`, not only the ones that went through
     * `UpdateAarRequest`'s own `prohibited` rules — an import or the AI
     * drafter must not be able to smuggle an identifier through a path the
     * Form Request never sees.
     *
     * @param  array<string, mixed>  $feedback
     * @return array<string, mixed>
     */
    private function normaliseFeedback(array $feedback): array
    {
        $result = ['schema' => is_string($feedback['schema'] ?? null) ? $feedback['schema'] : 'bcms.aar.feedback.v1'];

        if (array_key_exists('invited', $feedback)) {
            $result['invited'] = $feedback['invited'];
        }

        if (array_key_exists('responded', $feedback)) {
            $result['responded'] = $feedback['responded'];
        }

        if (is_array($feedback['questions'] ?? null)) {
            $result['questions'] = array_values(array_map(
                static fn (mixed $q): array => is_array($q) ? array_intersect_key(
                    $q,
                    array_flip(['key', 'prompt', 'scale', 'distribution', 'mean'])
                ) : [],
                $feedback['questions'],
            ));
        }

        if (is_array($feedback['comments'] ?? null)) {
            $result['comments'] = array_values(array_map(
                static fn (mixed $c): array => is_array($c) ? array_intersect_key(
                    $c,
                    array_flip(['text', 'role', 'business_unit'])
                ) : [],
                $feedback['comments'],
            ));
        }

        return $result;
    }

    /**
     * Re-derive every section the system can observe on its own. Called on
     * every read and before every gate evaluation.
     */
    public function refreshComputedSections(Aar $aar): void
    {
        // Gate 2 defect 1: a final report's computed sections are FROZEN, not
        // re-derived. Before this guard, every read (`conditions()`, called on
        // every `AarController::show()`) and every export
        // (`AarExportService::build()`) rewrote `quantitative_results` from
        // whatever the occurrence's attendance/timeline/readiness/scores look
        // like *right now* — so a manual check-in recorded after finalisation
        // silently rewrote a signed-off report's attendance figures, ladder
        // warnings and CALLTREE metrics. "Immutable on finalise" (ADR 0019 §2)
        // means this method returns immediately once the report is final;
        // `conditions()` and the export then read the stored, frozen snapshot,
        // exactly as every other frozen field on this model already does.
        if ($aar->status === 'final') {
            return;
        }

        $occurrence = $aar->occurrence;

        if ($occurrence === null) {
            return;
        }

        $definition = $occurrence->definition;
        $type = $definition?->exerciseType;
        $existing = (array) $aar->quantitative_results;

        $existingObjectives = collect($existing['objectives'] ?? [])->keyBy('objective_text');
        $objectives = [];

        // Advisory 16 (Gate 2): one query for every objective's scores,
        // grouped in PHP, rather than one `ExerciseScore` query PER objective
        // — this runs on every read (`refreshComputedSections()` is called
        // from `conditions()` on every `AarController::show()`), so an
        // exercise with a dozen objectives was a dozen extra round trips on
        // every page load.
        $scoresByObjective = ExerciseScore::query()
            ->where('occurrence_id', $occurrence->getKey())
            ->get()
            ->groupBy('objective_text');

        foreach ($this->scoring->objectivesFor($occurrence) as $objective) {
            $scores = $scoresByObjective->get($objective['text'], collect());

            $prior = $existingObjectives->get($objective['text'], []);

            $objectives[] = array_merge($prior, [
                'objective_text' => $objective['text'],
                'target' => $prior['target'] ?? null,
                'actual' => $prior['actual'] ?? null,
                'met' => $prior['met'] ?? null,
                'mean_score' => $scores->whereNotNull('score')->avg('score'),
                'scores_count' => $scores->count(),
                'finding_reference' => $prior['finding_reference'] ?? null,
                'disposition_note' => $prior['disposition_note'] ?? null,
            ]);
        }

        $attendance = $occurrence->participants()->get();
        $checkedIn = $attendance->whereNotNull('checked_in_at');

        $timeline = $occurrence->timeline()->get();
        $tasks = $occurrence->readinessTasks()->get();
        $overrides = $tasks->filter(fn (ReadinessTask $t) => $t->override_reason !== null)->map(fn (ReadinessTask $t) => [
            'task' => $t->title,
            'by' => $t->overridden_by,
            'at' => $t->overridden_at?->toIso8601String(),
            'reason' => $t->override_reason,
        ])->values()->all();

        $warnings = $definition === null ? [] : $this->ladder->adviseDefinition($definition);
        $openBelow = collect($warnings)->firstWhere('rule', 'open_actions_below');

        $carried = $this->carriedActions->present($occurrence);
        $existingCarried = collect($existing['carried_actions'] ?? [])->keyBy('reference');
        $carriedOut = array_map(fn (array $c) => array_merge(
            ['disposition' => null, 'note' => null],
            $existingCarried->get($c['reference'], []),
            ['reference' => $c['reference'], 'from_occurrence_uuid' => $c['from_occurrence_uuid']],
        ), $carried);

        $metrics = array_merge($existing['metrics'] ?? [], $this->autoMetrics($occurrence, $type?->code, $attendance, $checkedIn));
        $sources = $this->autoSources($occurrence, $existing['sources'] ?? []);

        $result = array_merge($existing, [
            'schema' => 'bcms.aar.quantitative.v1',
            'scope' => array_merge($existing['scope'] ?? [], [
                'exercise_type_code' => $type?->code,
                'ladder_level' => $type?->ladder_level?->value,
                // @phpstan-ignore-next-line nullsafe.neverNull (`definition_id` is a NOT NULL column, so Larastan treats `$occurrence->definition` as always-present — but this same method treats it as nullable three lines above (line 239) for a soft-deleted or orphaned definition, which is the real, if rare, runtime case this guards)
                'process_ids' => $definition?->process_ids ?? [],
                'site_codes' => array_filter([$occurrence->site?->code]),
                // @phpstan-ignore-next-line nullsafe.neverNull (same false non-nullability as `process_ids` above)
                'regulatory_drivers' => $definition?->regulatory_drivers ?? [],
            ]),
            'objectives' => $objectives,
            'metrics' => $metrics,
            'attendance' => [
                'expected' => $attendance->count(),
                'checked_in' => $checkedIn->count(),
                'absent' => $attendance->where('attendance_status', 'absent')->count(),
                'excused' => $attendance->where('attendance_status', 'excused')->count(),
                'unaccounted' => $attendance->where('attendance_status', 'unknown')->count(),
                'by_method' => $checkedIn->countBy('check_in_method'),
            ],
            'injects' => [
                'planned' => $occurrence->injects()->count(),
                'released' => $occurrence->injects()->whereNotNull('released_at')->count(),
            ],
            'timeline' => [
                'entries' => $timeline->count(),
                'decisions' => $timeline->where('entry_type', 'decision')->count(),
                'milestones' => $timeline->where('entry_type', 'milestone')->count(),
                'first_entry_at' => $timeline->min('logged_at')?->toIso8601String(),
                'last_entry_at' => $timeline->max('logged_at')?->toIso8601String(),
            ],
            'readiness' => [
                'tasks_total' => $tasks->count(),
                'blocking_open_at_start' => $tasks->where('is_blocking', true)->whereIn('status', ['open', 'in_progress', 'overdue'])->count(),
                'overrides' => $overrides,
            ],
            'carried_actions' => $carriedOut,
            'ladder' => [
                'advisor_warnings' => array_map(fn (array $w) => ['rule' => $w['rule'], 'message' => $w['message']], $warnings),
                'open_actions_below' => $openBelow['message'] ?? null,
            ],
            'not_measured' => $existing['not_measured'] ?? [],
            'sources' => $sources,
        ]);

        $aar->forceFill(['quantitative_results' => $result])->saveQuietly();
    }

    /**
     * The subset of `metrics` the system can compute on its own, per
     * exercise-type family. Only the values named here are ever overwritten
     * on refresh; everything else in `metrics` is human-supplied and left
     * exactly as last saved.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\Bcms\ExerciseParticipant>  $attendance
     * @param  \Illuminate\Support\Collection<int, \App\Models\Bcms\ExerciseParticipant>  $checkedIn
     * @return array<string, mixed>
     */
    private function autoMetrics(ExerciseOccurrence $occurrence, ?string $typeCode, $attendance, $checkedIn): array
    {
        if (in_array($typeCode, ['FIREDRILL', 'EVAC'], true)) {
            $lastIn = $checkedIn->max('checked_in_at');

            return [
                'headcount_expected' => $attendance->count(),
                'headcount_actual' => $checkedIn->count(),
                // target_seconds is not auto-computed (no target field exists
                // on the definition to read it from) and stays whatever a
                // human has recorded.
                'time_to_assembly_seconds' => $occurrence->actual_start !== null && $lastIn !== null
                    ? max(0, $occurrence->actual_start->diffInSeconds($lastIn, false))
                    : null,
            ];
        }

        if ($typeCode === 'CALLTREE') {
            $test = CallTreeTest::query()
                ->where('occurrence_id', $occurrence->getKey())
                ->latest('id')
                ->first();

            if ($test === null) {
                return [];
            }

            return [
                'call_tree_test_id' => $test->getKey(),
                'nodes_total' => $test->nodes_total,
                'nodes_reached' => $test->nodes_reached,
                'completion_rate' => $test->completion_rate,
                'first_attempt_rate' => $test->first_attempt_rate,
                'deputy_activation_rate' => $test->deputy_activation_rate,
                'total_cascade_minutes' => $test->total_cascade_minutes,
                'data_quality_failures' => $test->data_quality_failures,
            ];
        }

        return [];
    }

    /**
     * The generic evidence-source index (clause map §2.2): what already-first
     * -class record backs this AAR's numbers, so a later phase (EMNS alert
     * stats, DR provider output) only has to add its own `kind` here.
     *
     * @param  list<array<string, mixed>>  $existing
     * @return list<array<string, mixed>>
     */
    private function autoSources(ExerciseOccurrence $occurrence, array $existing): array
    {
        $sources = collect($existing)->reject(fn (array $s) => ($s['kind'] ?? null) === 'call_tree_test')->values();

        foreach (CallTreeTest::query()->where('occurrence_id', $occurrence->getKey())->get() as $test) {
            $sources->push([
                'kind' => 'call_tree_test',
                'id' => $test->getKey(),
                'captured_at' => $test->completed_at?->toIso8601String(),
            ]);
        }

        return $sources->values()->all();
    }

    /* ------------------------------------------------------------------ */
    /*  The gate */
    /* ------------------------------------------------------------------ */

    /**
     * The eleven-plus-one condition gate, live — computed the same way here
     * as at `finalise()`.
     *
     * @return list<array{key: string, label: string, met: bool, message: string}>
     */
    public function conditions(Aar $aar): array
    {
        $this->refreshComputedSections($aar);
        $aar->refresh();

        $occurrence = $aar->occurrence;
        $qr = (array) $aar->quantitative_results;
        $out = [];

        $out[] = $this->condition(
            '1_duration', 'The exercise has a recorded start and end',
            $occurrence !== null && $occurrence->actual_start !== null && $occurrence->actual_end !== null,
            'An exercise with no duration was not run.',
        );

        $outcomeSet = $occurrence?->outcome !== null;
        $inconclusiveOk = $occurrence?->outcome !== ExerciseOutcome::Inconclusive || trim((string) $aar->summary) !== '';
        $out[] = $this->condition(
            '2_outcome', 'An outcome is recorded',
            $outcomeSet && $inconclusiveOk,
            $outcomeSet ? 'An inconclusive outcome must state why in the summary.' : 'No outcome has been recorded for this exercise.',
        );

        $out[] = $this->condition(
            '3_narrative', 'Summary, what worked and what did not are all recorded',
            trim((string) $aar->summary) !== '' && trim((string) $aar->what_worked) !== '' && trim((string) $aar->what_failed) !== '',
            '"What did not work" empty on anything but a clean pass is not credible.',
        );

        $timelineEntries = $occurrence?->timeline()->count() ?? 0;
        $milestones = $occurrence?->timeline()->where('entry_type', 'milestone')->count() ?? 0;
        $out[] = $this->condition(
            '4_timeline', 'The timeline has at least one milestone entry',
            $timelineEntries > 0 && $milestones > 0,
            $timelineEntries === 0 ? 'The timeline is empty.' : ($milestones.' milestone entries so far — at least one is required.'),
        );

        $out[] = $this->condition(
            '5_scored', 'Every objective has at least one attributed score',
            $occurrence === null || $this->scoring->everyObjectiveScored($occurrence),
            'One or more objectives have no score yet.',
        );

        $out[] = $this->condition(
            '6_low_scores', 'Every score of 1–2 is linked to a finding or dispositioned',
            $this->lowScoresDispositioned($qr),
            'A score of 1 or 2 with no linked finding and no disposition note is an objective nobody acted on.',
        );

        $unaccounted = $occurrence?->participants()->where('attendance_status', 'unknown')->count() ?? 0;
        $out[] = $this->condition(
            '7_attendance', 'No participant is left unaccounted for',
            $unaccounted === 0,
            $unaccounted.' participant(s) still show attendance as unknown.',
        );

        // Advisory 10 (Gate 2): this used to compare a COUNT against a count
        // derived from the exact same underlying rows a moment earlier in
        // `refreshComputedSections()` — always equal, so the condition could
        // never fail. Compared by IDENTITY instead: every readiness task
        // currently carrying an `override_reason` must actually be NAMED in
        // the stored `overrides[]` list, not merely counted. For a draft this
        // is still ordinarily true (the refresh above just populated it); for
        // a FINAL report — frozen by the guard at the top of
        // `refreshComputedSections()` — this is now a genuine check that the
        // locked snapshot still names every override on record, rather than a
        // tautology that always passed.
        $currentOverrideTitles = $occurrence?->readinessTasks()->whereNotNull('override_reason')->pluck('title')->sort()->values()->all() ?? [];
        $recordedOverrideTitles = collect($qr['readiness']['overrides'] ?? [])->pluck('task')->sort()->values()->all();
        $overridesListed = $currentOverrideTitles === $recordedOverrideTitles;
        $out[] = $this->condition(
            '8_overrides', 'Every readiness override is listed',
            $overridesListed,
            'A readiness override happened but is not reflected in the record.',
        );

        $out[] = $this->condition(
            '9_carried', 'Every carried-forward action has a disposition',
            collect($qr['carried_actions'] ?? [])->every(fn (array $c) => filled($c['disposition'] ?? null)),
            'One or more items carried from the last occurrence have no disposition yet.',
        );

        $missedObjective = collect($qr['objectives'] ?? [])->contains(fn (array $o) => ($o['met'] ?? null) === false);
        $findingExists = Finding::query()->where('aar_id', $aar->getKey())->exists();
        $condition10 = $occurrence?->outcome !== ExerciseOutcome::Fail && ! $missedObjective || $findingExists;
        $out[] = $this->condition(
            '10_findings', 'A failed or missed objective has a finding',
            $condition10,
            'This exercise missed an objective or failed, and no finding has been raised for it.',
        );

        $out[] = $this->condition(
            '11_metrics', 'The required metrics for this exercise type are recorded, or explained as not measured',
            $this->requiredMetricsSatisfied($qr),
            'One or more required metrics for this exercise type are missing and not listed as not measured.',
        );

        $failures = $occurrence === null ? [] : $this->evidenceHashFailures($occurrence);
        $out[] = $this->condition(
            '12_evidence', 'Every evidence artefact matches its recorded hash',
            $failures === [],
            $failures === [] ? '' : 'These files no longer match what was recorded: '.implode(', ', $failures),
        );

        return $out;
    }

    /** @return array{key: string, label: string, met: bool, message: string} */
    private function condition(string $key, string $label, bool $met, string $message): array
    {
        return ['key' => $key, 'label' => $label, 'met' => $met, 'message' => $met ? '' : $message];
    }

    /** @param array<string, mixed> $qr */
    private function lowScoresDispositioned(array $qr): bool
    {
        foreach ((array) ($qr['objectives'] ?? []) as $objective) {
            $mean = $objective['mean_score'] ?? null;

            if ($mean === null || $mean > 2) {
                continue;
            }

            if (blank($objective['finding_reference'] ?? null) && blank($objective['disposition_note'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $qr */
    private function requiredMetricsSatisfied(array $qr): bool
    {
        $typeCode = $qr['scope']['exercise_type_code'] ?? null;
        $required = self::REQUIRED_METRICS[$typeCode] ?? [];

        if ($required === []) {
            return true;
        }

        $metrics = (array) ($qr['metrics'] ?? []);
        // Advisory 11 (Gate 2) / clause map §2.2 schema: a `not_measured[]`
        // entry with no `reason` is indistinguishable from a metric nobody
        // even tried to explain — it must carry one to count as satisfying
        // condition 11, so an entry with a blank reason is filtered out here
        // rather than accepted on the strength of its `metric` key alone.
        $notMeasured = collect($qr['not_measured'] ?? [])
            ->filter(fn (array $n) => filled($n['reason'] ?? null))
            ->pluck('metric')
            ->all();

        foreach ($required as $key) {
            if (array_key_exists($key, $metrics) && $metrics[$key] !== null) {
                continue;
            }

            if (in_array($key, $notMeasured, true)) {
                continue;
            }

            return false;
        }

        if (in_array($typeCode, self::CADENCE_TYPES, true)) {
            if (! array_key_exists('rto_actual_minutes', $metrics) && ! in_array('rto_actual_minutes', $notMeasured, true)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function evidenceHashFailures(ExerciseOccurrence $occurrence): array
    {
        $failures = [];

        foreach ($occurrence->evidence()->whereNull('locked_at')->get() as $row) {
            if (! $this->evidence->verifyHash($row)) {
                $failures[] = $row->file_name;
            }
        }

        return $failures;
    }

    /* ------------------------------------------------------------------ */
    /*  Approval separation of duties (clause map §1.3) */
    /* ------------------------------------------------------------------ */

    /** @return array{allowed: bool, reason: ?string, blocking: bool} */
    public function approverAllowed(Aar $aar, User $approver): array
    {
        $occurrence = $aar->occurrence;

        if ($occurrence === null) {
            return ['allowed' => true, 'reason' => null, 'blocking' => false];
        }

        $sameParty = (int) $occurrence->facilitator_id === (int) $approver->getKey();

        if (! $sameParty) {
            return ['allowed' => true, 'reason' => null, 'blocking' => false];
        }

        $level = $occurrence->definition?->exerciseType?->ladder_level;
        $hasOverride = $occurrence->readinessTasks()->whereNotNull('override_reason')->exists();
        $blocking = ($level !== null && $level->isAtOrAbove(LadderLevel::Functional)) || $hasOverride;

        return [
            'allowed' => ! $blocking,
            'reason' => 'You facilitated this exercise. At this level, a different approver must finalise it '
                .'(internal audit rule, not an ISO requirement).',
            'blocking' => $blocking,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Finalise / reopen / distribute */
    /* ------------------------------------------------------------------ */

    public function finalise(Aar $aar, User $by): Aar
    {
        if ($aar->status === 'final') {
            throw new InvalidArgumentException('This report is already final.');
        }

        $unmet = array_values(array_filter($this->conditions($aar), fn (array $c) => ! $c['met']));

        if ($unmet !== []) {
            throw new InvalidArgumentException(
                'This report cannot be finalised: '.implode(' ', array_column($unmet, 'message'))
            );
        }

        $approval = $this->approverAllowed($aar, $by);

        if (! $approval['allowed']) {
            throw new InvalidArgumentException((string) $approval['reason']);
        }

        $occurrence = $aar->occurrence;

        return DB::transaction(function () use ($aar, $by, $occurrence) {
            if ($occurrence !== null) {
                $failures = $this->evidence->verifyAndLock($occurrence, $by);

                if ($failures !== []) {
                    throw new InvalidArgumentException(
                        'These evidence files no longer match what was recorded and finalisation was refused: '
                        .implode(', ', $failures)
                    );
                }
            }

            $aar->update([
                'status' => 'final',
                'approved_by' => $by->getKey(),
                'approved_at' => now(),
                'updated_by' => $by->getKey(),
            ]);

            // Clause map refinement 8: "the T+3/T+7 AAR-overdue rungs must be
            // voided on status = final, not on AAR creation." A draft that
            // exists and says nothing is exactly the case the chasers are
            // for; once finalised there is nothing left to chase.
            if ($occurrence !== null) {
                $this->reminders->voidTemplates(
                    $occurrence,
                    self::AAR_CHASER_TEMPLATES,
                    'The after-action report was finalised; no further chasers are needed.',
                );
            }

            return $aar->refresh();
        });
    }

    public function reopen(Aar $aar, User $by, string $reason): Aar
    {
        if ($aar->status !== 'final') {
            throw new InvalidArgumentException('Only a final report can be reopened.');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('Reopening a final report has to say why.');
        }

        return DB::transaction(function () use ($aar, $by, $reason) {
            $occurrence = $aar->occurrence;

            if ($occurrence !== null) {
                TimelineEntry::query()->create([
                    'organization_id' => $occurrence->organization_id,
                    'occurrence_id' => $occurrence->getKey(),
                    'logged_at' => now(),
                    'logged_by' => $by->getKey(),
                    'entry_type' => 'system',
                    'content' => 'The after-action report was reopened: '.$reason,
                ]);
            }

            $aar->update([
                'status' => 'draft',
                'approved_by' => null,
                'approved_at' => null,
                'distributed_at' => null,
                'updated_by' => $by->getKey(),
            ]);

            $aar->recordAudit('aar_reopened', ['by' => $by->name, 'reason' => $reason]);

            // The counterpart of finalise()'s void: a reopened report is
            // draft again and may yet go final late, so its chasers resume
            // rather than staying silenced for ever. `build()` is the same
            // regenerate-the-ladder path a rescheduled occurrence uses — it
            // finds these rows by their unchanged idempotency key and flips
            // them `voided -> pending` with a freshly computed `send_at`
            // (clause map refinement 8: "reopened → they resume").
            if ($occurrence !== null) {
                $this->reminders->build($occurrence->fresh());
            }

            return $aar->refresh();
        });
    }

    public function distribute(Aar $aar, User $by): Aar
    {
        if ($aar->status !== 'final') {
            throw new InvalidArgumentException('Only a final report can be distributed.');
        }

        $aar->update(['distributed_at' => now(), 'updated_by' => $by->getKey()]);

        return $aar->refresh();
    }

    /** Set a carried action's disposition inside this AAR's quantitative_results. */
    public function disposeCarriedAction(Aar $aar, string $reference, string $disposition, ?string $note = null): Aar
    {
        if (! in_array($disposition, ['validated', 'still_open', 'superseded'], true)) {
            throw new InvalidArgumentException("'{$disposition}' is not a recognised disposition.");
        }

        $qr = (array) $aar->quantitative_results;
        $carried = collect($qr['carried_actions'] ?? []);

        $qr['carried_actions'] = $carried->map(function (array $c) use ($reference, $disposition, $note) {
            if ($c['reference'] === $reference) {
                $c['disposition'] = $disposition;
                $c['note'] = $note;
            }

            return $c;
        })->values()->all();

        $aar->forceFill(['quantitative_results' => $qr])->save();

        // A carried action validated at the current occurrence is closed the
        // way clause 10.1 requires — by verify(), not by this AAR — so this
        // only annotates; it never advances a CorrectiveAction's own status.
        return $aar->refresh();
    }

    /** Set an objective's low-score disposition note (the non-finding path of condition 6). */
    public function disposeObjective(Aar $aar, string $objectiveText, ?string $note): Aar
    {
        $qr = (array) $aar->quantitative_results;

        $qr['objectives'] = collect($qr['objectives'] ?? [])->map(function (array $o) use ($objectiveText, $note) {
            if ($o['objective_text'] === $objectiveText) {
                $o['disposition_note'] = $note;
            }

            return $o;
        })->values()->all();

        $aar->forceFill(['quantitative_results' => $qr])->save();

        return $aar->refresh();
    }

    /**
     * Link an objective's low score to a finding that has been raised for
     * it, at the point the caller raises the finding from the AAR builder.
     */
    public function linkObjectiveToFinding(Aar $aar, string $objectiveText, string $findingReference): Aar
    {
        $qr = (array) $aar->quantitative_results;

        $qr['objectives'] = collect($qr['objectives'] ?? [])->map(function (array $o) use ($objectiveText, $findingReference) {
            if ($o['objective_text'] === $objectiveText) {
                $o['finding_reference'] = $findingReference;
            }

            return $o;
        })->values()->all();

        $aar->forceFill(['quantitative_results' => $qr])->save();

        return $aar->refresh();
    }

    /**
     * The post-incident review's draft row (BCMS Phase 10, ADR 0020 §1) —
     * `incident_id` set, `occurrence_id` null, mirroring `ensureDraftFor()`'s
     * shape for the exercise side without touching that method. Idempotent,
     * same as its twin. Everything past creation (the plan-versus-actual
     * gate, the incident-metrics section, the tenth "what the exercises
     * predicted" section) is `App\Services\Bcms\Incidents\PirService`'s own
     * work, not this class's — kept separate because the PIR's finalisation
     * gate genuinely differs (clause map §4.2: no scoring, no injects, a
     * different due-date rule), not merely shortened.
     */
    public function ensureDraftForIncident(\App\Models\Bcms\Incident $incident): Aar
    {
        $aar = Aar::query()->where('incident_id', $incident->getKey())->first();

        if ($aar !== null) {
            return $aar;
        }

        return Aar::query()->create([
            'organization_id' => $incident->organization_id,
            'incident_id' => $incident->getKey(),
            'status' => 'draft',
            'iso_clause_ref' => IsoClauseRef::Iso22320_incident->value,
            'quantitative_results' => [],
            'participant_feedback' => [],
        ]);
    }
}
