<?php

namespace App\Policies;

use App\Models\Risk;
use App\Models\User;
use App\Support\Authorization\GraphScope;

/**
 * Who may do what to a risk register entry (migration Phase 3.2).
 *
 * Shape follows ThirdLine's InvestigationCasePolicy: the permission string
 * first, then reach. Reach here is two things — the caller's organisation
 * (a risk from another tenant is never theirs, whatever their role) and the
 * caller's node scope (a branch-pinned user reaches only the subtree they are
 * pinned to, resolved with the SAME visibleTo() query the grids use, so a
 * record hidden from a list is not reachable by policy either).
 *
 * Gate::before grants super-admin every ability before any of this runs.
 * Route bindings already 404 a record outside the tenant or the subtree
 * (ScopedToGraph::resolveRouteBindingQuery), so a policy refusal on reach is
 * defence in depth for callers that hold a model some other way.
 */
class RiskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('risk.view');
    }

    public function view(User $user, Risk $risk): bool
    {
        return $user->can('risk.view') && $this->withinReach($user, $risk);
    }

    public function create(User $user): bool
    {
        return $user->can('risk.create');
    }

    public function update(User $user, Risk $risk): bool
    {
        return $user->can('risk.edit') && $this->withinReach($user, $risk);
    }

    public function delete(User $user, Risk $risk): bool
    {
        return $user->can('risk.delete') && $this->withinReach($user, $risk);
    }

    /** Map an existing control onto the risk (the Controls tab). */
    public function mapControl(User $user, Risk $risk): bool
    {
        return $this->update($user, $risk);
    }

    /** Edit the tenant-configured attributes (the Attributes tab). */
    public function updateAttributes(User $user, Risk $risk): bool
    {
        return $this->update($user, $risk);
    }

    /**
     * Same organisation, and inside the caller's subtree when they have one.
     */
    private function withinReach(User $user, Risk $risk): bool
    {
        if ((int) $risk->organization_id !== (int) $user->organization_id) {
            return false;
        }

        if (! GraphScope::isSubtreeLimited($user)) {
            return true;
        }

        return Risk::query()
            ->whereKey($risk->getKey())
            ->visibleTo($user)
            ->exists();
    }
}
