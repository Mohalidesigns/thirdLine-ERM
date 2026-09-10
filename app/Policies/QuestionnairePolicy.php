<?php

namespace App\Policies;

use App\Models\Questionnaire;
use App\Models\User;

/**
 * Who may build and publish a questionnaire (migration Phase 4.5).
 *
 * Four abilities for the four permissions the routes carry: questionnaire.view,
 * questionnaire.create, questionnaire.edit and questionnaire.publish.
 *
 * PUBLISHING IS NOT EDITING. Only a published questionnaire can be attached to a
 * campaign, so publishing is the act that puts a set of questions in front of
 * every respondent in the bank; it is its own permission and always was.
 *
 * SECTIONS AND QUESTIONS ARE AUTHORISED THROUGH THEIR QUESTIONNAIRE. Neither
 * `questionnaire_sections` nor `questions` carries an organization_id, so
 * neither model is tenant-scoped and route model binding on {section} or
 * {question} resolves any id in the table — which is how a user with
 * questionnaire.edit in one bank could, until this phase, add a question to
 * another bank's section or delete another bank's question outright. The
 * controller now walks up to the owning Questionnaire (which IS scoped, so a
 * foreign parent reads back as null), 404s if there isn't one, and asks this
 * policy about that. The walk is both the tenancy check and the lookup, exactly
 * as CampaignController::tenantCampaignFor() does for assignments.
 *
 * Reach is the tenant. A questionnaire is a library object for the whole
 * organisation, not a node's.
 *
 * Gate::before grants super-admin every ability before any of this runs.
 */
class QuestionnairePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('questionnaire.view');
    }

    public function view(User $user, Questionnaire $questionnaire): bool
    {
        return $user->can('questionnaire.view') && $this->sameTenant($user, $questionnaire);
    }

    public function create(User $user): bool
    {
        return $user->can('questionnaire.create');
    }

    /** Add a section, add or remove a question. */
    public function update(User $user, Questionnaire $questionnaire): bool
    {
        return $user->can('questionnaire.edit') && $this->sameTenant($user, $questionnaire);
    }

    public function publish(User $user, Questionnaire $questionnaire): bool
    {
        return $user->can('questionnaire.publish') && $this->sameTenant($user, $questionnaire);
    }

    private function sameTenant(User $user, Questionnaire $questionnaire): bool
    {
        return (int) $questionnaire->organization_id === (int) $user->organization_id;
    }
}
