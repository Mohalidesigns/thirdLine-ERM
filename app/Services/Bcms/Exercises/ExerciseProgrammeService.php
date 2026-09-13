<?php

namespace App\Services\Bcms\Exercises;

use App\Enums\Bcms\IsoClauseRef;
use App\Enums\Bcms\OccurrenceStatus;
use App\Models\Bcms\ExerciseDefinition;
use App\Models\Bcms\ExerciseOccurrence;
use App\Models\Bcms\ExerciseProgramme;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The annual exercise programme — ISO 22301 clause 8.5's mandatory record.
 *
 * "THE EXERCISE PROGRAMME AS APPROVED AND AS DELIVERED" is what the clause
 * asks for, and it is why `total_planned` is STORED rather than counted. A
 * count recomputed on read tells you what the programme looks like now; the
 * clause asks what it looked like when the board approved it, so that the
 * difference between the two is visible. `total_completed` is stored for the
 * same reason and refreshed as occurrences finish.
 *
 * APPROVAL FREEZES THE PLAN, NOT THE DATES. Approving records `total_planned`
 * as the commitment; individual occurrences still move, and every move is
 * counted against the plan rather than quietly changing it.
 *
 * NOT THE SAME OBJECT AS THE BCMS PROGRAMME. `bcms_programmes` is Phase 1's
 * governance record — scope, objectives, policy, management review.
 * `bcms_exercise_programmes` is one year of testing. They are linked and they
 * are different, and the phase prompt is explicit that their screens must not
 * share a route or a component.
 */
class ExerciseProgrammeService
{
    /** @param array<string, mixed> $attributes */
    public function create(int $year, string $name, array $attributes = [], ?int $userId = null): ExerciseProgramme
    {
        return ExerciseProgramme::query()->create(array_merge([
            'year' => $year,
            'name' => $name,
            'status' => 'draft',
            'iso_clause_ref' => IsoClauseRef::Iso22301_8_5_programme->value,
            'created_by' => $userId ?? auth()->id(),
        ], $attributes));
    }

    /**
     * Approve the year's programme.
     *
     * THE APPROVER IS NOT THE AUTHOR, the same rule the policy and the plans
     * follow. A testing programme approved by the person who wrote it has had
     * no oversight, and clause 8.5 is one of the places an auditor looks first.
     */
    public function approve(ExerciseProgramme $programme, User $approver): ExerciseProgramme
    {
        if ($programme->status === 'approved' || $programme->status === 'active') {
            throw new InvalidArgumentException('This programme has already been approved.');
        }

        if ((int) $approver->getKey() === (int) $programme->created_by) {
            throw new InvalidArgumentException(
                'An exercise programme must be approved by somebody other than its author.'
            );
        }

        if ($programme->definitions()->where('status', 'active')->count() === 0) {
            throw new InvalidArgumentException(
                'A programme with no active exercise definitions is not a testing programme. Add the exercises '
                .'before asking for approval.'
            );
        }

        return DB::transaction(function () use ($programme, $approver) {
            $programme->update([
                'status' => 'approved',
                'approved_by' => $approver->getKey(),
                'approved_at' => now(),
                'updated_by' => $approver->getKey(),
            ]);

            // Recorded at the moment of approval: this is the commitment the
            // year is measured against, and it must not move when somebody
            // edits a definition in March.
            $this->refreshCounts($programme, freezePlanned: true);

            return $programme->refresh();
        });
    }

    /**
     * Recompute the delivered count, and optionally freeze the planned one.
     *
     * @return array{planned: int, completed: int}
     */
    public function refreshCounts(ExerciseProgramme $programme, bool $freezePlanned = false): array
    {
        $definitionIds = $programme->definitions()->pluck('id');

        $planned = (int) ExerciseOccurrence::query()
            ->whereIn('definition_id', $definitionIds)
            ->count();

        $completed = (int) ExerciseOccurrence::query()
            ->whereIn('definition_id', $definitionIds)
            ->where('status', OccurrenceStatus::Completed->value)
            ->count();

        $programme->forceFill(array_filter([
            'total_planned' => $freezePlanned || $programme->status === 'draft' ? $planned : null,
            'total_completed' => $completed,
        ], fn ($v) => $v !== null))->save();

        return ['planned' => (int) $programme->refresh()->total_planned, 'completed' => $completed];
    }

    /**
     * The dashboard figures.
     *
     * @return array<string, mixed>
     */
    public function summary(ExerciseProgramme $programme): array
    {
        $definitionIds = $programme->definitions()->pluck('id');

        $byStatus = ExerciseOccurrence::query()
            ->whereIn('definition_id', $definitionIds)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $planned = (int) $programme->total_planned;
        $completed = (int) ($byStatus[OccurrenceStatus::Completed->value] ?? 0);

        $overdue = (int) ExerciseOccurrence::query()
            ->whereIn('definition_id', $definitionIds)
            ->whereIn('status', [OccurrenceStatus::Planned->value, OccurrenceStatus::Confirmed->value])
            ->whereNotNull('scheduled_date')
            ->whereDate('scheduled_date', '<', now()->toDateString())
            ->count();

        return [
            'planned' => $planned,
            'completed' => $completed,
            'in_progress' => (int) ($byStatus[OccurrenceStatus::InProgress->value] ?? 0),
            'needs_scheduling' => (int) ($byStatus[OccurrenceStatus::NeedsScheduling->value] ?? 0),
            'cancelled' => (int) ($byStatus[OccurrenceStatus::Cancelled->value] ?? 0),
            'missed' => (int) ($byStatus[OccurrenceStatus::Missed->value] ?? 0),
            'overdue' => $overdue,
            // Null, never 100%, when nothing was planned. A programme with no
            // exercises has not achieved perfect delivery.
            'completion_rate' => $planned === 0 ? null : round(($completed / $planned) * 100, 1),
            'definition_count' => $definitionIds->count(),
            'total_reschedules' => (int) ExerciseOccurrence::query()
                ->whereIn('definition_id', $definitionIds)
                ->sum('reschedule_count'),
        ];
    }

    /**
     * Generate every active definition in the programme.
     *
     * @return array<string, mixed>
     */
    public function generateAll(ExerciseProgramme $programme, OccurrenceGenerator $generator, ?int $userId = null): array
    {
        $logs = [];

        foreach ($programme->definitions()->where('status', 'active')->get() as $definition) {
            $logs[$definition->name] = $generator->generate($definition, $userId);
        }

        $this->refreshCounts($programme);

        return [
            'definitions' => count($logs),
            'placed' => array_sum(array_column($logs, 'placed')),
            'needs_scheduling' => array_sum(array_column($logs, 'needs_scheduling')),
            'shifted' => array_sum(array_column($logs, 'shifted')),
            'logs' => $logs,
        ];
    }

    /** @return \Illuminate\Database\Eloquent\Builder<ExerciseDefinition> */
    public function definitionsFor(ExerciseProgramme $programme): \Illuminate\Database\Eloquent\Builder
    {
        return ExerciseDefinition::query()->where('exercise_programme_id', $programme->getKey());
    }
}
