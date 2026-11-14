<?php

namespace App\Policies\Sm;

use App\Models\Sm\ScenarioCause;
use App\Models\User;

/**
 * Named for its model: `App\Models\Sm\ScenarioCause` → this class (standard §3).
 *
 * A cause row has no permission of its own. It is a line on its parent scenario's
 * qualitative report, so every ability asks the PARENT'S permission —
 * `scenario.update` — and then checks the line really belongs to a scenario in the
 * caller's tenant. Checking tenancy on the child alone would not be enough: a
 * cause row carries `organization_id`, but the authority being exercised is over the
 * scenario it hangs off.
 */
class ScenarioCausePolicy
{
    public function view(User $user, ScenarioCause $cause): bool
    {
        return $user->can('scenario.view') && $this->reachable($user, $cause);
    }

    public function update(User $user, ScenarioCause $cause): bool
    {
        return $user->can('scenario.update') && $this->reachable($user, $cause);
    }

    /**
     * Unticking a cause DELETES its row (§A1.4: rows are sparse, so absence means
     * "not selected"), which is an edit of the qualitative report rather than a
     * destructive act of its own — hence `scenario.update` and not a delete
     * permission. `AuditTrailService` records the deletion.
     */
    public function delete(User $user, ScenarioCause $cause): bool
    {
        return $this->update($user, $cause);
    }

    /**
     * Both halves: the line's own tenant AND its parent's. A row whose
     * `organization_id` disagreed with its parent's would be a data defect, and
     * refusing it here is cheaper than explaining it later.
     */
    private function reachable(User $user, ScenarioCause $cause): bool
    {
        if ((int) $cause->organization_id !== (int) $user->organization_id) {
            return false;
        }

        $scenario = $cause->scenario;

        return $scenario !== null
            && (int) $scenario->organization_id === (int) $user->organization_id;
    }
}
