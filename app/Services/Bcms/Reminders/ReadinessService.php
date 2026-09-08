<?php

namespace App\Services\Bcms\Reminders;

use App\Enums\Bcms\OccurrenceStatus;
use App\Models\Bcms\ExerciseOccurrence;
use App\Models\Bcms\ReadinessTask;
use App\Models\Bcms\ReadinessTemplate;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Readiness checklists — the gating feature Blueprint §3.2 shows no competitor
 * has, and which is worthless if it is advisory.
 *
 * A BLOCKING TASK STOPS THE EXERCISE. `readiness_gating = true` on the
 * definition means the occurrence cannot be started while a blocking task is
 * open. The facilitator closes it or records an OVERRIDE WITH A REASON, and the
 * override is an audit record that the after-action report carries. That is the
 * difference between a checklist and a form: the second one gets ticked on the
 * morning of the exercise.
 *
 * DUE DATES ARE DERIVED FROM THE EXERCISE DATE, NOT TYPED. `due_offset_days` is
 * signed — T-8, T-5, T-2, and the positive ones for the AAR — so moving the
 * exercise moves every task with it. A checklist whose dates had to be retyped
 * after a reschedule is one that is silently wrong from the first reschedule
 * onwards.
 *
 * THE COUNTS ARE STORED ON THE OCCURRENCE. `readiness_complete` and
 * `blocking_tasks_open` are maintained here rather than counted on every render:
 * the gate reads one boolean, the calendar draws hundreds of rows, and the
 * stored value is what the AAR reports as the state on the day.
 */
class ReadinessService
{
    /**
     * Materialise the checklist for an occurrence from its type's template.
     *
     * Idempotent by `template_task_id`: running it twice does not duplicate,
     * and a task somebody has already completed is not reset.
     */
    public function materialise(ExerciseOccurrence $occurrence): int
    {
        $definition = $occurrence->definition;
        $template = $this->templateFor($occurrence);

        if ($template === null) {
            return 0;
        }

        $created = 0;

        DB::transaction(function () use ($occurrence, $definition, $template, &$created) {
            foreach ($template->tasks()->orderBy('sort_order')->get() as $templateTask) {
                $existing = ReadinessTask::query()
                    ->where('occurrence_id', $occurrence->getKey())
                    ->where('template_task_id', $templateTask->getKey())
                    ->first();

                $dueDate = $this->dueDateFor($occurrence, (int) $templateTask->due_offset_days);

                if ($existing !== null) {
                    // A reschedule moves the dates and nothing else. Ownership,
                    // completion and evidence are the human's.
                    if ($existing->status === 'open' || $existing->status === 'in_progress') {
                        $existing->update(['due_date' => $dueDate]);
                    }

                    continue;
                }

                ReadinessTask::query()->create([
                    'organization_id' => $occurrence->organization_id,
                    'occurrence_id' => $occurrence->getKey(),
                    'template_task_id' => $templateTask->getKey(),
                    'title' => $templateTask->title,
                    'description' => $templateTask->description,
                    // The facilitator owns everything until somebody reassigns
                    // it. An unowned task is one nobody chases.
                    'owner_id' => $occurrence->facilitator_id
                        ?? ($definition === null ? null : ($definition->facilitator_id ?? $definition->owner_id)),
                    'due_offset_days' => $templateTask->due_offset_days,
                    'due_date' => $dueDate,
                    'is_blocking' => (bool) $templateTask->is_blocking,
                    'status' => 'open',
                ]);

                $created++;
            }

            $this->refreshCounts($occurrence);
        });

        return $created;
    }

    public function complete(ReadinessTask $task, User $by, ?int $evidenceFileId = null): ReadinessTask
    {
        // The evidence requirement lives on the TEMPLATE task, not on the
        // materialised one — `bcms_readiness_tasks` carries the file, and the
        // template carries whether one is needed.
        $needsEvidence = $task->templateTask !== null && (bool) $task->templateTask->requires_evidence;

        if ($task->is_blocking && $needsEvidence && $evidenceFileId === null && $task->evidence_file_id === null) {
            throw new InvalidArgumentException(
                'This blocking task needs evidence attached before it can be closed. "Somebody said it was done" '
                .'is what the evidence requirement exists to replace.'
            );
        }

        $task->update([
            'status' => 'complete',
            'completed_at' => now(),
            'completed_by' => $by->getKey(),
            'evidence_file_id' => $evidenceFileId ?? $task->evidence_file_id,
        ]);

        $this->refreshCounts($task->occurrence);

        return $task->refresh();
    }

    /**
     * Override a blocking task so the exercise can go ahead.
     *
     * NEVER SILENTLY. The reason, the person and the moment are recorded, the
     * task stays visibly overridden rather than becoming complete, and the AAR
     * reports it. An override that looked like a completion would let a bank
     * run a DR failover with no rollback plan and no record that anybody
     * decided to.
     */
    public function override(ReadinessTask $task, User $by, string $reason): ReadinessTask
    {
        if (! $task->is_blocking) {
            throw new InvalidArgumentException(
                'Only a blocking task needs an override. This one is advisory — close it or leave it.'
            );
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('An override has to say why. That sentence is the whole record.');
        }

        $task->update([
            'status' => 'waived',
            'override_reason' => $reason,
            'overridden_by' => $by->getKey(),
            'overridden_at' => now(),
        ]);

        $occurrence = $task->occurrence;

        if ($occurrence !== null) {
            $occurrence->recordAudit('readiness_override', [
                'task' => $task->title,
                'reason' => $reason,
                'by' => $by->name,
            ]);

            $this->refreshCounts($occurrence);
        }

        return $task->refresh();
    }

    /**
     * Whether this occurrence may be started.
     *
     * @return array{allowed: bool, reason: ?string, blocking: list<array<string, mixed>>}
     */
    public function gate(ExerciseOccurrence $occurrence): array
    {
        $blocking = ReadinessTask::query()
            ->where('occurrence_id', $occurrence->getKey())
            ->where('is_blocking', true)
            ->whereIn('status', ['open', 'in_progress', 'overdue'])
            ->orderBy('due_date')
            ->get();

        $definition = $occurrence->definition;

        if ($definition === null || ! $definition->readiness_gating) {
            return [
                'allowed' => true,
                // Reported even when it does not gate: a facilitator about to
                // run an exercise with four blocking items open should see that,
                // whether or not the system will stop them.
                'reason' => $blocking->isEmpty()
                    ? null
                    : $blocking->count().' blocking readiness items are still open, but this exercise is not gated.',
                'blocking' => $this->present($blocking),
            ];
        }

        if ($blocking->isEmpty()) {
            return ['allowed' => true, 'reason' => null, 'blocking' => []];
        }

        return [
            'allowed' => false,
            'reason' => 'This exercise cannot start while '.$blocking->count().' blocking readiness items are open. '
                .'Close them, or override each one with a reason — an override is recorded and appears in the '
                .'after-action report.',
            'blocking' => $this->present($blocking),
        ];
    }

    /**
     * Recompute the stored counters on the occurrence.
     *
     * @return array{open: int, blocking_open: int, complete: int, total: int}
     */
    public function refreshCounts(?ExerciseOccurrence $occurrence): array
    {
        if ($occurrence === null) {
            return ['open' => 0, 'blocking_open' => 0, 'complete' => 0, 'total' => 0];
        }

        $tasks = ReadinessTask::query()->where('occurrence_id', $occurrence->getKey())->get();

        $open = $tasks->filter(fn (ReadinessTask $t) => in_array($t->status, ['open', 'in_progress', 'overdue'], true));
        $blockingOpen = $open->filter(fn (ReadinessTask $t) => (bool) $t->is_blocking);

        $occurrence->forceFill([
            // Complete means nothing is outstanding, including the advisory
            // items. A waived task counts as settled — somebody decided.
            'readiness_complete' => $tasks->isNotEmpty() && $open->isEmpty(),
            'blocking_tasks_open' => $blockingOpen->count(),
        ])->save();

        return [
            'open' => $open->count(),
            'blocking_open' => $blockingOpen->count(),
            'complete' => $tasks->count() - $open->count(),
            'total' => $tasks->count(),
        ];
    }

    /**
     * Mark overdue what is overdue.
     *
     * WRITTEN BY A SWEEP, NOT COMPUTED ON READ. The same argument the CAPA
     * register makes: an accessor makes "overdue" a property of when you looked,
     * and a board pack printed in March has to still say in December what it
     * said in March.
     */
    public function markOverdue(): int
    {
        return ReadinessTask::query()
            ->whereIn('status', ['open', 'in_progress'])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->toDateString())
            ->update(['status' => 'overdue', 'updated_at' => now()]);
    }

    /**
     * The tasks one person owes, across every exercise.
     *
     * @return list<array<string, mixed>>
     */
    public function forUser(User $user): array
    {
        $tasks = ReadinessTask::query()
            ->where('owner_id', $user->getKey())
            ->whereIn('status', ['open', 'in_progress', 'overdue'])
            ->with(['occurrence.definition:id,uuid,name', 'occurrence:id,uuid,definition_id,scheduled_date'])
            ->orderBy('due_date')
            ->get();

        return $tasks->map(fn (ReadinessTask $t) => [
            'id' => $t->getKey(),
            'title' => $t->title,
            'due_date' => $t->due_date?->toDateString(),
            'is_blocking' => (bool) $t->is_blocking,
            'status' => $t->status,
            'is_overdue' => $t->due_date !== null && $t->due_date->lt(now()->startOfDay()),
            'exercise' => $t->occurrence?->definition?->name,
            'exercise_date' => $t->occurrence?->scheduled_date?->toDateString(),
            'occurrence_uuid' => $t->occurrence?->uuid,
        ])->all();
    }

    /* ------------------------------------------------------------------ */

    private function templateFor(ExerciseOccurrence $occurrence): ?ReadinessTemplate
    {
        $type = $occurrence->definition?->exerciseType;

        if ($type?->readiness_template_id !== null) {
            return ReadinessTemplate::query()->find($type->readiness_template_id);
        }

        // A type with no checklist falls back to the generic one. A type with
        // NO checklist at all would generate an occurrence with an empty
        // readiness ladder, which reads as "nothing to do" rather than as
        // "nobody configured this".
        return ReadinessTemplate::query()->where('code', 'GENERIC')->first();
    }

    private function dueDateFor(ExerciseOccurrence $occurrence, int $offsetDays): ?Carbon
    {
        // An unscheduled occurrence has no date to count from, so its tasks
        // have no due dates — rather than dates counted from today, which would
        // be a deadline the system invented.
        return $occurrence->scheduled_date?->copy()->addDays($offsetDays);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ReadinessTask>  $tasks
     * @return list<array<string, mixed>>
     */
    private function present($tasks): array
    {
        return $tasks->map(fn (ReadinessTask $t) => [
            'id' => $t->getKey(),
            'title' => $t->title,
            'owner_id' => $t->owner_id,
            'due_date' => $t->due_date?->toDateString(),
            'status' => $t->status,
        ])->all();
    }

    /** Whether an occurrence is in a state where readiness still matters. */
    public function isRelevant(ExerciseOccurrence $occurrence): bool
    {
        return $occurrence->status->isOpen() || $occurrence->status === OccurrenceStatus::InProgress;
    }
}
