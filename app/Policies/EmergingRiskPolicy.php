<?php

namespace App\Policies;

use App\Models\EmergingRisk;
use App\Models\User;

/**
 * Who may maintain the horizon (migration Phase 4.6).
 *
 * IT ASKS FOR `risk.*`, NOT `emerging.*`, AND THAT IS DELIBERATE — it is the
 * permission set the routes have always carried, and the reasoning is in
 * EmergingRiskController's own docblock: an emerging risk is a register object,
 * and anyone trusted to maintain the risk register is trusted to maintain the
 * horizon in front of it. Minting a parallel `emerging.*` set here would give
 * every tenant four new permissions to assign that nothing had ever asked them
 * for, and would silently lock the screen for every existing role on upgrade.
 * The policy is still named for its MODEL, which is what makes it discovered.
 *
 * Reach is the tenant. An emerging risk is on the organisation's horizon, not a
 * node's — `emerging_risks` carries neither entity_id nor business_unit_id, and
 * the model has no graph scope — so there is no subtree to narrow to.
 *
 * Gate::before grants super-admin every ability before any of this runs.
 */
class EmergingRiskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('risk.view');
    }

    public function view(User $user, EmergingRisk $emerging): bool
    {
        return $user->can('risk.view') && $this->sameTenant($user, $emerging);
    }

    public function create(User $user): bool
    {
        return $user->can('risk.create');
    }

    public function update(User $user, EmergingRisk $emerging): bool
    {
        return $user->can('risk.edit') && $this->sameTenant($user, $emerging);
    }

    public function delete(User $user, EmergingRisk $emerging): bool
    {
        return $user->can('risk.delete') && $this->sameTenant($user, $emerging);
    }

    /**
     * Record that somebody has looked at the entry and it is still current.
     *
     * Editing, by the permission it asks for — but its own ability, because a
     * horizon whose entries are never revisited quietly rots and "when was this
     * last confirmed" is a different question from "who may change it".
     */
    public function review(User $user, EmergingRisk $emerging): bool
    {
        return $this->update($user, $emerging);
    }

    private function sameTenant(User $user, EmergingRisk $emerging): bool
    {
        return (int) $emerging->organization_id === (int) $user->organization_id;
    }
}
