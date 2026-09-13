<?php

namespace App\Policies\Tprm;

use App\Models\Tprm\QuestionnaireTemplate;
use App\Models\User;
use App\Policies\Tprm\Concerns\ChecksTprmAccess;

/**
 * The questionnaire builder's authority.
 *
 * A SHIPPED PACK IS READ-ONLY TO EVERY TENANT, including one holding
 * `tprm.questionnaire.manage`. It belongs to no organisation, so the tenancy
 * half of `allows()` would fail anyway — but the refusal is stated explicitly
 * here rather than left as a side effect, because "you may edit questionnaires,
 * but not this one, and here is why" is a different message from a bare 403.
 */
class QuestionnaireTemplatePolicy
{
    use ChecksTprmAccess;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'tprm.assessment.view');
    }

    public function view(User $user, QuestionnaireTemplate $template): bool
    {
        // A shipped pack is visible to everyone who can see assessments; it is
        // reference data, and hiding it would make the issue screen's list
        // unexplainable.
        if ($template->isSystemPack()) {
            return $user->can('tprm.assessment.view');
        }

        return $this->allows($user, 'tprm.assessment.view', $template);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'tprm.questionnaire.manage');
    }

    public function update(User $user, QuestionnaireTemplate $template): bool
    {
        return ! $template->isSystemPack()
            && $this->allows($user, 'tprm.questionnaire.manage', $template);
    }

    public function delete(User $user, QuestionnaireTemplate $template): bool
    {
        return $this->update($user, $template);
    }

    /** Cloning a shipped pack is how a tenant customises it. */
    public function clone(User $user, QuestionnaireTemplate $template): bool
    {
        return $this->allows($user, 'tprm.questionnaire.manage');
    }

    public function publish(User $user, QuestionnaireTemplate $template): bool
    {
        return $this->update($user, $template);
    }
}
