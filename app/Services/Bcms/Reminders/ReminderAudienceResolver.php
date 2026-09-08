<?php

namespace App\Services\Bcms\Reminders;

use App\Models\Bcms\Contact;
use App\Models\Bcms\ExerciseOccurrence;
use App\Models\Bcms\ExerciseParticipant;
use App\Models\Bcms\ReadinessTask;
use App\Models\Bcms\ReminderSchedule;
use App\Models\User;
use App\Services\Bcms\AudienceResolver;
use App\Services\Bcms\ContactResolver;
use App\Support\Bcms\AudienceRule;
use App\Support\Bcms\ReminderLadder;
use Illuminate\Support\Collection;

/**
 * Who a rung goes to.
 *
 * SIX SELECTORS, AND THREE OF THEM ARE QUESTIONS ABOUT STATE. "All participants"
 * is a list; "participants with open readiness tasks" is a query that gives a
 * different answer every morning, which is exactly what makes the daily
 * countdown worth receiving. That is why the selector is stored beside the
 * `AudienceRule` rather than as one: ADR 0003's grammar targets org nodes,
 * sites, roles and call trees — the things that do not change between Tuesday
 * and Wednesday.
 *
 * PARTICIPANT ROWS WIN OVER THE DEFINITION'S RULE. Once somebody has been
 * invited to a specific occurrence, that row is the truth; the rule is only how
 * the list was first arrived at. People get added and removed, and a countdown
 * resolved from the rule would be reminding whoever was invited last year.
 *
 * EVERYTHING RESOLVES TO CONTACTS, NEVER TO USERS. A user has no channel — the
 * `bcms_contacts` roster is the only thing that knows a mobile number, a
 * WhatsApp address or a language preference, and `ContactResolver` is the only
 * way to reach it (frozen at G0).
 */
class ReminderAudienceResolver
{
    public function __construct(
        private readonly AudienceResolver $audiences,
        private readonly ContactResolver $contacts,
    ) {}

    /**
     * The contacts one scheduled rung goes to.
     *
     * @return Collection<int, Contact>
     */
    public function resolve(ExerciseOccurrence $occurrence, ReminderSchedule $schedule): Collection
    {
        $selector = (string) ($schedule->audience_rule['selector'] ?? ReminderLadder::AUDIENCE_ALL);

        return match ($selector) {
            ReminderLadder::AUDIENCE_OPEN_TASKS => $this->withOpenTasks($occurrence),
            ReminderLadder::AUDIENCE_COMPLETE => $this->allComplete($occurrence),
            ReminderLadder::AUDIENCE_OWNERS => $this->ownersAndManagers($occurrence),
            ReminderLadder::AUDIENCE_FACILITATORS => $this->facilitatorsAndObservers($occurrence),
            ReminderLadder::AUDIENCE_CAPA_OWNERS => $this->capaOwners($occurrence),
            default => $this->allParticipants($occurrence, $schedule),
        };
    }

    public function countFor(ExerciseOccurrence $occurrence, ReminderSchedule $schedule): int
    {
        return $this->resolve($occurrence, $schedule)->count();
    }

    /* ------------------------------------------------------------------ */

    /** @return Collection<int, Contact> */
    private function allParticipants(ExerciseOccurrence $occurrence, ReminderSchedule $schedule): Collection
    {
        $fromRows = $this->contactsFromParticipants($occurrence);

        if ($fromRows->isNotEmpty()) {
            return $fromRows;
        }

        // No participant rows yet — the occurrence was generated but nobody has
        // been invited. Fall back to the definition's audience rule, which is
        // how the list would be built. A ladder that resolved to nobody in the
        // meantime would send nothing for the first week of the countdown.
        $rule = AudienceRule::fromJson($schedule->audience_rule['rule'] ?? null);

        return $rule === null ? collect() : $this->audiences->resolve($rule);
    }

    /** @return Collection<int, Contact> */
    private function withOpenTasks(ExerciseOccurrence $occurrence): Collection
    {
        $ownerIds = ReadinessTask::query()
            ->where('occurrence_id', $occurrence->getKey())
            ->whereIn('status', ['open', 'in_progress', 'overdue'])
            ->whereNotNull('owner_id')
            ->pluck('owner_id')
            ->unique()
            ->all();

        return $this->contactsForUserIds($ownerIds);
    }

    /**
     * Participants who owe nothing.
     *
     * The low-noise half of the daily band. They are computed as "everyone,
     * minus the people who still owe something" rather than queried directly,
     * because a participant with no tasks at all is complete by any reasonable
     * reading and would be missed by a query over the task table.
     *
     * @return Collection<int, Contact>
     */
    private function allComplete(ExerciseOccurrence $occurrence): Collection
    {
        $behind = $this->withOpenTasks($occurrence)->keyBy(fn (Contact $c) => $c->getKey());

        return $this->contactsFromParticipants($occurrence)
            ->reject(fn (Contact $c) => $behind->has($c->getKey()))
            ->values();
    }

    /**
     * The escalation audience: line managers and the programme owner.
     *
     * NOT THE PERSON WHO IS LATE. They have had six daily reminders by T-2 —
     * a seventh is the definition of alert fatigue. Escalation means somebody
     * else now knows.
     *
     * @return Collection<int, Contact>
     */
    private function ownersAndManagers(ExerciseOccurrence $occurrence): Collection
    {
        $definition = $occurrence->definition;

        $userIds = array_filter([
            $definition?->owner_id,
            $definition?->facilitator_id,
            $occurrence->facilitator_id,
            $definition?->exerciseProgramme?->created_by,
        ]);

        // The line manager of everybody who is behind. Read from the contact
        // roster, which is the only place that knows.
        $behind = $this->withOpenTasks($occurrence);

        foreach ($behind as $contact) {
            if ($contact->manager_user_id !== null) {
                $userIds[] = (int) $contact->manager_user_id;
            }
        }

        return $this->contactsForUserIds(array_values(array_unique($userIds)));
    }

    /** @return Collection<int, Contact> */
    private function facilitatorsAndObservers(ExerciseOccurrence $occurrence): Collection
    {
        $rows = ExerciseParticipant::query()
            ->where('occurrence_id', $occurrence->getKey())
            ->whereIn('role', ['facilitator', 'observer', 'evaluator'])
            ->get();

        $contacts = $this->contactsFromRows($rows);

        if ($contacts->isNotEmpty()) {
            return $contacts;
        }

        return $this->contactsForUserIds(array_filter([
            $occurrence->facilitator_id,
            $occurrence->definition?->facilitator_id,
        ]));
    }

    /**
     * Whoever owns a corrective action arising from this exercise's AAR.
     *
     * @return Collection<int, Contact>
     */
    private function capaOwners(ExerciseOccurrence $occurrence): Collection
    {
        $aar = $occurrence->aar;

        if ($aar === null) {
            return collect();
        }

        $ownerIds = $aar->findings()
            ->join('bcms_corrective_actions', 'bcms_corrective_actions.finding_id', '=', 'bcms_findings.id')
            ->whereNotIn('bcms_corrective_actions.status', ['verified', 'accepted_risk'])
            ->pluck('bcms_corrective_actions.owner_id')
            ->filter()
            ->unique()
            ->all();

        return $this->contactsForUserIds($ownerIds);
    }

    /* ------------------------------------------------------------------ */

    /** @return Collection<int, Contact> */
    private function contactsFromParticipants(ExerciseOccurrence $occurrence): Collection
    {
        return $this->contactsFromRows(
            ExerciseParticipant::query()->where('occurrence_id', $occurrence->getKey())->get()
        );
    }

    /**
     * @param  Collection<int, ExerciseParticipant>  $rows
     * @return Collection<int, Contact>
     */
    private function contactsFromRows(Collection $rows): Collection
    {
        if ($rows->isEmpty()) {
            return collect();
        }

        $contactIds = $rows->pluck('contact_id')->filter()->unique()->all();
        $userIds = $rows->whereNull('contact_id')->pluck('user_id')->filter()->unique()->all();

        $byContact = $contactIds === []
            ? collect()
            : Contact::query()->whereIn('id', $contactIds)->where('is_active', true)->get();

        return $byContact
            ->concat($this->contactsForUserIds($userIds))
            ->unique(fn (Contact $c) => $c->getKey())
            ->values();
    }

    /**
     * @param  array<int, int|string>  $userIds
     * @return Collection<int, Contact>
     */
    private function contactsForUserIds(array $userIds): Collection
    {
        $userIds = array_values(array_filter(array_map('intval', $userIds)));

        if ($userIds === []) {
            return collect();
        }

        return $this->contacts->forUsers(User::query()->whereIn('id', $userIds)->get())->values();
    }
}
