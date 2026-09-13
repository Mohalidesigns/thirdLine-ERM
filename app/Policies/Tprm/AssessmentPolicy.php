<?php

namespace App\Policies\Tprm;

use App\Models\Tprm\Assessment;
use App\Models\User;
use App\Policies\Tprm\Concerns\ChecksTprmAccess;

/**
 * Named for its model, in the namespace Laravel's discovery looks in.
 *
 * `review` and `validateAssessment` are separate abilities on separate
 * permissions, because reviewing an answer and fixing the score it produces
 * are different acts: a reviewer works through the answers, and validation is
 * the moment the assessment's assurance number becomes the one the residual
 * risk is computed from.
 *
 * `validateAssessment` rather than `validate`, because `validate` collides
 * with the method every Laravel request object has and the resulting failure —
 * the ability silently resolving to the wrong thing — is the class of bug
 * development standard §3 was written about.
 */
class AssessmentPolicy
{
    use ChecksTprmAccess;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'tprm.assessment.view');
    }

    public function view(User $user, Assessment $assessment): bool
    {
        return $this->allows($user, 'tprm.assessment.view', $assessment);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'tprm.assessment.issue');
    }

    public function issue(User $user, Assessment $assessment): bool
    {
        return $this->allows($user, 'tprm.assessment.issue', $assessment);
    }

    public function review(User $user, Assessment $assessment): bool
    {
        return $this->allows($user, 'tprm.assessment.review', $assessment);
    }

    public function validateAssessment(User $user, Assessment $assessment): bool
    {
        return $this->allows($user, 'tprm.assessment.validate', $assessment);
    }
}
