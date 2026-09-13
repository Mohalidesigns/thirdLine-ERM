<?php

namespace App\Policies\Tprm;

use App\Models\Tprm\Engagement;
use App\Models\User;
use App\Policies\Tprm\Concerns\ChecksTprmAccess;

/**
 * The engagement is where risk is assessed, so it is where the consequential
 * authorities live.
 *
 * `overrideTier`, `approveWaiver` and `acceptRisk` are separate abilities on
 * separate permissions, not variations of `update`. Each one lets a vendor
 * into the estate on terms the model says are insufficient, and the whole
 * point of the module is that doing so is a recorded decision by somebody with
 * the standing to make it.
 *
 * LIFECYCLE RULES ARE NOT HERE. "Only an intake-approved engagement may enter
 * due diligence" is a status question, and standard §3 keeps those in the
 * controller, which answers a wrong status with a flash message rather than a
 * 403 — a user who is allowed to do a thing but cannot do it yet should be
 * told which, and a 403 says neither.
 */
class EngagementPolicy
{
    use ChecksTprmAccess;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'tprm.view');
    }

    public function view(User $user, Engagement $engagement): bool
    {
        return $this->allows($user, 'tprm.view', $engagement);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'tprm.create');
    }

    public function update(User $user, Engagement $engagement): bool
    {
        return $this->allows($user, 'tprm.edit', $engagement);
    }

    public function delete(User $user, Engagement $engagement): bool
    {
        return $this->allows($user, 'tprm.delete', $engagement);
    }

    public function approveIntake(User $user, Engagement $engagement): bool
    {
        return $this->allows($user, 'tprm.intake.approve', $engagement);
    }

    /** Reducing the scrutiny the model says a vendor needs. */
    public function overrideTier(User $user, Engagement $engagement): bool
    {
        return $this->allows($user, 'tprm.tier.override', $engagement);
    }

    /** Admitting a vendor a regulator-required contract term does not cover. */
    public function approveWaiver(User $user, Engagement $engagement): bool
    {
        return $this->allows($user, 'tprm.waiver.approve', $engagement);
    }

    /** Carrying a gap rather than closing it. */
    public function acceptRisk(User $user, Engagement $engagement): bool
    {
        return $this->allows($user, 'tprm.finding.accept_risk', $engagement);
    }

    public function issueAssessment(User $user, Engagement $engagement): bool
    {
        return $this->allows($user, 'tprm.assessment.issue', $engagement);
    }

    public function manageContract(User $user, Engagement $engagement): bool
    {
        return $this->allows($user, 'tprm.contract.manage', $engagement);
    }
}
