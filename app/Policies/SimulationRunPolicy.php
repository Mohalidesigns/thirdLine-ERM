<?php

namespace App\Policies;

use App\Models\SimulationRun;
use App\Models\User;

/**
 * Who may run and stop a Monte Carlo simulation (migration Phase 5.2).
 *
 * RUNNING IS ITS OWN PERMISSION. `quantification.run_simulation` is separate
 * from `quantification.create` in the seeded set and always has been: a run
 * over a real scenario set is tens of thousands of iterations per scenario on
 * a queue worker, and the number it produces is presented to a board and filed
 * with the CBN. Somebody trusted to calibrate a scenario is not automatically
 * trusted to publish a capital figure from it.
 *
 * CANCELLING ASKS FOR THE SAME ABILITY AS RUNNING, not for an edit permission.
 * A cancel is a request the worker honours at its next checkpoint, and a
 * cancelled run has NO results — a partial loss distribution is not a smaller
 * answer, it is a wrong one. Whoever may start the work may stop it.
 *
 * Reach is the tenant; `simulation_runs` carries no narrower scope.
 *
 * Gate::before grants super-admin every ability before any of this runs.
 */
class SimulationRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('quantification.view');
    }

    public function view(User $user, SimulationRun $simulation): bool
    {
        return $user->can('quantification.view') && $this->sameTenant($user, $simulation);
    }

    public function create(User $user): bool
    {
        return $user->can('quantification.run_simulation');
    }

    public function cancel(User $user, SimulationRun $simulation): bool
    {
        return $user->can('quantification.run_simulation') && $this->sameTenant($user, $simulation);
    }

    private function sameTenant(User $user, SimulationRun $simulation): bool
    {
        return (int) $simulation->organization_id === (int) $user->organization_id;
    }
}
