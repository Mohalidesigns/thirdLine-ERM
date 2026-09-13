<?php

namespace App\Http\Controllers\Bcms;

use App\Http\Controllers\Controller;
use App\Models\Bcms\ExerciseOccurrence;
use App\Models\Bcms\NotificationDelivery;
use App\Models\Bcms\ReadinessTask;
use App\Models\User;
use App\Services\Bcms\Reminders\AttendanceService;
use App\Services\Bcms\Reminders\ReadinessService;
use App\Services\Bcms\Reminders\ReminderAudienceResolver;
use App\Services\Bcms\Reminders\ReminderScheduleBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Readiness, the reminder ladder, and the evidence they produce.
 *
 * THE LADDER PANEL IS A DEMO MOMENT AND IS BUILT AS ONE. "Here are the fourteen
 * alerts this exercise will send, to these forty-six people, on these days,
 * through these channels" — shown before a single one has gone out. That is
 * only possible because the rows are materialised (ADR 0005), and it is the
 * thing that makes a customer believe the countdown exists.
 */
class ReadinessController extends Controller
{
    public function __construct(
        private ReadinessService $readiness,
        private ReminderScheduleBuilder $builder,
        private ReminderAudienceResolver $audience,
        private AttendanceService $attendance,
    ) {}

    /** The occurrence's readiness checklist and its alert plan. */
    public function show(Request $request, ExerciseOccurrence $occurrence): Response
    {
        Gate::authorize('bcms.exercise.view');

        $occurrence->loadMissing(['definition.exerciseType', 'site:id,name', 'facilitator:id,name']);

        $tasks = ReadinessTask::query()
            ->where('occurrence_id', $occurrence->getKey())
            ->with(['owner:id,name', 'templateTask:id,requires_evidence'])
            ->orderBy('due_offset_days')
            ->orderBy('id')
            ->get();

        $gate = $this->readiness->gate($occurrence);

        // A local, because a belongsTo on a nullable key is genuinely nullable
        // at runtime and typed non-null by static analysis — and the complaint
        // that follows a `?->… ??` is how somebody eventually deletes the check.
        $definition = $occurrence->definition;

        return Inertia::render('Bcms/Exercises/Readiness', [
            'occurrence' => [
                'id' => $occurrence->getKey(),
                'uuid' => $occurrence->uuid,
                'title' => $occurrence->definition?->name,
                'type' => $occurrence->definition?->exerciseType?->name,
                'scheduled_date' => $occurrence->scheduled_date?->toDateString(),
                'scheduled_start' => $occurrence->scheduled_start?->toIso8601String(),
                'status' => $occurrence->status->value,
                'site' => $occurrence->site?->name,
                'facilitator' => $occurrence->facilitator?->name,
                'readiness_complete' => (bool) $occurrence->readiness_complete,
                'blocking_tasks_open' => (int) $occurrence->blocking_tasks_open,
                'readiness_gating' => $definition !== null && (bool) $definition->readiness_gating,
                'unannounced' => $definition !== null && (bool) $definition->unannounced,
            ],
            'tasks' => $tasks->map(fn (ReadinessTask $t) => [
                'id' => $t->getKey(),
                'title' => $t->title,
                'description' => $t->description,
                'owner' => $t->owner?->name,
                'owner_id' => $t->owner_id,
                'due_date' => $t->due_date?->toDateString(),
                'due_offset_days' => (int) $t->due_offset_days,
                'status' => $t->status,
                'is_blocking' => (bool) $t->is_blocking,
                'requires_evidence' => $t->templateTask !== null && (bool) $t->templateTask->requires_evidence,
                'has_evidence' => $t->evidence_file_id !== null,
                'override_reason' => $t->override_reason,
                'overridden_at' => $t->overridden_at?->toDateString(),
                'is_overdue' => $t->status === 'overdue',
            ])->all(),
            'gate' => $gate,
            'ladder' => $this->builder->plan($occurrence, $this->audience),
            'attendance' => $this->attendance->summary($occurrence),
            'can' => [
                'manage' => $request->user()?->can('bcms.exercise.manage') === true,
                'facilitate' => $request->user()?->can('bcms.exercise.facilitate') === true,
                'override' => $request->user()?->can('bcms.readiness.override') === true,
                'export' => $request->user()?->can('bcms.report.export') === true,
            ],
        ]);
    }

    /** What I owe, across every exercise. */
    public function mine(Request $request): Response
    {
        Gate::authorize('bcms.exercise.view');

        return Inertia::render('Bcms/Exercises/MyReadiness', [
            'tasks' => $this->readiness->forUser($request->user()),
        ]);
    }

    public function complete(Request $request, ReadinessTask $task): RedirectResponse
    {
        Gate::authorize('bcms.exercise.facilitate');

        $data = $request->validate(['evidence_file_id' => ['nullable', 'integer']]);

        try {
            $this->readiness->complete($task, $request->user(), $data['evidence_file_id'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Marked complete.');
    }

    /**
     * Override a blocking task.
     *
     * A SEPARATE PERMISSION FROM COMPLETING ONE. `bcms.readiness.override` is
     * held by fewer people than `bcms.exercise.facilitate`, because overriding
     * is deciding to run an exercise unprepared and that decision belongs to
     * somebody who will answer for it.
     */
    public function override(Request $request, ReadinessTask $task): RedirectResponse
    {
        Gate::authorize('bcms.readiness.override');

        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:2000']]);

        try {
            $this->readiness->override($task, $request->user(), $data['reason']);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with(
            'success',
            'Overridden. The reason is recorded against the exercise and the after-action report will carry it.'
        );
    }

    /** Rebuild the ladder — after a reschedule, or when somebody wants to see it refreshed. */
    public function regenerate(Request $request, ExerciseOccurrence $occurrence): RedirectResponse
    {
        Gate::authorize('bcms.exercise.manage');

        $this->readiness->materialise($occurrence);
        $result = $this->builder->build($occurrence);

        if ($result['skipped_reason'] !== null) {
            return back()->with('error', $result['skipped_reason']);
        }

        return back()->with('success', sprintf(
            '%d alerts planned, %d already sent and left alone, %d obsolete ones voided.',
            $result['created'] + $result['kept'], $result['kept'], $result['voided'],
        ));
    }

    /** The T-3 response. */
    public function confirmAttendance(Request $request, ExerciseOccurrence $occurrence): RedirectResponse
    {
        Gate::authorize('bcms.exercise.view');

        $data = $request->validate([
            'response' => ['required', 'in:accept,decline'],
            'deputy_user_id' => ['nullable', 'integer',
                'required_if:response,decline',
                \Illuminate\Validation\Rule::exists('users', 'id')
                    ->where('organization_id', $request->user()?->organization_id)],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            if ($data['response'] === 'accept') {
                $this->attendance->confirm($occurrence, $request->user());

                return back()->with('success', 'Thank you — you are down as attending.');
            }

            $this->attendance->decline(
                $occurrence,
                $request->user(),
                User::query()->find($data['deputy_user_id']),
                $data['reason'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Recorded. Your deputy has been added and will get the reminders.');
    }

    /**
     * The delivery evidence for one exercise.
     *
     * EVIDENCE, NOT TELEMETRY. An examiner asking "prove the March drill was
     * notified" is asking for this, and it is why the delivery row is written
     * before the provider is called rather than after it succeeds.
     */
    public function deliveries(Request $request, ExerciseOccurrence $occurrence): StreamedResponse
    {
        Gate::authorize('bcms.report.export');

        $rows = NotificationDelivery::query()
            ->whereIn('reminder_schedule_id', $occurrence->reminderSchedules()->pluck('id'))
            ->with(['reminderSchedule:id,day_offset,template_key', 'contact:id,full_name,email'])
            ->orderBy('created_at')
            ->get();

        $columns = [
            'Day', 'Alert', 'Recipient', 'Channel', 'Address', 'Status',
            'Attempts', 'Sent at', 'Failure', 'Consolidated into',
        ];

        return response()->streamDownload(function () use ($rows, $columns): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, $columns);

            foreach ($rows as $row) {
                $schedule = $row->reminderSchedule;
                $contact = $row->contact;

                fputcsv($out, array_map('strval', [
                    'T'.sprintf('%+d', $schedule === null ? 0 : (int) $schedule->day_offset),
                    $schedule === null ? '' : $schedule->template_key,
                    $contact === null ? '' : $contact->full_name,
                    $row->channel,
                    $row->address ?? '',
                    $row->status,
                    $row->attempts,
                    $row->sent_at?->toDateTimeString() ?? '',
                    $row->failed_reason ?? '',
                    $row->raw_response['consolidated_into'] ?? '',
                ]));
            }

            fclose($out);
        }, 'bcms-reminder-deliveries-'.$occurrence->uuid.'.csv', ['Content-Type' => 'text/csv']);
    }
}
