<?php

namespace App\Services\Bcms\Exercises;

use App\Enums\Bcms\CorrectiveActionStatus;
use App\Enums\Bcms\OccurrenceStatus;
use App\Models\Bcms\CorrectiveAction;
use App\Models\Bcms\ExerciseOccurrence;
use Illuminate\Support\Collection;

/**
 * The closing loop — ISO 22398's ladder rule, and the criterion an examiner
 * looks for (compliance analyst §3.3).
 *
 * `carried_to_occurrence_id` IS THE EXERCISE ENGINE'S EXCLUSIVE COLUMN
 * (Orchestration §5, the finding/CAPA migration header). Nothing else in the
 * product writes it, and this is the only service that does.
 *
 * THE CARRY RUNS AT "GENERATED OR OPENED". This phase triggers it when an
 * occurrence's readiness screen or execution workspace is first opened,
 * which is the earliest point Phase 9 owns without reaching into Phase 4's
 * generation engine (a documented choice — see the phase notes). It is
 * idempotent either way: an action already carried to this occurrence is not
 * re-stamped, so calling it on every page load costs one query and writes
 * nothing on the second call.
 *
 * THE FALLBACK IS A REAL RULE, NOT A NICETY (§3.3 item 3). A definition that
 * runs once a year in December has no "next occurrence of this definition"
 * until next December — so an action from this December's fire drill would
 * never be carried at all under the naive rule. The fallback looks for the
 * next SCHEDULED occurrence of ANY definition covering the same processes.
 * If there genuinely is none, the action is left uncarried and
 * `unresolvedCarries()` is how a programme dashboard would say so — silently
 * dropping the carry is how the loop stops being evidence.
 */
class CarriedActionService
{
    /**
     * Carry every open corrective action from the previous occurrence(s) of
     * this definition onto `$occurrence`.
     *
     * @return int how many actions were newly carried
     */
    public function carryForward(ExerciseOccurrence $occurrence): int
    {
        $previous = ExerciseOccurrence::query()
            ->where('definition_id', $occurrence->definition_id)
            ->where('sequence_no', '<', $occurrence->sequence_no)
            ->pluck('id');

        if ($previous->isEmpty()) {
            return 0;
        }

        $carried = CorrectiveAction::query()
            ->whereHas('finding', fn ($q) => $q->whereIn('aar_id', function ($sub) use ($previous): void {
                $sub->select('id')->from('bcms_aars')->whereIn('occurrence_id', $previous);
            }))
            ->whereNull('carried_to_occurrence_id')
            ->whereIn('status', [
                CorrectiveActionStatus::Open->value,
                CorrectiveActionStatus::InProgress->value,
                CorrectiveActionStatus::Overdue->value,
            ])
            ->get();

        foreach ($carried as $action) {
            $action->forceFill([
                'carried_to_occurrence_id' => $occurrence->getKey(),
                'carried_at' => now(),
            ])->save();
        }

        return $carried->count();
    }

    /**
     * Where an open action from a definition with no upcoming occurrence of
     * its own should land — the fallback rule (§3.3 item 3).
     *
     * Not called automatically: a sweep or a dashboard invokes this for
     * actions that `carryForward()` could never reach, because there is
     * nothing here for it to reach FROM (`carryForward()` runs against a real
     * occurrence; this runs against an orphaned finding).
     */
    public function fallbackOccurrenceFor(CorrectiveAction $action): ?ExerciseOccurrence
    {
        $finding = $action->finding;
        $processId = $finding?->affected_process_id;

        if ($processId === null) {
            return null;
        }

        return ExerciseOccurrence::query()
            ->whereIn('status', [OccurrenceStatus::Planned->value, OccurrenceStatus::NeedsScheduling->value, OccurrenceStatus::Confirmed->value])
            ->whereHas('definition', fn ($q) => $q->whereJsonContains('process_ids', $processId))
            ->orderByRaw('scheduled_date IS NULL, scheduled_date ASC')
            ->first();
    }

    /**
     * Open actions with no next occurrence of their own definition AND no
     * fallback available — "no scheduled exercise will validate this
     * action". The programme dashboard's fact, computed here rather than
     * stored, per the same reasoning `LadderAdvisor` gives for every warning
     * in this module: a stored flag would need a job to keep it honest as
     * the calendar changes underneath it.
     *
     * @return Collection<int, CorrectiveAction>
     */
    public function unresolvedCarries(int $organizationId): Collection
    {
        return CorrectiveAction::query()
            ->where('organization_id', $organizationId)
            ->whereNull('carried_to_occurrence_id')
            ->whereIn('status', [
                CorrectiveActionStatus::Open->value,
                CorrectiveActionStatus::InProgress->value,
                CorrectiveActionStatus::Overdue->value,
            ])
            ->whereHas('finding', fn ($q) => $q->where('source', 'aar'))
            ->get()
            ->filter(fn (CorrectiveAction $a) => $this->fallbackOccurrenceFor($a) === null && $this->definitionHasNoUpcoming($a));
    }

    /**
     * Every carried action against this occurrence — the read side of the
     * carry, consumed by `GET occurrences/{occurrence}/carried-actions` and
     * by the AAR builder's §9 table.
     *
     * DISPOSITION IS NOT ON THIS ROW. It lives in the CURRENT occurrence's
     * own AAR, `quantitative_results.carried_actions[]` (compliance analyst
     * §2.2 schema) — keyed by `reference` — because a carried action's
     * disposition is a fact about how THIS exercise validated it, not a
     * property of the action itself (the same action can be carried again to
     * a THIRD occurrence with a different disposition each time).
     * `AarService` merges the two; this method returns the action side only.
     *
     * @return list<array<string, mixed>>
     */
    public function present(ExerciseOccurrence $occurrence): array
    {
        return $occurrence->carriedActions()
            ->with(['finding:id,reference,description,aar_id'])
            ->get()
            ->map(fn (CorrectiveAction $a) => [
                'id' => $a->getKey(),
                'uuid' => $a->uuid,
                'reference' => $a->reference,
                'title' => $a->title,
                'from_occurrence_uuid' => $a->finding?->aar?->occurrence?->uuid,
                'status' => $a->status?->value,
            ])->values()->all();
    }

    private function definitionHasNoUpcoming(CorrectiveAction $action): bool
    {
        $occurrenceId = $action->finding?->aar?->occurrence_id;

        if ($occurrenceId === null) {
            return true;
        }

        $origin = ExerciseOccurrence::query()->find($occurrenceId);

        if ($origin === null) {
            return true;
        }

        return ExerciseOccurrence::query()
            ->where('definition_id', $origin->definition_id)
            ->where('sequence_no', '>', $origin->sequence_no)
            ->whereNotIn('status', [OccurrenceStatus::Cancelled->value])
            ->doesntExist();
    }
}
