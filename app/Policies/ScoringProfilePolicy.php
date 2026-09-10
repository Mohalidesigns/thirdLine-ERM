<?php

namespace App\Policies;

use App\Models\ScoringProfile;
use App\Models\User;

/**
 * Who may decide what a score means (migration Phase 6.4).
 *
 * Behind `admin.scoring` rather than `admin.metadata`, which is why the route
 * sits in its own middleware group: redefining what Critical means re-rates the
 * whole register, so it is grantable separately from the rest of the builder.
 *
 * THE SEEDED PROFILE IS NEVER EDITED IN PLACE. The 5×5 is the parity baseline
 * every tenant without a profile of their own resolves against, so editing it
 * would move scores for everybody; saving over one forks it into this tenant's
 * profile instead. That behaviour is the controller's, kept from
 * ScoringProfileBuilder — `update` on a system row is allowed here because the
 * fork is the outcome the user wants and gets. Deleting one is not.
 */
class ScoringProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('admin.scoring');
    }

    public function view(User $user, ScoringProfile $profile): bool
    {
        return $user->can('admin.scoring') && $this->reachable($user, $profile);
    }

    public function create(User $user): bool
    {
        return $user->can('admin.scoring');
    }

    public function update(User $user, ScoringProfile $profile): bool
    {
        return $this->view($user, $profile);
    }

    /**
     * Note what is NOT here: "the seeded profile is undeletable". Gate::before
     * answers every ability true for a super-admin, so a policy cannot express
     * "nobody, ever" — the controller enforces that as a data invariant, the
     * same shape as MetadataGuard's assertions.
     */
    public function delete(User $user, ScoringProfile $profile): bool
    {
        return $this->update($user, $profile);
    }

    private function reachable(User $user, ScoringProfile $profile): bool
    {
        return $profile->organization_id === null
            || (int) $profile->organization_id === (int) $user->organization_id;
    }
}
