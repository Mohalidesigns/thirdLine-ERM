<?php

namespace App\Services\Bcms\Exercises;

use App\Enums\Bcms\OccurrenceStatus;
use App\Models\Bcms\ExerciseOccurrence;
use App\Models\User;
use App\Services\Bcms\Reminders\ReadinessService;
use InvalidArgumentException;

/**
 * Starting and ending an exercise occurrence, and the workspace's live poll.
 *
 * START CALLS THE EXISTING GATE. `ReadinessService::gate()` shipped in Phase
 * 5; this phase does not write a second one (clause map §2.1, "plus, at
 * start rather than at finalisation"). An override is Phase 5's own
 * mechanism, already audited there.
 *
 * COMPLETE CREATES THE DRAFT AAR. The prompt's own API surface says so
 * (`POST .../complete` in the same breath as "spawns corrective actions" —
 * that half is finalise's), and the aar-builder screen spec is built on the
 * assumption that a draft already exists by the time anyone is routed there.
 */
class OccurrenceExecutionService
{
    public function __construct(
        private ReadinessService $readiness,
        private AarService $aar,
        private CarriedActionService $carried,
    ) {}

    /**
     * Whether `start()` would need `confirmedEarlyStart` right now — today is
     * still before the occurrence's `scheduled_date`. Shared with the
     * readiness screen so the Start button can ask before the server refuses.
     */
    public function startsEarly(ExerciseOccurrence $occurrence): bool
    {
        return $occurrence->scheduled_date !== null
            && now()->startOfDay()->lt($occurrence->scheduled_date->copy()->startOfDay());
    }

    /**
     * @return ExerciseOccurrence the started occurrence, refreshed
     */
    public function start(
        ExerciseOccurrence $occurrence,
        User $by,
        bool $confirmedOverride = false,
        bool $confirmedEarlyStart = false,
    ): ExerciseOccurrence {
        if ($occurrence->actual_start !== null) {
            throw new InvalidArgumentException('This exercise has already started.');
        }

        // THE DATE GUARD. Nothing stopped a 2027 exercise starting today
        // before this — `start()` only ever checked `actual_start`. Starting
        // ahead of `scheduled_date` is not refused outright (a facilitator
        // legitimately runs a drill early sometimes, and the demo itself
        // starts a 2027-dated simulation today), but it is never silent: it
        // needs the same explicit, named confirmation the readiness override
        // already uses, and it is audited either way.
        $startedEarly = $this->startsEarly($occurrence);

        if ($startedEarly && ! $confirmedEarlyStart) {
            throw new InvalidArgumentException(sprintf(
                'This exercise is scheduled for %s, which has not arrived. Confirm to start it early.',
                $occurrence->scheduled_date->toFormattedDateString(),
            ));
        }

        $gate = $this->readiness->gate($occurrence);

        if (! $gate['allowed'] && ! $confirmedOverride) {
            throw new InvalidArgumentException((string) $gate['reason']);
        }

        // Carry forward before the facilitator sees the readiness ladder —
        // "opened" is the trigger the compliance analyst names alongside
        // "generated" (§3.3), and this is the earliest point Phase 9 owns.
        $this->carried->carryForward($occurrence);

        $occurrence->update([
            'actual_start' => now(),
            'status' => OccurrenceStatus::InProgress->value,
        ]);

        $occurrence->recordAudit('exercise_started', [
            'by' => $by->name,
            'gate_overridden' => ! $gate['allowed'],
            'started_early' => $startedEarly,
        ]);

        return $occurrence->refresh();
    }

    public function complete(
        ExerciseOccurrence $occurrence,
        User $by,
        ?string $outcome = null,
        bool $aborted = false,
        ?string $reason = null,
    ): ExerciseOccurrence {
        if ($occurrence->actual_start === null) {
            throw new InvalidArgumentException('This exercise has not started.');
        }

        if ($occurrence->actual_end !== null) {
            throw new InvalidArgumentException('This exercise has already ended.');
        }

        if ($aborted && trim((string) $reason) === '') {
            throw new InvalidArgumentException('Aborting an exercise needs a reason.');
        }

        $occurrence->update([
            'actual_end' => now(),
            'status' => OccurrenceStatus::Completed->value,
            'outcome' => $outcome,
        ]);

        $occurrence->timeline()->create([
            'organization_id' => $occurrence->organization_id,
            'logged_at' => now(),
            'logged_by' => $by->getKey(),
            'entry_type' => $aborted ? 'system' : 'milestone',
            'content' => $aborted ? 'Exercise aborted: '.$reason : 'Exercise ended.',
        ]);

        $occurrence->recordAudit($aborted ? 'exercise_aborted' : 'exercise_completed', [
            'by' => $by->name,
            'reason' => $reason,
        ]);

        $this->aar->ensureDraftFor($occurrence->refresh());

        return $occurrence->refresh();
    }

    /**
     * The small JSON document the workspace polls every five seconds
     * (execution-workspace spec §6) — never a full page render.
     *
     * @return array<string, mixed>
     */
    public function liveMetrics(ExerciseOccurrence $occurrence): array
    {
        // Gate 2 defect 7 (N+1): this is the five-second poll (spec §6), so
        // any per-row query here runs twelve times a minute for as long as
        // the workspace is open. Attendance is now two `COUNT` aggregates
        // instead of loading every participant row to count them in PHP, and
        // the timeline eager-loads `loggedBy` instead of resolving it once
        // per row (up to 50 extra queries a poll, on top of everything else
        // on this page).
        $expected = $occurrence->participants()->count();
        $checkedIn = $occurrence->participants()->whereNotNull('checked_in_at')->count();
        $timeline = $occurrence->timeline()->with('loggedBy:id,name')->orderByDesc('logged_at')->limit(50)->get();

        return [
            'status' => $occurrence->status->value,
            'actual_start' => $occurrence->actual_start?->toIso8601String(),
            'actual_end' => $occurrence->actual_end?->toIso8601String(),
            'attendance' => [
                'expected' => $expected,
                'checked_in' => $checkedIn,
            ],
            'injects' => [
                'planned' => $occurrence->injects()->count(),
                'released' => $occurrence->injects()->whereNotNull('released_at')->count(),
            ],
            'timeline' => $timeline->map(fn ($t) => [
                'id' => $t->getKey(),
                'logged_at' => $t->logged_at->toIso8601String(),
                'entry_type' => $t->entry_type,
                'content' => $t->content,
                'logged_by' => $t->loggedBy?->name,
            ])->values()->all(),
            'decisions_logged' => $occurrence->timeline()->where('entry_type', 'decision')->count(),
        ];
    }
}
