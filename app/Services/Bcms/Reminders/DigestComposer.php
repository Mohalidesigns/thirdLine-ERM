<?php

namespace App\Services\Bcms\Reminders;

use App\Contracts\Bcms\RenderedMessage;
use App\Enums\Bcms\AlertSeverity;
use App\Models\Bcms\Contact;
use App\Models\Bcms\ReadinessTask;
use App\Models\Bcms\ReminderSchedule;
use App\Support\Bcms\ReminderLadder;
use Illuminate\Support\Carbon;

/**
 * The words. One person, one day, every exercise they are involved in.
 *
 * THE DIGEST IS THE FATIGUE GUARD MADE VISIBLE. Somebody in five overlapping
 * exercises gets one message listing five lines, not five messages. The
 * difference between a system people respect and a system they filter to junk
 * is entirely in this class and in the dispatcher that calls it.
 *
 * CONTENT VARIES WITH WHAT THE PERSON STILL OWES. "3 of 7 readiness items
 * outstanding — 6 days to the Fire Drill (Head Office)" is a message somebody
 * acts on. "Reminder: you have an exercise" is one they learn to ignore, and
 * six of those in a row teaches them to ignore the seventh, which is the T-1
 * final brief.
 *
 * EVERY EXERCISE MESSAGE CARRIES THE SIMULATION PREFIX. Standing rule 5, applied
 * by `RenderedMessage::wireBody()` rather than by whoever composes one — a rule
 * every caller has to remember is a rule that gets forgotten once, at the worst
 * possible moment.
 */
class DigestComposer
{
    /**
     * One consolidated message for one person on one day.
     *
     * @param  list<array{schedule: ReminderSchedule, occurrence: \App\Models\Bcms\ExerciseOccurrence}>  $items
     */
    public function digest(Contact $contact, array $items, Carbon $on): RenderedMessage
    {
        $lines = [];
        $outstandingTotal = 0;

        foreach ($items as $item) {
            $occurrence = $item['occurrence'];
            $definition = $occurrence->definition;
            $date = $occurrence->scheduled_date;
            $days = $date === null ? null : (int) $on->copy()->startOfDay()->diffInDays($date->copy()->startOfDay(), false);

            $tasks = $this->openTasksFor($contact, $occurrence);
            $total = $this->totalTasksFor($contact, $occurrence);
            $outstandingTotal += count($tasks);

            $lines[] = $this->line(
                $definition === null ? 'An exercise' : $definition->name,
                $days, count($tasks), $total, $occurrence,
            );
        }

        $subject = count($items) === 1
            ? $this->singleSubject($items[0], $outstandingTotal)
            : 'Business continuity: '.count($items).' exercises coming up'
                .($outstandingTotal > 0 ? ', '.$outstandingTotal.' items outstanding' : '');

        $body = implode("\n", array_merge(
            [$outstandingTotal > 0
                ? 'You have '.$outstandingTotal.' readiness items outstanding.'
                : 'Nothing is outstanding for you. This is a summary of what is coming.'],
            [''],
            $lines,
            [''],
            ['Open the resilience calendar to complete an item or hand it to somebody else.'],
        ));

        return new RenderedMessage(
            body: $body,
            subject: $subject,
            locale: (string) ($contact->preferred_language ?? 'en'),
            severity: AlertSeverity::Advisory,
            // Every exercise message says so. A drill notice mistaken for a
            // real activation is the failure standing rule 5 exists for.
            isSimulation: true,
            metadata: ['kind' => 'digest', 'occurrences' => count($items)],
        );
    }

    /**
     * A discrete rung — the formal notice, the attendance request, the final
     * brief, the go-live.
     *
     * These arrive on their own deliberately. They are the four moments where a
     * line in a summary would not be read in time.
     */
    public function discrete(
        Contact $contact,
        ReminderSchedule $schedule,
        \App\Models\Bcms\ExerciseOccurrence $occurrence,
        Carbon $on,
    ): RenderedMessage {
        $definition = $occurrence->definition;
        $name = $definition === null ? 'A business continuity exercise' : $definition->name;
        $date = $occurrence->scheduled_date;
        $when = $date?->format('l j F');
        $time = $occurrence->scheduled_start?->format('H:i');
        $rung = ReminderLadder::findTemplate((string) $schedule->template_key);

        [$subject, $body] = match ($schedule->template_key) {
            'exercise.notice' => [
                $name.' — '.$when,
                $this->notice($name, $occurrence, $contact),
            ],
            'exercise.confirm_attendance' => [
                'Please confirm: '.$name.' on '.$when,
                "You are down to take part in {$name} on {$when}".($time ? " at {$time}" : '').".\n\n"
                .'Please confirm or decline. If you decline, nominate a deputy — an exercise the right people do '
                .'not attend is not evidence of anything.',
            ],
            'exercise.escalation' => [
                'Escalation: readiness incomplete for '.$name,
                $this->escalation($name, $occurrence, $when),
            ],
            'exercise.final_brief' => [
                'Tomorrow: '.$name,
                $this->finalBrief($name, $occurrence, $when, $time),
            ],
            'exercise.go_live' => [
                $name.' starts in one hour',
                "{$name} starts at {$time}."
                .($occurrence->location !== null ? "\nWhere: {$occurrence->location}" : '')
                ."\n\nThis is an exercise. Do not treat any instruction in it as a real event.",
            ],
            'exercise.workspace_open' => [
                $name.' — execution workspace open',
                "The workspace for {$name} is open. Log the timeline as it happens rather than afterwards.",
            ],
            'exercise.aar_due', 'exercise.aar_overdue' => [
                'After-action report due: '.$name,
                "The after-action report for {$name} is due.\n\n"
                .'An AAR written a fortnight later is a memory rather than a record, and the corrective actions '
                .'it produces are what the exercise was for.',
            ],
            'exercise.aar_escalation' => [
                'Overdue after-action report: '.$name,
                "The after-action report for {$name} is a week overdue. It is now with you as programme owner.",
            ],
            'exercise.capa_due' => [
                'Corrective actions due from '.$name,
                "Corrective actions raised by {$name} are due for acceptance.",
            ],
            default => [$name, $rung['purpose'] ?? $name],
        };

        return new RenderedMessage(
            body: $body,
            subject: $subject,
            locale: (string) ($contact->preferred_language ?? 'en'),
            severity: AlertSeverity::Advisory,
            isSimulation: true,
            responseRequired: $schedule->template_key === 'exercise.confirm_attendance',
            metadata: ['kind' => 'discrete', 'template' => $schedule->template_key],
        );
    }

    /* ------------------------------------------------------------------ */

    private function notice(string $name, \App\Models\Bcms\ExerciseOccurrence $occurrence, Contact $contact): string
    {
        $definition = $occurrence->definition;
        $objectives = is_array($definition?->objectives) ? $definition->objectives : [];
        $tasks = $this->openTasksFor($contact, $occurrence);

        $parts = [
            'You are taking part in '.$name.' on '
                .($occurrence->scheduled_date?->format('l j F') ?? 'a date to be confirmed')
                .($occurrence->scheduled_start !== null ? ' at '.$occurrence->scheduled_start->format('H:i') : '')
                .'.',
        ];

        if ($definition?->exerciseType !== null) {
            $parts[] = 'Type: '.$definition->exerciseType->name
                .' ('.$definition->exerciseType->ladder_level?->label().').';
        }

        if ($objectives !== []) {
            $parts[] = "\nWhat it is testing:";

            foreach (array_slice($objectives, 0, 6) as $objective) {
                $parts[] = ' · '.(is_array($objective) ? ($objective['title'] ?? json_encode($objective)) : $objective);
            }
        }

        if ($tasks !== []) {
            $parts[] = "\nYour readiness items (".count($tasks).'):';

            foreach ($tasks as $task) {
                $parts[] = ' · '.$task->title
                    .($task->due_date !== null ? ' — due '.$task->due_date->format('j M') : '')
                    .($task->is_blocking ? ' [blocking]' : '');
            }
        }

        return implode("\n", $parts);
    }

    private function escalation(string $name, \App\Models\Bcms\ExerciseOccurrence $occurrence, ?string $when): string
    {
        $open = ReadinessTask::query()
            ->where('occurrence_id', $occurrence->getKey())
            ->where('is_blocking', true)
            ->whereIn('status', ['open', 'in_progress', 'overdue'])
            ->with('owner:id,name')
            ->orderBy('due_date')
            ->get();

        $lines = [
            $name.' is in two days'.($when !== null ? ' ('.$when.')' : '').' and '.$open->count()
                .' blocking readiness items are still open.',
            '',
        ];

        foreach ($open as $task) {
            $owner = $task->owner;

            $lines[] = ' · '.$task->title.' — '.($owner === null ? 'unassigned' : $owner->name)
                .($task->due_date !== null ? ', due '.$task->due_date->format('j M') : '');
        }

        $lines[] = '';
        $gated = $occurrence->definition !== null && (bool) $occurrence->definition->readiness_gating;

        $lines[] = $gated
            ? 'This exercise is gated: it cannot start while these are open. Either they are closed, or somebody '
                .'records an override with a reason — which the after-action report will carry.'
            : 'This exercise is not gated, so it can go ahead regardless. Whether it should is your call.';

        return implode("\n", $lines);
    }

    private function finalBrief(string $name, \App\Models\Bcms\ExerciseOccurrence $occurrence, ?string $when, ?string $time): string
    {
        $lines = [
            $name.' is tomorrow'.($when !== null ? ', '.$when : '').($time !== null ? ' at '.$time : '').'.',
            '',
        ];

        if ($occurrence->location !== null) {
            $lines[] = 'Where: '.$occurrence->location;
        }

        if ($occurrence->site !== null) {
            $lines[] = 'Site: '.$occurrence->site->name;
        }

        $lines[] = '';
        $lines[] = 'What good looks like: the plan is followed as written, and where it cannot be, somebody says so '
            .'at the time rather than afterwards. A failed exercise that produces three corrective actions is worth '
            .'more than a smooth one that produces none.';
        $lines[] = '';
        $lines[] = 'This is an exercise. Do not treat any instruction in it as a real event, and do not act on it '
            .'outside the exercise.';

        return implode("\n", $lines);
    }

    /** @param array{schedule: ReminderSchedule, occurrence: \App\Models\Bcms\ExerciseOccurrence} $item */
    private function singleSubject(array $item, int $outstanding): string
    {
        $definition = $item['occurrence']->definition;
        $name = $definition === null ? 'A business continuity exercise' : $definition->name;

        return $outstanding > 0
            ? $outstanding.' readiness items outstanding — '.$name
            : 'Coming up: '.$name;
    }

    private function line(string $name, ?int $days, int $open, int $total, \App\Models\Bcms\ExerciseOccurrence $occurrence): string
    {
        $site = $occurrence->site?->name;
        $where = $site !== null ? ' ('.$site.')' : '';

        $when = match (true) {
            $days === null => 'date to be confirmed',
            $days === 0 => 'today',
            $days === 1 => 'tomorrow',
            $days > 0 => $days.' days',
            default => abs($days).' days ago',
        };

        // The sentence Blueprint §5.4 asks for, exactly: "3 of 7 readiness items
        // outstanding — 6 days to the Fire Drill (Head Office)".
        return $open > 0
            ? ' · '.$open.' of '.$total.' readiness items outstanding — '.$when.' to '.$name.$where
            : ' · '.ucfirst($when === 'today' || $when === 'tomorrow' ? $when : 'in '.$when).': '
                .$name.$where.' — nothing outstanding for you';
    }

    /** @return list<ReadinessTask> */
    private function openTasksFor(Contact $contact, \App\Models\Bcms\ExerciseOccurrence $occurrence): array
    {
        if ($contact->user_id === null) {
            return [];
        }

        return ReadinessTask::query()
            ->where('occurrence_id', $occurrence->getKey())
            ->where('owner_id', $contact->user_id)
            ->whereIn('status', ['open', 'in_progress', 'overdue'])
            ->orderBy('due_date')
            ->get()
            ->all();
    }

    private function totalTasksFor(Contact $contact, \App\Models\Bcms\ExerciseOccurrence $occurrence): int
    {
        if ($contact->user_id === null) {
            return 0;
        }

        return (int) ReadinessTask::query()
            ->where('occurrence_id', $occurrence->getKey())
            ->where('owner_id', $contact->user_id)
            ->count();
    }
}
