<?php

namespace App\Policies;

use App\Models\Risk;
use App\Models\TreatmentPlan;
use App\Models\User;
use App\Support\Authorization\GraphScope;

/**
 * Who may do what to a treatment plan (migration Phase 3.5).
 *
 * Shape follows RiskAssessmentPolicy (3.3): the permission string first, then
 * reach, then the lifecycle rule. Reach is two things — the caller's
 * organisation, and the caller's node scope, which a plan INHERITS FROM THE
 * RISK IT TREATS rather than holding itself. That is why every reach check
 * goes through `risk`, matching the EnforcesNodeScope call
 * (`abortUnlessNodeVisibleThrough($treatment, 'risk')`) the controller made.
 *
 * Absorbs the last two inline Gate closures in AppServiceProvider,
 * `approve-treatment-plan` and `resubmit-treatment-plan`, deleted in the same
 * commit. After this only `view-grid` and `approve-loss-event` remain there.
 *
 * BOTH FOLDED ABILITIES KEEP THEIR OLD NAMES, following 3.4 rather than 3.3:
 * TreatmentPlanBinding::gate() returns 'approve-treatment-plan', and
 * WorkflowEngine::canAct() asks `$user->can('approve-treatment-plan', $plan)`
 * through it. Laravel resolves a hyphenated ability on a model to the
 * camel-cased policy method, so approveTreatmentPlan() below catches that call
 * and delegates to approve() — the binding never changes, and a decision taken
 * from My Tasks passes exactly the check one taken on the plan's own page does.
 *
 * Named for the MODEL, not the module: Laravel discovers App\Models\TreatmentPlan
 * only as App\Policies\TreatmentPlanPolicy, and a policy the container never
 * finds authorises nothing.
 *
 * Gate::before grants super-admin every ability before any of this runs.
 */
class TreatmentPlanPolicy
{
    /**
     * Roles that may approve on top of nobody in particular: the plan table
     * has no assigned-reviewer column, so the `approve-treatment-plan` closure
     * this replaces was role-based and stays that way.
     *
     * @var list<string>
     */
    public const APPROVER_ROLES = ['chief-risk-officer', 'risk-manager'];

    public function viewAny(User $user): bool
    {
        return $user->can('treatment.view');
    }

    public function view(User $user, TreatmentPlan $plan): bool
    {
        return $user->can('treatment.view') && $this->withinReach($user, $plan);
    }

    public function create(User $user): bool
    {
        return $user->can('treatment.create');
    }

    public function update(User $user, TreatmentPlan $plan): bool
    {
        return $user->can('treatment.edit') && $this->withinReach($user, $plan);
    }

    public function delete(User $user, TreatmentPlan $plan): bool
    {
        return $user->can('treatment.delete') && $this->withinReach($user, $plan);
    }

    /** Adding a review comment is a viewer's action — the route asks treatment.view. */
    public function comment(User $user, TreatmentPlan $plan): bool
    {
        return $user->can('treatment.view') && $this->withinReach($user, $plan);
    }

    /**
     * The `approve-treatment-plan` closure — organisation, then an
     * approver-class role — plus the node scope every other ability here
     * applies.
     *
     * NO `treatment.approve` PERMISSION CHECK INSIDE THE ABILITY, deliberately
     * and following 3.4: the workflow engine's own actor may hold `approval.act`
     * rather than the module permission. The route middleware still requires
     * `treatment.approve` on the page's approve and reject actions.
     *
     * WHAT IS DELIBERATELY NOT HERE: the "only a pending_review plan can be
     * approved" rule. The screens answer a wrong status with a flash message
     * rather than a 403, and canAct() asks this same ability about undecided
     * tasks — folding the lifecycle in would turn a message into a permission
     * denial. That guard stays in the controller.
     */
    public function approve(User $user, TreatmentPlan $plan): bool
    {
        return $this->withinReach($user, $plan)
            && $user->hasAnyRole(self::APPROVER_ROLES);
    }

    /** Rejecting is the same decision as approving, taken the other way. */
    public function reject(User $user, TreatmentPlan $plan): bool
    {
        return $this->approve($user, $plan);
    }

    /** The `resubmit-treatment-plan` closure: the plan's owner or its creator. */
    public function resubmit(User $user, TreatmentPlan $plan): bool
    {
        return $this->withinReach($user, $plan)
            && in_array((int) $user->id, array_map('intval', array_filter([
                $plan->owner_id,
                $plan->created_by,
            ])), true);
    }

    /**
     * Submitting a draft for review is the owner's or creator's action, the
     * same set the resubmit closure named — carried across unchanged from
     * submitForReview(), which checked tenancy only.
     */
    public function submit(User $user, TreatmentPlan $plan): bool
    {
        return $this->resubmit($user, $plan);
    }

    /** `$user->can('approve-treatment-plan', $plan)` — the workflow engine's spelling. */
    public function approveTreatmentPlan(User $user, TreatmentPlan $plan): bool
    {
        return $this->approve($user, $plan);
    }

    /** `$user->can('resubmit-treatment-plan', $plan)`. */
    public function resubmitTreatmentPlan(User $user, TreatmentPlan $plan): bool
    {
        return $this->resubmit($user, $plan);
    }

    /**
     * Same organisation, and inside the caller's subtree when they have one —
     * resolved through the risk, with the same visibleTo() query the grids
     * use, so a plan hidden from the register grid is not reachable by policy
     * either.
     */
    private function withinReach(User $user, TreatmentPlan $plan): bool
    {
        if ((int) $plan->organization_id !== (int) $user->organization_id) {
            return false;
        }

        if (! GraphScope::isSubtreeLimited($user)) {
            return true;
        }

        if ($plan->risk_id === null) {
            return false;
        }

        return Risk::query()
            ->whereKey($plan->risk_id)
            ->visibleTo($user)
            ->exists();
    }
}
