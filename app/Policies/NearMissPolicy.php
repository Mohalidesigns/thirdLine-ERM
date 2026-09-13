<?php

namespace App\Policies;

use App\Models\NearMiss;
use App\Models\User;

/**
 * Who may record and convert a near miss (migration Phase 4.3).
 *
 * A near miss rides on the loss-event permissions rather than having its own
 * set: it is the same reporting act with no money attached, and the seeder has
 * never issued a `near_miss.*` permission. Reporting one asks
 * `loss_event.create`, which is what the route carries.
 *
 * NearMiss does NOT use ScopedToGraph — it has no node column — so reach is the
 * tenant only. Converting one creates a loss event, which does carry a node;
 * that record is then scoped like any other.
 *
 * Gate::before grants super-admin every ability before any of this runs.
 */
class NearMissPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('loss_event.view');
    }

    public function view(User $user, NearMiss $nearMiss): bool
    {
        return $user->can('loss_event.view') && $this->sameTenant($user, $nearMiss);
    }

    public function create(User $user): bool
    {
        return $user->can('loss_event.create');
    }

    /**
     * Promote a near miss to a reported loss event. `loss_event.create`, not
     * `loss_event.edit`: the outcome is a new loss event.
     */
    public function convert(User $user, NearMiss $nearMiss): bool
    {
        return $user->can('loss_event.create') && $this->sameTenant($user, $nearMiss);
    }

    private function sameTenant(User $user, NearMiss $nearMiss): bool
    {
        return (int) $nearMiss->organization_id === (int) $user->organization_id;
    }
}
