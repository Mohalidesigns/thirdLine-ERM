<?php

namespace App\Policies\Rcsa;

use App\Models\Rcsa\RcsaCycle;
use App\Models\User;

/**
 * RCSA v2, P3. Discovered from App\Models\Rcsa\RcsaCycle.
 *
 * OPENING IS ITS OWN PERMISSION, not a stronger `manage`. It copies the entire
 * published universe into an assessment for every business unit and cannot be
 * undone — scheduling a cycle and pulling that trigger are different acts, and
 * separating them is what lets a bank let a coordinator draft the quarter's
 * cycle while the Head of ORM decides when it starts.
 */
class RcsaCyclePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('rcsa_cycle.view');
    }

    public function view(User $user, RcsaCycle $cycle): bool
    {
        return $user->can('rcsa_cycle.view') && $this->reachable($user, $cycle);
    }

    public function create(User $user): bool
    {
        return $user->can('rcsa_cycle.manage');
    }

    public function update(User $user, RcsaCycle $cycle): bool
    {
        return $user->can('rcsa_cycle.manage') && $this->reachable($user, $cycle);
    }

    public function open(User $user, RcsaCycle $cycle): bool
    {
        return $user->can('rcsa_cycle.open') && $this->reachable($user, $cycle);
    }

    public function close(User $user, RcsaCycle $cycle): bool
    {
        return $user->can('rcsa_cycle.close') && $this->reachable($user, $cycle);
    }

    /**
     * A cycle is deleted only while it is a draft, and the CONTROLLER enforces
     * that — an open cycle has assessments and lines behind it, and deleting
     * it would take a quarter's work with it.
     */
    public function delete(User $user, RcsaCycle $cycle): bool
    {
        return $user->can('rcsa_cycle.manage') && $this->reachable($user, $cycle);
    }

    private function reachable(User $user, RcsaCycle $cycle): bool
    {
        return $user->organization_id === $cycle->organization_id;
    }
}
