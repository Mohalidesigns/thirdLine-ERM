<?php

namespace App\Services\Bcms\Plans;

use App\Models\Bcms\Plan;
use App\Models\Bcms\PlanActivation;
use App\Models\User;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * The record of a plan having been used.
 *
 * PHASE 10 WRITES HERE; PHASE 3 BUILDS THE SERVICE AND THE READ VIEWS. An
 * incident activates a plan, and the incident engine does not exist yet — but
 * the question "has this plan ever actually been used, and did it work" is
 * asked of the plan, on the plan's screen, and the answer has to be somewhere
 * before there is anything to answer it with.
 *
 * AN EXERCISE ACTIVATION IS NOT AN ACTIVATION IN ANGER, and the column that
 * separates them was in the Phase 0 schema for exactly this reason. A
 * management review that cannot tell them apart reports a plan as battle-tested
 * when it was rehearsed, which is the most flattering error this module could
 * make.
 *
 * ONLY AN APPROVED PLAN IS ACTIVATED. Activating a draft during an incident
 * means somebody is following an unapproved document while the building is on
 * fire; the system should refuse, and the refusal should be loud enough that
 * they go and find the approved one.
 */
class PlanActivationService
{
    public function activate(
        Plan $plan,
        User $by,
        string $reason,
        bool $isExercise = false,
        ?int $incidentId = null,
        ?int $occurrenceId = null,
    ): PlanActivation {
        if ($plan->status !== 'approved') {
            throw new InvalidArgumentException(
                'Only an approved plan can be activated. Activating a draft means following an unapproved '
                .'document during a disruption.'
            );
        }

        $open = $this->openActivation($plan);

        if ($open !== null) {
            throw new InvalidArgumentException(
                'This plan is already active — it was activated on '
                .$open->activated_at?->format('d M Y H:i').' and has not been stood down. Stand it down before '
                .'activating it again, or the two events cannot be told apart afterwards.'
            );
        }

        return PlanActivation::query()->create([
            'organization_id' => $plan->organization_id,
            'plan_id' => $plan->getKey(),
            'incident_id' => $incidentId,
            'occurrence_id' => $occurrenceId,
            'is_exercise' => $isExercise,
            'activated_by' => $by->getKey(),
            'activated_at' => now(),
            'activation_reason' => $reason,
        ]);
    }

    public function deactivate(PlanActivation $activation, ?Carbon $at = null): PlanActivation
    {
        if ($activation->deactivated_at !== null) {
            throw new InvalidArgumentException('This activation has already been stood down.');
        }

        $activation->update(['deactivated_at' => $at ?? now()]);

        return $activation->refresh();
    }

    public function openActivation(Plan $plan): ?PlanActivation
    {
        return PlanActivation::query()
            ->where('plan_id', $plan->getKey())
            ->whereNull('deactivated_at')
            ->orderByDesc('activated_at')
            ->first();
    }

    /**
     * A plan's activation history, for its screen.
     *
     * @return array{
     *   rows: list<array<string, mixed>>, live_count: int, exercise_count: int,
     *   last_live_at: ?string, is_active: bool
     * }
     */
    public function history(Plan $plan): array
    {
        $activations = PlanActivation::query()
            ->where('plan_id', $plan->getKey())
            ->with('activatedBy:id,name')
            ->orderByDesc('activated_at')
            ->get();

        $rows = $activations->map(fn (PlanActivation $a) => [
            'id' => $a->getKey(),
            'activated_at' => $a->activated_at?->toIso8601String(),
            'deactivated_at' => $a->deactivated_at?->toIso8601String(),
            'duration_hours' => ($a->activated_at !== null && $a->deactivated_at !== null)
                ? round($a->activated_at->diffInMinutes($a->deactivated_at) / 60, 2)
                : null,
            'is_exercise' => (bool) $a->is_exercise,
            'incident_id' => $a->incident_id,
            'occurrence_id' => $a->occurrence_id,
            'activated_by' => $a->activatedBy?->name,
            'reason' => $a->activation_reason,
        ])->all();

        $live = array_values(array_filter($rows, fn (array $r) => ! $r['is_exercise']));

        return [
            'rows' => $rows,
            'live_count' => count($live),
            'exercise_count' => count($rows) - count($live),
            'last_live_at' => $live === [] ? null : $live[0]['activated_at'],
            'is_active' => $activations->contains(fn (PlanActivation $a) => $a->deactivated_at === null),
        ];
    }
}
