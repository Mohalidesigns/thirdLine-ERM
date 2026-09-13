<?php

namespace App\Policies;

use App\Models\LossEvent;
use App\Models\User;
use App\Support\Authorization\GraphScope;

/**
 * Who may do what to a loss event (migration Phase 4.3).
 *
 * Absorbs `approve-loss-event`, the LAST inline Gate::define a risk module
 * owned — after this only `view-grid` (Phase 2's grid guard) remains in
 * AppServiceProvider.
 *
 * THE ABILITY KEEPS ITS HYPHENATED NAME, following 3.4 and 3.5:
 * LossEventBinding::gate() returns 'approve-loss-event' and
 * WorkflowEngine::canAct() asks for it by that name, so approveLossEvent()
 * below catches the call and delegates. The binding never changes, and a
 * decision taken from My Tasks passes exactly the check one taken on the
 * event's own page does.
 *
 * The ability keeps its SEMANTICS too: no `loss_event.approve` permission check
 * inside it, because the workflow engine's own actor may hold `approval.act`
 * rather than the module permission. The route middleware still requires
 * `loss_event.approve`.
 *
 * Gate::before grants super-admin every ability before any of this runs.
 */
class LossEventPolicy
{
    /**
     * Roles that may approve on top of the assigned handler, from the closure
     * this replaces.
     *
     * @var list<string>
     */
    public const APPROVER_ROLES = ['chief-risk-officer', 'loss-event-manager', 'compliance-officer'];

    public function viewAny(User $user): bool
    {
        return $user->can('loss_event.view');
    }

    public function view(User $user, LossEvent $event): bool
    {
        return $user->can('loss_event.view') && $this->withinReach($user, $event);
    }

    public function create(User $user): bool
    {
        return $user->can('loss_event.create');
    }

    public function update(User $user, LossEvent $event): bool
    {
        return $user->can('loss_event.edit') && $this->withinReach($user, $event);
    }

    public function delete(User $user, LossEvent $event): bool
    {
        return $user->can('loss_event.delete') && $this->withinReach($user, $event);
    }

    /** Record the root cause analysis, and attach evidence to it. */
    public function recordRca(User $user, LossEvent $event): bool
    {
        return $user->can('loss_event.edit') && $this->withinReach($user, $event);
    }

    /**
     * Sign the RCA off. A different question from writing it: `loss_event.approve`
     * rather than `loss_event.edit`, because an analysis approved by whoever
     * wrote it is not an approval.
     */
    public function approveRca(User $user, LossEvent $event): bool
    {
        return $user->can('loss_event.approve') && $this->withinReach($user, $event);
    }

    /** Notify the CBN. Its own permission — it is a statutory filing. */
    public function notifyRegulator(User $user, LossEvent $event): bool
    {
        return $user->can('loss_event.cbn_notify') && $this->withinReach($user, $event);
    }

    /**
     * The `approve-loss-event` closure: the assigned handler, or an
     * approver-class role, plus the node scope every other ability applies.
     */
    public function approve(User $user, LossEvent $event): bool
    {
        if (! $this->withinReach($user, $event)) {
            return false;
        }

        if ((int) $event->assigned_to_id === (int) $user->id) {
            return true;
        }

        return $user->hasAnyRole(self::APPROVER_ROLES);
    }

    /** Rejecting is the same decision as approving, taken the other way. */
    public function reject(User $user, LossEvent $event): bool
    {
        return $this->approve($user, $event);
    }

    /** `$user->can('approve-loss-event', $event)` — the workflow engine's spelling. */
    public function approveLossEvent(User $user, LossEvent $event): bool
    {
        return $this->approve($user, $event);
    }

    /**
     * Same organisation, and inside the caller's subtree when they have one.
     * A loss event carries its own node (ScopedToGraph), so this is the model's
     * own visibleTo() scope.
     */
    private function withinReach(User $user, LossEvent $event): bool
    {
        if ((int) $event->organization_id !== (int) $user->organization_id) {
            return false;
        }

        if (! GraphScope::isSubtreeLimited($user)) {
            return true;
        }

        return LossEvent::query()->whereKey($event->getKey())->visibleTo($user)->exists();
    }
}
