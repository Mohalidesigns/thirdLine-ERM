<?php

namespace App\Policies;

use App\Models\MeasureThreshold;
use App\Models\User;

/**
 * Who may read and re-baseline a formula threshold (migration Phase 4.2).
 *
 * Named for `MeasureThreshold` so Laravel discovers it, and the three abilities
 * are the three permissions the routes carry: threshold.view, threshold.manage
 * and threshold.rebaseline_approve.
 *
 * `rebaselineApprove` IS A CLASS-LEVEL ABILITY, on purpose. The thing being
 * approved is stored as an ApprovalRequest row, and ApprovalRequestPolicy
 * already owns `approve` / `reject` for the approvals module — asking
 * `can('approve', $approvalRequest)` here would land there and ask for
 * `approval.act` instead of `threshold.rebaseline_approve`. The question this
 * screen asks is "may this user re-baseline thresholds at all", which needs no
 * instance; that the specific request belongs to the caller's tenant and is
 * really a re-baselining request stays a guard in the controller, where a
 * mismatch is a 404 rather than a permission answer.
 *
 * Gate::before grants super-admin every ability before any of this runs.
 */
class MeasureThresholdPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('threshold.view');
    }

    public function view(User $user, MeasureThreshold $threshold): bool
    {
        return $user->can('threshold.view')
            && (int) $threshold->organization_id === (int) $user->organization_id;
    }

    /** Edit a band set by hand. */
    public function manage(User $user): bool
    {
        return $user->can('threshold.manage');
    }

    /** Decide a queued re-baselining. */
    public function rebaselineApprove(User $user): bool
    {
        return $user->can('threshold.rebaseline_approve');
    }
}
