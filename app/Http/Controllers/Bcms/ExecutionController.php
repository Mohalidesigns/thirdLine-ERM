<?php

namespace App\Http\Controllers\Bcms;

use App\Enums\Bcms\ChannelKey;
use App\Enums\Bcms\InjectDeliveryChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bcms\CompleteOccurrenceRequest;
use App\Http\Requests\Bcms\ManualCheckInRequest;
use App\Http\Requests\Bcms\ReorderExerciseInjectsRequest;
use App\Http\Requests\Bcms\StartOccurrenceRequest;
use App\Http\Requests\Bcms\StoreExerciseInjectRequest;
use App\Http\Requests\Bcms\StoreTimelineEntryRequest;
use App\Http\Requests\Bcms\UpdateExerciseInjectRequest;
use App\Models\Bcms\Evidence;
use App\Models\Bcms\ExerciseInject;
use App\Models\Bcms\ExerciseOccurrence;
use App\Models\Bcms\ExerciseParticipant;
use App\Services\Bcms\Exercises\AarExportService;
use App\Services\Bcms\Exercises\CarriedActionService;
use App\Services\Bcms\Exercises\CheckInService;
use App\Services\Bcms\Exercises\InjectService;
use App\Services\Bcms\Exercises\LadderAdvisor;
use App\Services\Bcms\Exercises\OccurrenceExecutionService;
use App\Services\Bcms\Exercises\ScoringService;
use App\Services\Bcms\Exercises\TimelineService;
use App\Services\Bcms\Notification\ChannelRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * The exercise execution workspace — starting, running and ending an
 * occurrence (execution-workspace spec, PHASE-09-execution-aar.md).
 *
 * "START" IS THE READINESS GATE, AGAIN — NOT A SECOND ONE. `start()` calls
 * `ReadinessService::gate()`, already built in Phase 5 (clause map §2.1's
 * closing line: "do not write a second gate").
 *
 * THE LIVE POLL IS ONE SMALL JSON DOCUMENT, NEVER A FULL PAGE (spec §6). The
 * workspace itself is a normal Inertia render; `liveMetrics()` is what the
 * five-second poll actually hits.
 */
class ExecutionController extends Controller
{
    public function __construct(
        private OccurrenceExecutionService $execution,
        private TimelineService $timeline,
        private InjectService $injects,
        private CheckInService $checkIn,
        private CarriedActionService $carried,
        private ScoringService $scoring,
        private ChannelRegistry $channels,
        private LadderAdvisor $ladder,
        private AarExportService $aarExport,
    ) {}

    public function show(Request $request, ExerciseOccurrence $occurrence): Response
    {
        Gate::authorize('bcms.exercise.view');

        // "Not yet started" 404s (spec §3): starting is the readiness screen's
        // job, and a workspace with nothing in it invites logging before the
        // gate has run.
        abort_if($occurrence->actual_start === null, 404);

        $occurrence->loadMissing(['definition.exerciseType', 'site:id,name', 'facilitator:id,name']);
        $definition = $occurrence->definition;
        $type = $definition?->exerciseType;

        $tasks = $occurrence->readinessTasks()->get();
        $overrides = $tasks->filter(fn ($t) => $t->override_reason !== null)->values();

        $ladderWarnings = $definition === null ? [] : $this->ladder->adviseDefinition($definition);

        $participants = $occurrence->participants()->with(['user:id,name'])->get();
        $checkedIn = $participants->whereNotNull('checked_in_at');

        // Gate 2 review #2, blocking finding A: acceptance criterion 3 (a
        // participant can check in by QR or short code) is unmeetable if no
        // screen ever shows a participant's own code to anyone. The
        // credential is FACILITATOR-ONLY — never shipped to a viewer who can
        // only `bcms.exercise.view` — because it must never reach a
        // non-facilitator's screen; the facilitator relays it to the person
        // in front of them, or by their own channel. See the `not_checked_in`
        // mapping below, and `qr-checkin.md` §9's addendum next to it.
        $canFacilitate = $request->user()?->can('bcms.exercise.facilitate') === true;

        // Gate 2 defect 7 (N+1): each row below reads `loggedBy`/`releasedBy`
        // for its `->name` — 200 timeline rows and every inject, on a screen
        // that also polls `liveMetrics()` every five seconds, was 200-plus
        // one-row `users` queries per render. Eager-loaded, id+name only.
        $timeline = $occurrence->timeline()->with('loggedBy:id,name')->orderByDesc('logged_at')->limit(200)->get();
        $injects = $occurrence->injects()->with('releasedBy:id,name')->orderBy('sequence')->get();
        $evidence = $occurrence->evidence()->with('uploader:id,name')->orderByDesc('id')->get();

        return Inertia::render('Bcms/Exercises/Workspace', [
            'occurrence' => [
                'id' => $occurrence->getKey(),
                'uuid' => $occurrence->uuid,
                'definition_name' => $definition?->name,
                'exercise_type_label' => $type?->name,
                'ladder_level_label' => $type?->ladder_level?->label(),
                'type_code' => $type?->code,
                'site' => $occurrence->site?->name,
                'location' => $occurrence->location,
                'status' => $occurrence->status->value,
                'actual_start' => $occurrence->actual_start?->toIso8601String(),
                'actual_end' => $occurrence->actual_end?->toIso8601String(),
                'outcome' => $occurrence->outcome?->value,
                'facilitator' => $occurrence->facilitator?->name,
            ],
            'is_simulation' => true,
            'ladder_warnings' => array_values($ladderWarnings),
            'readiness_overrides' => $overrides->map(fn ($t) => [
                'task' => $t->title,
                'reason' => $t->override_reason,
                'overridden_at' => $t->overridden_at?->toIso8601String(),
            ])->values()->all(),
            'channels_are_mocked' => $this->anyMockChannel(),
            'metrics' => [
                'headcount_expected' => $participants->count(),
                'headcount_checked_in' => $checkedIn->count(),
                'time_to_assembly_target_seconds' => null,
                'decisions_logged' => $timeline->where('entry_type', 'decision')->count(),
            ],
            'timeline' => $timeline->map(fn ($t) => [
                'id' => $t->getKey(),
                'logged_at' => $t->logged_at->toIso8601String(),
                'entry_type' => $t->entry_type,
                'content' => $t->content,
                'logged_by' => $t->loggedBy?->name,
            ])->values()->all(),
            'injects' => $injects->map(function (ExerciseInject $i) use ($occurrence, $canFacilitate) {
                $released = $i->released_at !== null;

                $row = [
                    'id' => $i->getKey(),
                    'title' => $i->title,
                    'content' => $i->content,
                    'sequence' => $i->sequence,
                    'release_offset_minutes' => $i->release_offset_minutes,
                    'delivery_channel' => $i->delivery_channel,
                    'released_at' => $i->released_at?->toIso8601String(),
                    'released_by' => $i->releasedBy?->name,
                    'ai_generated' => (bool) $i->ai_generated,
                    'release_url' => $released ? null : route('bcms.occurrences.injects.release', [$occurrence, $i]),
                ];

                // GAP 2 (A3). Edit/delete keys are genuinely ABSENT once
                // released, or for a non-facilitator viewer — matching the
                // "absent, not null" contract the comment always claimed,
                // the same `attendance.not_checked_in` already uses below.
                if ($canFacilitate && ! $released) {
                    $row['update_url'] = route('bcms.occurrences.injects.update', [$occurrence, $i]);
                    $row['delete_url'] = route('bcms.occurrences.injects.destroy', [$occurrence, $i]);
                }

                return $row;
            })->values()->all(),
            'attendance' => [
                'expected' => $participants->count(),
                'checked_in' => $checkedIn->count(),
                'not_checked_in' => $participants->whereNull('checked_in_at')->map(function (ExerciseParticipant $p) use ($canFacilitate) {
                    $row = [
                        'id' => $p->getKey(),
                        'name' => $p->user?->name,
                    ];

                    // Keys ABSENT (not null) for a non-facilitator viewer —
                    // the contract `Workspace.jsx` is built against.
                    if ($canFacilitate) {
                        $row['short_code'] = $this->checkIn->shortCodeFor($p);
                        $row['check_in_url'] = route('bcms.check-in.show', $this->checkIn->tokenFor($p));
                    }

                    return $row;
                })->values()->all(),
            ],
            'evidence' => $evidence->map(fn (Evidence $e) => [
                'uuid' => $e->uuid,
                'file_name' => $e->file_name,
                'kind' => $e->kind,
                'caption' => $e->caption,
                'uploader' => $e->uploader?->name,
                'captured_at' => $e->captured_at?->toIso8601String(),
                'locked' => $e->isLocked(),
                'download_url' => route('bcms.evidence.download', [$occurrence, $e]),
                'delete_url' => $e->isLocked() ? null : route('bcms.evidence.destroy', [$occurrence, $e]),
            ])->values()->all(),
            'objectives' => $this->scoring->objectivesFor($occurrence),
            // GAP 2 — the closed vocabulary for the inject form's delivery
            // channel select (ADR 0023 Amendment 1). Validated in
            // `StoreExerciseInjectRequest`/`UpdateExerciseInjectRequest`, not
            // cast on the model — see `InjectDeliveryChannel`'s own docblock.
            'options' => [
                'inject_delivery_channels' => array_map(
                    fn (InjectDeliveryChannel $c) => ['value' => $c->value, 'label' => $c->label()],
                    InjectDeliveryChannel::cases(),
                ),
            ],
            'can' => [
                'facilitate' => $canFacilitate,
                'evaluate' => $request->user()?->can('bcms.exercise.evaluate') === true,
                'export' => $request->user()?->can('bcms.report.export') === true,
            ],
            'urls' => [
                'readiness' => route('bcms.occurrences.readiness', $occurrence),
                'complete' => route('bcms.occurrences.complete', $occurrence),
                'timeline_store' => route('bcms.occurrences.timeline.store', $occurrence),
                'check_in' => route('bcms.occurrences.check-in', $occurrence),
                'check_in_poster' => route('bcms.occurrences.check-in-poster', $occurrence),
                'live_metrics' => route('bcms.occurrences.live-metrics', $occurrence),
                'score' => route('bcms.occurrences.score.show', $occurrence),
                'evidence_upload' => route('bcms.evidence.store', $occurrence),
                'aar' => $occurrence->aar !== null ? route('bcms.aars.show', $occurrence->aar) : null,
                // GAP 2.
                'injects_store' => route('bcms.occurrences.injects.store', $occurrence),
                'injects_reorder' => route('bcms.occurrences.injects.reorder', $occurrence),
            ],
        ]);
    }

    public function start(StartOccurrenceRequest $request, ExerciseOccurrence $occurrence): RedirectResponse
    {
        try {
            $this->execution->start(
                $occurrence,
                $request->user(),
                (bool) $request->boolean('confirmed_override'),
                (bool) $request->boolean('confirmed_early_start'),
            );
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bcms.occurrences.workspace', $occurrence)
            ->with('success', 'Exercise started.');
    }

    public function complete(CompleteOccurrenceRequest $request, ExerciseOccurrence $occurrence): RedirectResponse
    {
        $data = $request->validated();

        try {
            $this->execution->complete(
                $occurrence,
                $request->user(),
                $data['outcome'] ?? null,
                (bool) ($data['aborted'] ?? false),
                $data['reason'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Exercise ended. Continue to the after-action report.');
    }

    public function storeTimelineEntry(StoreTimelineEntryRequest $request, ExerciseOccurrence $occurrence): RedirectResponse
    {
        try {
            $this->timeline->log($occurrence, $request->string('entry_type')->toString(), $request->string('content')->toString(), $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Logged.');
    }

    public function releaseInject(Request $request, ExerciseOccurrence $occurrence, ExerciseInject $inject): RedirectResponse
    {
        Gate::authorize('bcms.exercise.facilitate');

        try {
            $this->injects->release($occurrence, $inject, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Released.');
    }

    /** GAP 2 — authoring an inject. Appended; use {@see reorderInjects()} to place it. */
    public function storeInject(StoreExerciseInjectRequest $request, ExerciseOccurrence $occurrence): RedirectResponse
    {
        try {
            $this->injects->create($occurrence, $request->validated());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Inject added.');
    }

    /** GAP 2 — editing an unreleased inject. */
    public function updateInject(UpdateExerciseInjectRequest $request, ExerciseOccurrence $occurrence, ExerciseInject $inject): RedirectResponse
    {
        if ((int) $inject->occurrence_id !== (int) $occurrence->getKey()) {
            return back()->with('error', 'That inject does not belong to this occurrence.');
        }

        try {
            $this->injects->update($inject, $request->validated());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Inject updated.');
    }

    /** GAP 2 — deleting an unreleased inject. */
    public function destroyInject(Request $request, ExerciseOccurrence $occurrence, ExerciseInject $inject): RedirectResponse
    {
        Gate::authorize('bcms.exercise.facilitate');

        if ((int) $inject->occurrence_id !== (int) $occurrence->getKey()) {
            return back()->with('error', 'That inject does not belong to this occurrence.');
        }

        try {
            $this->injects->delete($inject);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Inject removed.');
    }

    /** GAP 2 — reordering an occurrence's injects. */
    public function reorderInjects(ReorderExerciseInjectsRequest $request, ExerciseOccurrence $occurrence): RedirectResponse
    {
        try {
            $this->injects->reorder($occurrence, array_map('intval', $request->validated('inject_ids')), $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Order updated.');
    }

    /** The facilitator checking a participant in by hand (execution-workspace spec §4). */
    public function checkIn(ManualCheckInRequest $request, ExerciseOccurrence $occurrence): RedirectResponse
    {
        $participant = ExerciseParticipant::query()
            ->where('occurrence_id', $occurrence->getKey())
            ->find($request->integer('participant_id'));

        if ($participant === null) {
            return back()->with('error', 'That participant is not on this occurrence.');
        }

        try {
            $this->checkIn->checkIn($participant, 'manual', $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Checked in.');
    }

    /** The five-second poll (spec §6) — one small JSON document, not a page. */
    public function liveMetrics(Request $request, ExerciseOccurrence $occurrence): JsonResponse
    {
        Gate::authorize('bcms.exercise.view');

        return response()->json($this->execution->liveMetrics($occurrence));
    }

    /** "Items to validate" from the previous occurrence of this definition (clause map §3.3). */
    public function carriedActions(Request $request, ExerciseOccurrence $occurrence): JsonResponse
    {
        Gate::authorize('bcms.exercise.view');

        return response()->json(['carried_actions' => $this->carried->present($occurrence)]);
    }

    /**
     * The examiner export (ADR 0019 §5, clause map refinement 12) — built
     * from stored values, computing nothing new. Nested under the occurrence,
     * per the ADR's own routing decision, not under the AAR.
     */
    public function aarExport(Request $request, ExerciseOccurrence $occurrence): JsonResponse
    {
        Gate::authorize('bcms.report.export');

        $payload = $this->aarExport->build($occurrence);

        return response()->json($payload)->withHeaders([
            'Content-Disposition' => 'attachment; filename="'.$this->aarExport->filename($occurrence).'"',
        ]);
    }

    /**
     * The facilitator's check-in poster/kiosk view (qr-checkin spec, screen A).
     *
     * ADDENDUM 2026-09-17 (qr-checkin.md §9, Gate 2 blocking defect 5): this
     * payload NEVER ships a per-participant token or short code. Those used
     * to be printed on one shared sheet displayed at the assembly point,
     * where any bystander could read them — forging headcount and
     * time-to-assembly (evidence criterion 1) and printing a staff roster
     * outdoors besides. The poster carries only the occurrence identity, the
     * live count, and ONE QR/URL for the short-code *form* — never a
     * participant's own code. Each participant's token/short code is
     * distributed to them individually by the SMS/email path the spec
     * already assumes; the facilitator's per-participant attendance list
     * stays on the authenticated workspace screen (`Workspace.jsx`'s
     * attendance card), never on this poster.
     *
     * ADDENDUM 2026-09-23 (Gate 2 review #2, blocking finding A): the
     * paragraph above was aspirational at review time — `show()`'s
     * `attendance.not_checked_in[]` carried only id/name, so no screen
     * anywhere actually surfaced a participant's code to the facilitator.
     * `show()` now includes `short_code`/`check_in_url` on each not-yet-
     * checked-in row, gated on `can.facilitate`, so the claim above is true
     * of the code as it stands, not just of the intent.
     */
    public function checkInPoster(Request $request, ExerciseOccurrence $occurrence): Response
    {
        Gate::authorize('bcms.exercise.facilitate');

        $expected = $occurrence->participants()->count();
        $checkedIn = $occurrence->participants()->whereNotNull('checked_in_at')->count();

        return Inertia::render('Bcms/Exercises/CheckInPoster', [
            'occurrence' => [
                'uuid' => $occurrence->uuid,
                'title' => $occurrence->definition?->name,
                'status' => $occurrence->status->value,
                'ended' => $occurrence->actual_end !== null,
            ],
            'expected' => $expected,
            'checked_in' => $checkedIn,
            'code_form_url' => route('bcms.check-in.code'),
            'live_metrics_url' => route('bcms.occurrences.live-metrics', $occurrence),
        ]);
    }

    private function anyMockChannel(): bool
    {
        foreach (ChannelKey::cases() as $channel) {
            if ($this->channels->isMock($channel)) {
                return true;
            }
        }

        return false;
    }
}
