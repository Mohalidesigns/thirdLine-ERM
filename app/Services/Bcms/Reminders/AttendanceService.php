<?php

namespace App\Services\Bcms\Reminders;

use App\Models\Bcms\ExerciseOccurrence;
use App\Models\Bcms\ExerciseParticipant;
use App\Models\User;
use App\Services\Bcms\ContactResolver;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The T-3 confirmation, and the rule that makes it worth asking.
 *
 * A DECLINE MUST NOMINATE A DEPUTY. An exercise the right people do not attend
 * is not evidence of anything — a DR failover rehearsed by whoever was free is
 * a rehearsal of the wrong thing, and the AAR that follows says the plan works
 * when what it tested was three substitutes reading it for the first time.
 * Letting somebody decline into a gap turns the attendance question into a
 * politeness.
 *
 * THE DEPUTY IS A PARTICIPANT ROW, NOT A COLUMN. `bcms_exercise_participants`
 * already carries a `deputy` role, and a deputy who is a row is somebody the
 * reminder ladder resolves, the roll-call counts and the AAR lists. A
 * `deputy_user_id` column would have been a second, invisible participant list.
 */
class AttendanceService
{
    public function __construct(private readonly ContactResolver $contacts) {}

    public function confirm(ExerciseOccurrence $occurrence, User $user): ExerciseParticipant
    {
        $participant = $this->participantFor($occurrence, $user);

        $participant->update(['invitation_status' => 'accepted']);

        return $participant->refresh();
    }

    /**
     * Decline, with a deputy who takes the place.
     */
    public function decline(
        ExerciseOccurrence $occurrence,
        User $user,
        ?User $deputy,
        ?string $reason = null,
    ): ExerciseParticipant {
        if ($deputy === null) {
            throw new InvalidArgumentException(
                'Declining an exercise requires a nominated deputy. An exercise the right people do not attend is '
                .'not evidence that the plan works.'
            );
        }

        if ((int) $deputy->getKey() === (int) $user->getKey()) {
            throw new InvalidArgumentException('Somebody cannot deputise for themselves.');
        }

        return DB::transaction(function () use ($occurrence, $user, $deputy, $reason) {
            $participant = $this->participantFor($occurrence, $user);

            $participant->update(['invitation_status' => 'declined']);

            $deputyContact = $this->contacts->forUser($deputy);

            ExerciseParticipant::query()->updateOrCreate(
                ['occurrence_id' => $occurrence->getKey(), 'user_id' => $deputy->getKey()],
                [
                    'organization_id' => $occurrence->organization_id,
                    'contact_id' => $deputyContact?->getKey(),
                    'role' => 'deputy',
                    'business_unit_id' => $deputy->business_unit_id,
                    // Pending, not accepted. A deputy nominated by somebody
                    // else has not agreed to anything yet, and the ladder will
                    // ask them.
                    'invitation_status' => 'pending',
                ]
            );

            $occurrence->recordAudit('attendance_declined', [
                'by' => $user->name,
                'deputy' => $deputy->name,
                'reason' => $reason,
            ]);

            return $participant->refresh();
        });
    }

    /**
     * Where the confirmations stand.
     *
     * @return array{
     *   invited: int, accepted: int, declined: int, pending: int, tentative: int,
     *   percentage: ?float, outstanding: list<array<string, mixed>>
     * }
     */
    public function summary(ExerciseOccurrence $occurrence): array
    {
        $rows = ExerciseParticipant::query()
            ->where('occurrence_id', $occurrence->getKey())
            ->with('user:id,name')
            ->get();

        $counts = $rows->groupBy('invitation_status')->map->count();
        $invited = $rows->count();
        $accepted = (int) ($counts['accepted'] ?? 0);

        return [
            'invited' => $invited,
            'accepted' => $accepted,
            'declined' => (int) ($counts['declined'] ?? 0),
            'pending' => (int) ($counts['pending'] ?? 0) + (int) ($counts['sent'] ?? 0),
            'tentative' => (int) ($counts['tentative'] ?? 0),
            // Null, never 100%, when nobody has been invited. An exercise with
            // no participants has not achieved full attendance.
            'percentage' => $invited === 0 ? null : round(($accepted / $invited) * 100, 1),
            'outstanding' => $rows
                ->filter(fn (ExerciseParticipant $p) => in_array($p->invitation_status, ['pending', 'sent'], true))
                ->map(fn (ExerciseParticipant $p) => [
                    'user_id' => $p->user_id,
                    'name' => $p->user?->name,
                    'role' => $p->role,
                ])->values()->all(),
        ];
    }

    private function participantFor(ExerciseOccurrence $occurrence, User $user): ExerciseParticipant
    {
        $participant = ExerciseParticipant::query()
            ->where('occurrence_id', $occurrence->getKey())
            ->where('user_id', $user->getKey())
            ->first();

        if ($participant !== null) {
            return $participant;
        }

        // Somebody responding to a reminder they received through the
        // definition's audience rule rather than through a materialised
        // participant row. Their answer is worth keeping, so the row is created
        // rather than the response refused.
        return ExerciseParticipant::query()->create([
            'organization_id' => $occurrence->organization_id,
            'occurrence_id' => $occurrence->getKey(),
            'user_id' => $user->getKey(),
            'contact_id' => $this->contacts->forUser($user)?->getKey(),
            'role' => 'participant',
            'business_unit_id' => $user->business_unit_id,
            'invitation_status' => 'pending',
        ]);
    }
}
