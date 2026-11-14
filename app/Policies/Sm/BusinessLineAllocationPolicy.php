<?php

namespace App\Policies\Sm;

use App\Models\Sm\BusinessLineAllocation;
use App\Models\User;

/**
 * Named for its model: `App\Models\Sm\BusinessLineAllocation` → this class
 * (standard §3).
 *
 * An allocation line has no permission of its own; it is a line on its parent
 * scenario's qualitative report, so every ability asks `scenario.update` and then
 * checks the line belongs to a scenario in the caller's tenant.
 *
 * THE SUM-TO-100 INVARIANT IS NOT HERE. It is not an authorization question — it is
 * a domain rule about the DATA, and §A1.5 puts it in `AllocationService` so it
 * answers a 422 on key `allocations` rather than a 403. A policy that returned false
 * for "these percentages add to 75" would tell a designer they are not allowed to
 * save their own draft, which is both wrong and the reason §A1.5 enforces the
 * invariant at the `in_design → designed` TRANSITION rather than on every save.
 */
class BusinessLineAllocationPolicy
{
    public function view(User $user, BusinessLineAllocation $allocation): bool
    {
        return $user->can('scenario.view') && $this->reachable($user, $allocation);
    }

    public function update(User $user, BusinessLineAllocation $allocation): bool
    {
        return $user->can('scenario.update') && $this->reachable($user, $allocation);
    }

    /** Removing a line is an edit of the allocation, not a destructive act of its own. */
    public function delete(User $user, BusinessLineAllocation $allocation): bool
    {
        return $this->update($user, $allocation);
    }

    private function reachable(User $user, BusinessLineAllocation $allocation): bool
    {
        if ((int) $allocation->organization_id !== (int) $user->organization_id) {
            return false;
        }

        $scenario = $allocation->scenario;

        return $scenario !== null
            && (int) $scenario->organization_id === (int) $user->organization_id;
    }
}
