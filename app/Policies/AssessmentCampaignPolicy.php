<?php

namespace App\Policies;

use App\Models\AssessmentCampaign;
use App\Models\User;

/**
 * Who may run an assessment campaign (migration Phase 4.5).
 *
 * Five abilities for the five permissions the routes carry: campaign.view,
 * campaign.create, campaign.manage, campaign.respond and campaign.review.
 *
 * MANAGE, RESPOND AND REVIEW ARE THREE DIFFERENT PEOPLE, and that is the whole
 * shape of an RCSA. The programme office builds the campaign and hands out the
 * work (manage); the business unit answers for itself (respond); somebody in
 * the second line accepts or returns that answer (review). A campaign whose
 * respondent could approve their own submission would not be assurance, so
 * these never collapse into one another and the seeder issues them separately.
 *
 * RESPOND AND REVIEW TAKE THE CAMPAIGN, NOT THE ASSIGNMENT. Both acts happen
 * against a `CampaignAssignment`, but Laravel resolves a policy from the
 * SUBJECT's class — `can('respond', $assignment)` would look for a
 * `CampaignAssignmentPolicy`, find none, and deny everyone silently (4.1 shipped
 * exactly that bug against MeasureBreach). campaign_assignments carries no
 * organization_id of its own either, so the campaign is where both the tenancy
 * check and the permission live; the row-level rules — which statuses may be
 * responded to, whether a submission is waiting on review — stay in the
 * controller, answered with a flash message rather than a 403.
 *
 * Reach is the tenant. A campaign is the organisation's programme, not a node's:
 * its assignments are the thing that names business units.
 *
 * Gate::before grants super-admin every ability before any of this runs.
 */
class AssessmentCampaignPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('campaign.view');
    }

    public function view(User $user, AssessmentCampaign $campaign): bool
    {
        return $user->can('campaign.view') && $this->sameTenant($user, $campaign);
    }

    public function create(User $user): bool
    {
        return $user->can('campaign.create');
    }

    /** Add assignments, launch, close — the programme office's work. */
    public function manage(User $user, AssessmentCampaign $campaign): bool
    {
        return $user->can('campaign.manage') && $this->sameTenant($user, $campaign);
    }

    /** Open the response form and file a submission against an assignment. */
    public function respond(User $user, AssessmentCampaign $campaign): bool
    {
        return $user->can('campaign.respond') && $this->sameTenant($user, $campaign);
    }

    /** Accept or return a submitted assignment. */
    public function review(User $user, AssessmentCampaign $campaign): bool
    {
        return $user->can('campaign.review') && $this->sameTenant($user, $campaign);
    }

    private function sameTenant(User $user, AssessmentCampaign $campaign): bool
    {
        return (int) $campaign->organization_id === (int) $user->organization_id;
    }
}
