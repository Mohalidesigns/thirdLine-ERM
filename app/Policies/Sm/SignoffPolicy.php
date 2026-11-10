<?php

namespace App\Policies\Sm;

use App\Models\Sm\Signoff;
use App\Models\User;

/**
 * Named for its model: `App\Models\Sm\Signoff` → this class (standard §3).
 *
 * THERE IS NO `update` AND NO `delete` ABILITY HERE, AND THAT IS THE POINT.
 * §A1.6: "No soft deletes, no `updated_by`: a sign-off is appended to, never
 * edited." A policy method that does not exist resolves to a denial, so the
 * append-only rule is enforced by the absence of an ability rather than by a
 * convention somebody has to remember — and Phase 1 registers no route that could
 * call one anyway. A sign-off an author could quietly amend is not a governance
 * record; it is a draft.
 *
 * The correction path is a NEW row: §A1.6 puts no unique key on
 * (sm_scenario_id, source) precisely so source 1 can sign, be returned, and sign
 * again, with the earlier row surviving on the trail.
 */
class SignoffPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('scenario.view');
    }

    public function view(User $user, Signoff $signoff): bool
    {
        return $user->can('scenario.view') && $this->reachable($user, $signoff);
    }

    /**
     * Recording a sign-off is `scenario.sign_off`, NOT `scenario.update` and NOT
     * `scenario.validate`. §A1.10 keeps the three apart because they are three
     * authorities: editing is first-line work, moving the lifecycle is the validator's,
     * and signing is attesting to somebody else's design as one of three sources.
     *
     * Takes no model: a store ability is asked before the row exists, and the tenancy
     * check that matters is on the PARENT scenario, which the route binds through
     * `ResolveTenant` + `BelongsToOrganization` before this runs.
     */
    public function create(User $user): bool
    {
        return $user->can('scenario.sign_off');
    }

    private function reachable(User $user, Signoff $signoff): bool
    {
        if ((int) $signoff->organization_id !== (int) $user->organization_id) {
            return false;
        }

        $scenario = $signoff->scenario;

        return $scenario !== null
            && (int) $scenario->organization_id === (int) $user->organization_id;
    }
}
