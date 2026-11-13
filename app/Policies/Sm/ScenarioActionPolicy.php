<?php

namespace App\Policies\Sm;

use App\Models\Sm\ScenarioAction;
use App\Models\User;

/**
 * Named for its model: `App\Models\Sm\ScenarioAction` → this class (standard §3).
 */
class ScenarioActionPolicy
{
    public function view(User $user, ScenarioAction $action): bool
    {
        return $user->can('scenario.view') && $this->reachable($user, $action);
    }

    public function update(User $user, ScenarioAction $action): bool
    {
        return $user->can('scenario.manage_actions') && $this->reachable($user, $action);
    }

    public function delete(User $user, ScenarioAction $action): bool
    {
        return $user->can('scenario.manage_actions') && $this->reachable($user, $action);
    }

    public function promote(User $user, ScenarioAction $action): bool
    {
        return $user->can('scenario.manage_actions') && $this->reachable($user, $action);
    }

    private function reachable(User $user, ScenarioAction $action): bool
    {
        if ((int) $action->organization_id !== (int) $user->organization_id) {
            return false;
        }

        $scenario = $action->scenario;
        if (! $scenario || (int) $scenario->organization_id !== (int) $user->organization_id) {
            return false;
        }

        return true;
    }
}
