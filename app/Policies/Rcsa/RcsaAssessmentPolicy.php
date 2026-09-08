<?php

namespace App\Policies\Rcsa;

use App\Models\Rcsa\RcsaAssessment;
use App\Models\User;
use App\Support\Rcsa\RcsaScope;

/**
 * RCSA v2, P3. Discovered from App\Models\Rcsa\RcsaAssessment.
 *
 * `complete` IS THE PERMISSION PLUS THE STATE. Holding `rcsa_assessment.complete`
 * is not enough on its own: the assessment has to be in a state that accepts
 * edits, and its cycle has to be open. Putting that here rather than only in
 * the controller means a service, a queued job or a future API route cannot
 * write to a submitted assessment by taking a different path in.
 *
 * BUSINESS-UNIT SCOPING IS STILL P7, exactly as it is on the universe policy.
 * §11's rule — a risk champion in Retail cannot open Treasury's assessment —
 * lands in `reachable()`, which every ability already routes through.
 */
class RcsaAssessmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('rcsa_assessment.view');
    }

    public function view(User $user, RcsaAssessment $assessment): bool
    {
        return $user->can('rcsa_assessment.view') && $this->reachable($user, $assessment);
    }

    /**
     * May this user change a line in this assessment right now?
     */
    public function complete(User $user, RcsaAssessment $assessment): bool
    {
        return $user->can('rcsa_assessment.complete')
            && $this->reachable($user, $assessment)
            && $assessment->acceptsEdits();
    }

    /**
     * May this user submit it?
     *
     * The permission plus the state, as `complete` is — an assessment already
     * submitted cannot be submitted again, and one whose cycle has closed
     * cannot be submitted at all. Whether the BLOCKERS are clear is not asked
     * here: that is a question about the assessment's content rather than
     * about this user's authority, and RcsaSubmissionService answers it with a
     * list rather than a yes or no.
     */
    public function submit(User $user, RcsaAssessment $assessment): bool
    {
        return $user->can('rcsa_assessment.submit')
            && $this->reachable($user, $assessment)
            && $assessment->acceptsEdits();
    }

    /**
     * May this user approve it on behalf of the business unit?
     *
     * THE APPROVER CANNOT BE THE SUBMITTER. The BU-head step exists so that
     * somebody other than the person who filled the assessment in signs it off;
     * a head who also happens to be the unit's risk champion approves nothing
     * by pressing two buttons in a row. Where that leaves a one-person unit
     * unable to proceed, the answer is to turn the step off for the tenant, not
     * to let it be self-approved.
     */
    public function approve(User $user, RcsaAssessment $assessment): bool
    {
        return $user->can('rcsa_assessment.approve')
            && $this->reachable($user, $assessment)
            && $assessment->status === RcsaAssessment::BU_APPROVAL
            && (int) $assessment->submitted_by !== (int) $user->id;
    }

    /**
     * May this user open it in the review queue and challenge its lines?
     *
     * THE REVIEWER CANNOT BE THE SUBMITTER, for the same reason and more
     * strongly: §9 is a two-person control between the first line and the
     * second, and an assessment reviewed by the person who filed it has not
     * been reviewed. This is the check that makes the separation real rather
     * than a matter of which permissions an administrator happened to hand out.
     */
    public function review(User $user, RcsaAssessment $assessment): bool
    {
        return $user->can('rcsa_assessment.review')
            && $this->reachable($user, $assessment)
            && $assessment->acceptsReview()
            && (int) $assessment->submitted_by !== (int) $user->id;
    }

    /**
     * May this user accept it?
     *
     * Validating requires being able to review it in the first place — a
     * permission to decide is not a permission to decide something you may not
     * look at — plus the authority to make the decision.
     */
    public function validate(User $user, RcsaAssessment $assessment): bool
    {
        return $user->can('rcsa_assessment.validate') && $this->review($user, $assessment);
    }

    /**
     * May this user send it back?
     */
    public function returnForRework(User $user, RcsaAssessment $assessment): bool
    {
        return $user->can('rcsa_assessment.return') && $this->review($user, $assessment);
    }

    /**
     * May this user decide a treatment override? (§14 Q5.)
     *
     * Its own permission on top of `review`, for the reason the catalog entry
     * gives: who signs off "the formula says TREAT and we are choosing to
     * ACCEPT" is the question the bank was actually asked, and a tenant that
     * wants it on a risk committee rather than on the reviewer can move it
     * without touching `validate`.
     *
     * Routing through `review()` carries the two rules that matter for free —
     * the assessment must be reachable in this user's units, and THE SUBMITTER
     * CANNOT DECIDE. RcsaTreatmentOverrideService repeats the narrower version
     * of that (the requester cannot decide their own) because the service is
     * reachable from paths this policy is not on.
     */
    public function approveOverride(User $user, RcsaAssessment $assessment): bool
    {
        return $user->can('rcsa_assessment.approve_override') && $this->review($user, $assessment);
    }

    /**
     * Escalating is part of reviewing: raising a hand is not a decision, and a
     * reviewer who can see something wrong but cannot say so to anybody senior
     * is the reason escalation paths go unused.
     */
    public function escalate(User $user, RcsaAssessment $assessment): bool
    {
        return $this->review($user, $assessment);
    }

    /**
     * Same organisation, and a business unit this user is assigned to (§11).
     *
     * Every ability on this policy already routes through here, which is why
     * P3 and P5 could defer the second half to P7: adding it in one place adds
     * it to view, complete, submit, approve, review, validate, return and
     * escalate at once.
     */
    private function reachable(User $user, RcsaAssessment $assessment): bool
    {
        return $user->organization_id === $assessment->organization_id
            && app(RcsaScope::class)->reaches($user, $assessment->business_unit_id);
    }
}
