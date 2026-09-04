<?php

namespace App\Policies;

use App\Models\ControlTest;
use App\Models\User;
use App\Support\Authorization\GraphScope;

/**
 * Migration Phase 3.4. Absorbs the `review-control-test` and
 * `resubmit-control-test` closures that lived in AppServiceProvider.
 *
 * THE TWO FOLDED ABILITIES KEEP THEIR OLD NAMES. WorkflowEngine::canAct()
 * asks `$user->can('review-control-test', $test)` through
 * ControlTestBinding::gate(), and Laravel resolves a hyphenated ability on
 * a model to the camel-cased policy method — reviewControlTest(). Those two
 * methods delegate to review() / resubmit(), so a review recorded from My
 * Tasks and one recorded on the test's own page pass the same check.
 *
 * They also keep their old SEMANTICS: no `control_test.review` permission
 * check inside the ability, because the workflow engine's own actor may hold
 * `approval.act` rather than the module permission. The route middleware
 * still requires `control_test.review` on the page's review action.
 *
 * Roles allowed to review on top of the assigned reviewer come from the
 * closure this replaces; they are the approver-class roles the seeder ships.
 */
class ControlTestPolicy
{
    /** @var list<string> */
    public const REVIEWER_ROLES = ['chief-risk-officer', 'risk-manager', 'compliance-officer'];

    public function viewAny(User $user): bool
    {
        return $user->can('control_test.view');
    }

    public function view(User $user, ControlTest $test): bool
    {
        return $user->can('control_test.view') && $this->reachable($user, $test);
    }

    public function create(User $user): bool
    {
        return $user->can('control_test.create');
    }

    public function update(User $user, ControlTest $test): bool
    {
        return $user->can('control_test.edit') && $this->reachable($user, $test);
    }

    /** Start, record results against, or attach evidence to a test. */
    public function execute(User $user, ControlTest $test): bool
    {
        return $user->can('control_test.execute') && $this->reachable($user, $test);
    }

    /** Was `review-control-test`: the assigned reviewer or an approver-class role. */
    public function review(User $user, ControlTest $test): bool
    {
        if ($user->organization_id !== $test->organization_id) {
            return false;
        }

        if ($test->reviewer_id === $user->id) {
            return true;
        }

        return $user->hasAnyRole(self::REVIEWER_ROLES);
    }

    /** Was `resubmit-control-test`: the assigned tester or the original creator. */
    public function resubmit(User $user, ControlTest $test): bool
    {
        if ($user->organization_id !== $test->organization_id) {
            return false;
        }

        return in_array($user->id, array_filter([$test->tester_id, $test->created_by]), true);
    }

    /** `$user->can('review-control-test', $test)` — the workflow engine's spelling. */
    public function reviewControlTest(User $user, ControlTest $test): bool
    {
        return $this->review($user, $test);
    }

    /** `$user->can('resubmit-control-test', $test)`. */
    public function resubmitControlTest(User $user, ControlTest $test): bool
    {
        return $this->resubmit($user, $test);
    }

    /**
     * Same organisation and, for a subtree-limited caller, a control inside
     * their subtree — a test is visible exactly when its control is.
     */
    private function reachable(User $user, ControlTest $test): bool
    {
        if ($user->organization_id !== $test->organization_id) {
            return false;
        }

        if (! GraphScope::isSubtreeLimited($user)) {
            return true;
        }

        return GraphScope::applyThrough(
            ControlTest::query()->whereKey($test->getKey()),
            'control',
            $user,
        )->exists();
    }
}
