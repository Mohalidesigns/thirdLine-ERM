<?php

namespace App\Policies\Rcsa;

use App\Models\Rcsa\RcsaActionPlan;
use App\Models\User;
use App\Support\Rcsa\RcsaScope;

/**
 * RCSA v2, P5. The remediation register of §9.3.
 *
 * THE OWNER IS AN AUTHORITY HERE, not just a name in a column. `rcsa_actionplan
 * .update` plus being the owner is what lets somebody record progress, mark
 * their plan complete or ask for more time — a bank does not hand every risk
 * owner the right to update every other unit's remediation, and a register that
 * anyone can edit is not evidence of anything. The ORM's own permissions
 * (`close`, `verify`) are unconditional by contrast, because chasing other
 * people's plans is exactly the second line's job.
 *
 * VERIFY IS NOT CLOSE. The owner claims the control is in place; the second line
 * accepts that it is. `verify` explicitly excludes the person who made the
 * claim — self-verified remediation is the failure mode §9.3 is written to
 * prevent, and permissions alone would not stop it because the Head of ORM
 * legitimately holds both.
 *
 * BUSINESS-UNIT SCOPING IS STILL P7, as on every other RCSA policy.
 */
class RcsaActionPlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('rcsa_actionplan.view');
    }

    public function view(User $user, RcsaActionPlan $plan): bool
    {
        return $user->can('rcsa_actionplan.view') && $this->reachable($user, $plan);
    }

    /**
     * Record progress, complete, or ask for an extension.
     */
    public function update(User $user, RcsaActionPlan $plan): bool
    {
        if (! $this->reachable($user, $plan)) {
            return false;
        }

        // The owner acting on their own plan, or the second line acting on
        // anybody's.
        return ($user->can('rcsa_actionplan.update') && (int) $plan->owner_id === (int) $user->id)
            || $user->can('rcsa_actionplan.close');
    }

    /**
     * Approve or refuse an extension request.
     *
     * NOT THE REQUESTER. Moving your own deadline because you asked to is not
     * an approval, and it is the single easiest way for a remediation register
     * to report nothing overdue.
     */
    public function decideExtension(User $user, RcsaActionPlan $plan): bool
    {
        return $user->can('rcsa_actionplan.close')
            && $this->reachable($user, $plan)
            && (int) $plan->extension_requested_by !== (int) $user->id;
    }

    /**
     * The second line accepting that the control is really in place.
     */
    public function verify(User $user, RcsaActionPlan $plan): bool
    {
        return $user->can('rcsa_actionplan.verify')
            && $this->reachable($user, $plan)
            && (int) $plan->closed_by !== (int) $user->id;
    }

    /**
     * Same organisation, and a business unit this user is assigned to (§11).
     *
     * The plan reaches its unit through its LINE, which carries
     * `business_unit_id` as a snapshot. Reading it through the line rather than
     * through the assessment matters on a register that outlives the cycle: the
     * assessment can be closed and gone from every screen while the plan is
     * still being chased.
     */
    private function reachable(User $user, RcsaActionPlan $plan): bool
    {
        if ($user->organization_id !== $plan->organization_id) {
            return false;
        }

        $line = $plan->relationLoaded('line') ? $plan->line : $plan->line()->first();

        return app(RcsaScope::class)->reaches($user, $line?->business_unit_id);
    }
}
