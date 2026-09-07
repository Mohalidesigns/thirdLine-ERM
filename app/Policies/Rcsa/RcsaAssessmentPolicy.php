<?php

namespace App\Policies\Rcsa;

use App\Models\Rcsa\RcsaAssessment;
use App\Models\User;

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

    private function reachable(User $user, RcsaAssessment $assessment): bool
    {
        return $user->organization_id === $assessment->organization_id;
    }
}
