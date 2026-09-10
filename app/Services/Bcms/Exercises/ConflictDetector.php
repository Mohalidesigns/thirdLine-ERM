<?php

namespace App\Services\Bcms\Exercises;

use App\Enums\Bcms\OccurrenceStatus;
use App\Models\Bcms\ExerciseDefinition;
use App\Models\Bcms\ExerciseOccurrence;
use App\Models\Bcms\ExerciseParticipant;
use App\Services\Bcms\AudienceResolver;
use App\Support\Bcms\AudienceRule;
use Illuminate\Support\Carbon;

/**
 * What else is happening on the day, and whether it collides.
 *
 * PEOPLE, NOT DATES. Two exercises on the same Tuesday are not a conflict; two
 * exercises on the same Tuesday that need the same fifteen people are. So the
 * check is an intersection of resolved audiences over an overlapping time
 * window — which is why `default_audience_rule` is on the definition and why it
 * uses the audience grammar every other targeting feature uses (ADR 0003)
 * rather than a second way of saying "the operations team".
 *
 * TIME WINDOWS, NOT WHOLE DAYS. A 45-minute fire drill at 09:00 and a six-hour
 * DR test at 14:00 share their participants and do not collide. Treating the
 * day as atomic would shift half the calendar for conflicts that are not real,
 * and a generator that moves things for no reason is one nobody trusts.
 *
 * TWO OF THE PROMPT'S FIVE SOURCES DO NOT EXIST IN THIS PRODUCT and are named
 * rather than faked (ADR 0012). There is no audit engagement register here —
 * `issues` carries an audit's *findings*, not its diary — and change-freeze
 * windows are blackout periods, which the working calendar already removed
 * before this class is asked anything.
 */
class ConflictDetector
{
    public function __construct(private readonly AudienceResolver $audience) {}

    /**
     * Everything that collides with putting this definition on this date.
     *
     * @return list<array{kind: string, severity: string, message: string, occurrence_id: ?int}>
     */
    public function check(
        ExerciseDefinition $definition,
        Carbon $start,
        Carbon $end,
        ?int $ignoreOccurrenceId = null,
    ): array {
        $conflicts = [];

        $candidates = ExerciseOccurrence::query()
            ->whereDate('scheduled_date', $start->toDateString())
            ->whereIn('status', array_map(
                fn (OccurrenceStatus $s) => $s->value,
                array_filter(OccurrenceStatus::cases(), fn (OccurrenceStatus $s) => $s->isOpen() || $s === OccurrenceStatus::Completed),
            ))
            ->when($ignoreOccurrenceId !== null, fn ($q) => $q->whereKeyNot($ignoreOccurrenceId))
            ->with('definition:id,name,site_id,default_audience_rule')
            ->get();

        if ($candidates->isEmpty()) {
            return [];
        }

        $mine = $this->participantIdsFor($definition);

        foreach ($candidates as $occurrence) {
            if (! $this->overlaps($start, $end, $occurrence)) {
                continue;
            }

            $theirDefinition = $occurrence->definition;

            if ($definition->site_id !== null && (int) $occurrence->site_id === (int) $definition->site_id) {
                $conflicts[] = [
                    'kind' => 'site',
                    'severity' => 'blocking',
                    'message' => '"'.($theirDefinition === null ? 'Another exercise' : $theirDefinition->name)
                        .'" is already booked at this site in the same window.',
                    'occurrence_id' => $occurrence->getKey(),
                ];

                continue;
            }

            $theirs = $theirDefinition === null
                ? []
                : $this->participantIdsFor($theirDefinition, $occurrence);

            $shared = array_intersect($mine, $theirs);

            if ($shared === []) {
                continue;
            }

            $conflicts[] = [
                'kind' => 'participants',
                'severity' => 'blocking',
                'message' => count($shared).' of the people needed for this exercise are already committed to "'
                    .($theirDefinition === null ? 'another exercise' : $theirDefinition->name).'" in the same window.',
                'occurrence_id' => $occurrence->getKey(),
            ];
        }

        return $conflicts;
    }

    /**
     * Who this exercise needs.
     *
     * A MATERIALISED OCCURRENCE'S OWN PARTICIPANTS WIN. Once somebody has been
     * invited to a specific occurrence, that row is the truth and the
     * definition's audience rule is only how the list was first arrived at —
     * people get added and removed, and a conflict check against the rule would
     * be checking who was invited last year.
     *
     * @return list<string> stable `contact:12` / `user:4` keys
     */
    public function participantIdsFor(ExerciseDefinition $definition, ?ExerciseOccurrence $occurrence = null): array
    {
        if ($occurrence !== null) {
            $rows = ExerciseParticipant::query()
                ->where('occurrence_id', $occurrence->getKey())
                ->get(['user_id', 'contact_id']);

            if ($rows->isNotEmpty()) {
                return $rows
                    ->map(fn (ExerciseParticipant $p) => $p->contact_id !== null
                        ? 'contact:'.$p->contact_id
                        : 'user:'.$p->user_id)
                    ->unique()
                    ->values()
                    ->all();
            }
        }

        $rule = AudienceRule::fromJson($definition->default_audience_rule);

        if ($rule === null) {
            // A definition with no audience is not "everybody" — it is a
            // definition nobody has finished configuring, and it conflicts with
            // nothing rather than with everything (ADR 0003: fail closed).
            return [];
        }

        return $this->audience->resolve($rule)
            ->map(fn ($contact) => 'contact:'.$contact->getKey())
            ->values()
            ->all();
    }

    /**
     * The seam for audit engagements.
     *
     * THERE IS NO AUDIT ENGAGEMENT REGISTER IN THIS PRODUCT (ADR 0012). The
     * phase prompt lists thirdLine's audit diary as a conflict source; thirdLine
     * is this product, and what it holds is `issues` with an audit *source* —
     * the output of an audit, not its schedule. This returns nothing and says
     * so, because an empty conflict source that looks implemented is worse than
     * one that admits it is a seam.
     *
     * @return list<array{kind: string, severity: string, message: string, occurrence_id: ?int}>
     */
    public function auditEngagements(Carbon $start, Carbon $end): array
    {
        return [];
    }

    private function overlaps(Carbon $start, Carbon $end, ExerciseOccurrence $occurrence): bool
    {
        $theirStart = $occurrence->scheduled_start;
        $theirEnd = $occurrence->scheduled_end;

        // An occurrence with a date but no times is treated as taking the whole
        // day. That is the safe reading: it is unscheduled in time, so anything
        // else that day might genuinely clash with it.
        if ($theirStart === null || $theirEnd === null) {
            return true;
        }

        return $start->lt($theirEnd) && $end->gt($theirStart);
    }
}
