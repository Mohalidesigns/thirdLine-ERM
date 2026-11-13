<?php

namespace App\Policies\Sm;

use App\Models\Sm\ScenarioVariant;
use App\Models\User;

/**
 * Named for its model: `App\Models\Sm\ScenarioVariant` → this class (standard §3).
 */
class ScenarioVariantPolicy
{
    public function view(User $user, ScenarioVariant $variant): bool
    {
        return $user->can('scenario.view') && $this->reachable($user, $variant);
    }

    public function update(User $user, ScenarioVariant $variant): bool
    {
        return $user->can('scenario.update') && $this->reachable($user, $variant);
    }

    private function reachable(User $user, ScenarioVariant $variant): bool
    {
        if ((int) $variant->organization_id !== (int) $user->organization_id) {
            return false;
        }

        $scenario = $variant->scenario;
        if (! $scenario || (int) $scenario->organization_id !== (int) $user->organization_id) {
            return false;
        }

        return true;
    }
}
