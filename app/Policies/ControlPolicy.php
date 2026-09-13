<?php

namespace App\Policies;

use App\Models\Control;
use App\Models\User;
use App\Support\Authorization\GraphScope;

/**
 * Migration Phase 3.4. Permission first (the `control.*` strings the route
 * middleware already checks), then tenancy, then node scope.
 *
 * NODE SCOPE IS A 404 IN THE CONTROLLER AND A DENY HERE. Route-model binding
 * (ScopedToGraph) and EnforcesNodeScope answer a URL for a sibling branch's
 * control with NOT FOUND, because a 403 confirms the record exists. This
 * policy is the second line: a Control reached some other way — a service, a
 * task, a link a page built — is still refused for a subtree-limited user.
 */
class ControlPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('control.view');
    }

    public function view(User $user, Control $control): bool
    {
        return $user->can('control.view') && $this->reachable($user, $control);
    }

    public function create(User $user): bool
    {
        return $user->can('control.create');
    }

    public function update(User $user, Control $control): bool
    {
        return $user->can('control.edit') && $this->reachable($user, $control);
    }

    public function delete(User $user, Control $control): bool
    {
        return $user->can('control.delete') && $this->reachable($user, $control);
    }

    /** Link to or unlink from a risk: an edit of the control's mappings. */
    public function linkRisk(User $user, Control $control): bool
    {
        return $this->update($user, $control);
    }

    /**
     * Same organisation, and — for a caller pinned to part of the graph —
     * inside their subtree. Checked with the same query the index uses.
     */
    private function reachable(User $user, Control $control): bool
    {
        if ($user->organization_id !== $control->organization_id) {
            return false;
        }

        if (! GraphScope::isSubtreeLimited($user)) {
            return true;
        }

        return Control::query()->whereKey($control->getKey())->visibleTo($user)->exists();
    }
}
