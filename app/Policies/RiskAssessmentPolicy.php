<?php

namespace App\Policies;

use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Models\User;
use App\Support\Authorization\GraphScope;

/**
 * Who may do what to a risk assessment (migration Phase 3.3).
 *
 * Shape follows ThirdLine's InvestigationCasePolicy: the permission string
 * first, then reach, then the lifecycle rule. Reach is two things — the
 * caller's organisation, and the caller's node scope, which an assessment
 * INHERITS FROM THE RISK IT ASSESSES rather than holding itself. That is why
 * every reach check below goes through `risk`: an assessment is not a thing a
 * branch is pinned to, the risk is.
 *
 * Absorbs the two inline Gate closures this module owned,
 * `approve-risk-assessment` and `resubmit-risk-assessment`
 * (AppServiceProvider), which are deleted in the same commit.
 *
 * Named for the model rather than the module (the phase prompt says
 * "AssessmentPolicy") so Laravel's convention discovers it:
 * App\Models\RiskAssessment resolves to App\Policies\RiskAssessmentPolicy,
 * and a policy the container never finds authorises nothing.
 *
 * Gate::before grants super-admin every ability before any of this runs.
 */
class RiskAssessmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('assessment.view');
    }

    public function view(User $user, RiskAssessment $assessment): bool
    {
        return $user->can('assessment.view') && $this->withinReach($user, $assessment);
    }

    public function create(User $user): bool
    {
        return $user->can('assessment.create');
    }

    public function update(User $user, RiskAssessment $assessment): bool
    {
        return $user->can('assessment.create') && $this->withinReach($user, $assessment);
    }

    public function submit(User $user, RiskAssessment $assessment): bool
    {
        return $user->can('assessment.submit') && $this->withinReach($user, $assessment);
    }

    /**
     * The assigned reviewer, or a risk manager / CRO — the
     * `approve-risk-assessment` closure, plus the node scope every other
     * ability here applies.
     *
     * WHAT IS DELIBERATELY NOT HERE: the "only an in-review assessment can be
     * approved" rule. WorkflowEngine::canAct() asks this same ability through
     * RiskAssessmentBinding::gate(), and the screens answer a wrong status with
     * a flash message rather than a 403 — folding the lifecycle in would turn
     * "Only in-review assessments can be approved." into a permission denial
     * and would change what the engine considers actionable. The lifecycle
     * guard stays where it was, in the controller.
     */
    public function approve(User $user, RiskAssessment $assessment): bool
    {
        if (! $this->withinReach($user, $assessment)) {
            return false;
        }

        if ((int) $assessment->reviewer_id === (int) $user->id) {
            return true;
        }

        return $user->hasAnyRole(['chief-risk-officer', 'risk-manager']);
    }

    /** Rejecting is the same decision as approving, taken the other way. */
    public function reject(User $user, RiskAssessment $assessment): bool
    {
        return $this->approve($user, $assessment);
    }

    /** The `resubmit-risk-assessment` closure: the original assessor only. */
    public function resubmit(User $user, RiskAssessment $assessment): bool
    {
        return $this->withinReach($user, $assessment)
            && (int) $assessment->assessor_id === (int) $user->id;
    }

    /**
     * Same organisation, and inside the caller's subtree when they have one —
     * resolved through the risk, with the same visibleTo() query the grids
     * use, so a record hidden from a list is not reachable by policy either.
     */
    private function withinReach(User $user, RiskAssessment $assessment): bool
    {
        if ((int) $assessment->organization_id !== (int) $user->organization_id) {
            return false;
        }

        if (! GraphScope::isSubtreeLimited($user)) {
            return true;
        }

        if ($assessment->risk_id === null) {
            return false;
        }

        return Risk::query()
            ->whereKey($assessment->risk_id)
            ->visibleTo($user)
            ->exists();
    }
}
