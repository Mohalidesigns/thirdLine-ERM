<?php

namespace App\Services\Bcms\Exercises;

use App\Enums\Bcms\OccurrenceStatus;
use App\Models\ApprovalRequest;
use App\Models\Bcms\ExerciseOccurrence;
use App\Models\User;
use App\Services\ApprovalService;
use App\Support\Bcms\PreferredWindow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Moving an exercise, and the governance that makes moving it visible.
 *
 * *Regulators care as much about what you moved as what you ran.* That sentence
 * is the whole design. Anyone can drag a date; what this adds is that the move
 * is dated, attributed, justified, counted, and — inside `min_notice_days` —
 * approved by somebody other than the person who wants the exercise to go away.
 *
 * `min_notice_days` IS THE ANTI-GAMING RULE. Outside it, a reschedule is
 * ordinary planning and happens immediately with a justification on the record.
 * Inside it, the exercise is close enough that moving it is how a team avoids
 * one, so it becomes a REQUEST routed to the programme owner and nothing moves
 * until they agree. A system that let both happen silently would produce a
 * completion rate that means nothing.
 *
 * A MANDATORY EXERCISE CANNOT BE CANCELLED WITHOUT A WAIVER. Cancelling is not
 * a reschedule with no new date — it removes an obligation the organisation
 * committed to, and `mandatory` on the definition is where that commitment was
 * recorded.
 *
 * THE APPROVAL RIDES ON THE PLATFORM'S OWN ENGINE. `ApprovalRequest` already
 * does routing, payload application and audit; a BCMS-specific approvals table
 * would be a second inbox for the same person to forget to check.
 */
class RescheduleService
{
    public const ACTION = 'bcms.occurrence.reschedule';

    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly ConflictDetector $conflicts,
    ) {}

    /**
     * Move an occurrence, or ask permission to.
     *
     * @return array{moved: bool, approval: ?ApprovalRequest, conflicts: list<array<string, mixed>>}
     */
    public function reschedule(
        ExerciseOccurrence $occurrence,
        string $newDate,
        string $justification,
        User $actor,
        bool $acceptConflicts = false,
    ): array {
        $definition = $occurrence->definition;

        if ($definition === null) {
            throw new InvalidArgumentException('This occurrence has no definition, so there is nothing to reschedule against.');
        }

        if (! $occurrence->status->isOpen()) {
            throw new InvalidArgumentException(
                'A '.$occurrence->status->value.' exercise cannot be rescheduled. It is a record of what happened.'
            );
        }

        if (trim($justification) === '') {
            throw new InvalidArgumentException(
                'Moving an exercise requires a reason. "Regulators care as much about what you moved as what you ran" '
                .'is not a slogan — the reason is the record.'
            );
        }

        $window = PreferredWindow::fromJson($definition->preferred_window);
        $start = Carbon::parse($newDate.' '.($window?->startTime() ?? OccurrenceGenerator::DEFAULT_START_TIME));
        $end = $start->copy()->addMinutes(max(15, (int) $definition->duration_minutes));

        $conflicts = $this->conflicts->check($definition, $start, $end, $occurrence->getKey());

        if ($conflicts !== [] && ! $acceptConflicts) {
            return ['moved' => false, 'approval' => null, 'conflicts' => $conflicts];
        }

        $payload = [
            'scheduled_date' => $start->toDateString(),
            'scheduled_start' => $start->toDateTimeString(),
            'scheduled_end' => $end->toDateTimeString(),
            // Part of the payload, so that approving the request applies the
            // counter with the move rather than leaving them free to disagree.
            'reschedule_count' => (int) $occurrence->reschedule_count + 1,
        ];

        if ($this->needsApproval($occurrence, $start)) {
            $approval = $this->approvals->requestApproval(
                entity: $occurrence,
                action: self::ACTION,
                payload: $payload,
                userId: $actor->getKey(),
                reviewerId: $this->approverFor($occurrence),
            );

            $approval->update([
                'payload' => $payload + [
                    // Kept beside the payload rather than only in `comments`,
                    // so the before/after pair survives on the request itself.
                    '_from' => $occurrence->scheduled_date?->toDateString(),
                    '_justification' => $justification,
                ],
            ]);

            return ['moved' => false, 'approval' => $approval, 'conflicts' => $conflicts];
        }

        DB::transaction(function () use ($occurrence, $payload, $justification, $actor) {
            $occurrence->update($payload + ['updated_by' => $actor->getKey()]);

            // The justification lives in the audit log rather than in a column:
            // `BcmsAuditable` already writes the before/after diff of
            // `scheduled_date`, and this is the sentence that goes with it.
            $occurrence->recordAudit('rescheduled', ['justification' => $justification]);
        });

        return ['moved' => true, 'approval' => null, 'conflicts' => $conflicts];
    }

    /**
     * Cancel an occurrence.
     *
     * A mandatory exercise needs an executive waiver, which is an approval by
     * somebody holding `bcms.exercise.approve` rather than a checkbox on a form.
     */
    public function cancel(
        ExerciseOccurrence $occurrence,
        string $reason,
        User $actor,
        bool $waiverGranted = false,
    ): ExerciseOccurrence {
        if (! $occurrence->status->isOpen()) {
            throw new InvalidArgumentException('This exercise is already '.$occurrence->status->value.'.');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('Cancelling an exercise requires a reason.');
        }

        // `$definition !== null &&` rather than `?->… ??`: a belongsTo on a
        // nullable key is genuinely nullable at runtime and typed non-null by
        // static analysis, and the complaint that follows a `??` is how
        // somebody eventually deletes the null check.
        $definition = $occurrence->definition;

        if ($definition !== null && $definition->mandatory && ! $waiverGranted) {
            throw new InvalidArgumentException(
                'This exercise is mandatory and cannot be cancelled without an executive waiver. The obligation was '
                .'recorded deliberately; removing it has to be a decision somebody signs.'
            );
        }

        $occurrence->update([
            'status' => OccurrenceStatus::Cancelled,
            'cancellation_reason' => $reason,
            'updated_by' => $actor->getKey(),
        ]);

        $occurrence->recordAudit('cancelled', [
            'reason' => $reason,
            'waiver' => $waiverGranted,
            'mandatory' => $definition !== null && (bool) $definition->mandatory,
        ]);

        return $occurrence->refresh();
    }

    /**
     * Whether moving to this date needs the programme owner's agreement.
     *
     * MEASURED FROM TODAY TO THE **CURRENT** DATE, not to the new one. The
     * question is "is this exercise close enough that moving it looks like
     * avoidance", and that is a property of the exercise that is nearly due,
     * not of wherever somebody would like to put it.
     */
    public function needsApproval(ExerciseOccurrence $occurrence, Carbon $newStart): bool
    {
        $definition = $occurrence->definition;
        $minNotice = (int) ($definition === null ? 0 : $definition->min_notice_days);

        if ($minNotice <= 0 || $occurrence->scheduled_date === null) {
            return false;
        }

        return $occurrence->scheduled_date->startOfDay()->lte(now()->startOfDay()->addDays($minNotice));
    }

    /** The pending reschedule request for this occurrence, if there is one. */
    public function pendingFor(ExerciseOccurrence $occurrence): ?ApprovalRequest
    {
        return $this->approvals->latestPending($occurrence);
    }

    /**
     * Who decides. The programme owner where there is one, the definition owner
     * otherwise, and null rather than a guess where there is neither — an
     * unrouted request is visible in the approvals queue, which is better than
     * one sent to somebody arbitrary.
     */
    private function approverFor(ExerciseOccurrence $occurrence): ?int
    {
        $definition = $occurrence->definition;

        if ($definition === null) {
            return null;
        }

        if ($definition->owner_id !== null) {
            return (int) $definition->owner_id;
        }

        $programme = $definition->exerciseProgramme;

        return $programme === null ? null : $programme->created_by;
    }
}
