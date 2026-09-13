<?php

namespace App\Policies;

use App\Models\Issue;
use App\Models\User;
use App\Support\Authorization\GraphScope;

/**
 * Who may do what to an issue (migration Phase 4.4).
 *
 * Six abilities for the six permissions the routes carry: issue.view,
 * issue.create, issue.edit, issue.escalate, issue.close and issue.delete.
 *
 * CLOSING IS NOT EDITING. `issue.close` is its own permission and the seeder
 * issues it separately — an issue is closed when somebody with the standing to
 * accept the remediation says so, which is a different act from updating the
 * action plan. The same goes for escalating.
 *
 * Reach is the caller's organisation and, for a subtree-limited caller, the
 * issue's own node — Issue uses ScopedToGraph, so it carries one.
 *
 * The lifecycle rules — which status may move to which, whether a closure has
 * already been requested — stay in the controller, answered with a flash
 * message rather than a 403, as everywhere else in this codebase.
 *
 * Gate::before grants super-admin every ability before any of this runs.
 */
class IssuePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('issue.view');
    }

    public function view(User $user, Issue $issue): bool
    {
        return $user->can('issue.view') && $this->withinReach($user, $issue);
    }

    public function create(User $user): bool
    {
        return $user->can('issue.create');
    }

    public function update(User $user, Issue $issue): bool
    {
        return $user->can('issue.edit') && $this->withinReach($user, $issue);
    }

    public function delete(User $user, Issue $issue): bool
    {
        return $user->can('issue.delete') && $this->withinReach($user, $issue);
    }

    /** Request closure, and accept or reject one. */
    public function close(User $user, Issue $issue): bool
    {
        return $user->can('issue.close') && $this->withinReach($user, $issue);
    }

    /** Raise the escalation level. */
    public function escalate(User $user, Issue $issue): bool
    {
        return $user->can('issue.escalate') && $this->withinReach($user, $issue);
    }

    /**
     * Record progress, attach evidence, add a remediation action. The owner's
     * day-to-day work on the issue, which is editing it.
     */
    public function recordProgress(User $user, Issue $issue): bool
    {
        return $this->update($user, $issue);
    }

    /**
     * Same organisation, and inside the caller's subtree when they have one.
     */
    private function withinReach(User $user, Issue $issue): bool
    {
        if ((int) $issue->organization_id !== (int) $user->organization_id) {
            return false;
        }

        if (! GraphScope::isSubtreeLimited($user)) {
            return true;
        }

        return Issue::query()->whereKey($issue->getKey())->visibleTo($user)->exists();
    }
}
