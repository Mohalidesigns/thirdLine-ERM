<?php

namespace App\Policies;

use App\Models\RiskLibraryItem;
use App\Models\User;

/**
 * Policy for App\Models\RiskLibraryItem (Standard §3, ADR 0028 Decision 6 & 7).
 *
 * Order: permission -> tenancy -> domain rule.
 * delete() ALWAYS returns false: library items are retired, never deleted.
 */
class RiskLibraryItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('risk_library.view');
    }

    public function view(User $user, RiskLibraryItem $item): bool
    {
        return $user->can('risk_library.view') && $this->sameOrganization($user, $item);
    }

    public function create(User $user): bool
    {
        return $user->can('risk_library.create');
    }

    public function update(User $user, RiskLibraryItem $item): bool
    {
        return $user->can('risk_library.update') && $this->sameOrganization($user, $item);
    }

    public function retire(User $user, RiskLibraryItem $item): bool
    {
        return $user->can('risk_library.retire') && $this->sameOrganization($user, $item);
    }

    /**
     * Hard deletion is permanently prohibited (ADR 0028 Decision 6).
     */
    public function delete(User $user, RiskLibraryItem $item): bool
    {
        return false;
    }

    private function sameOrganization(User $user, RiskLibraryItem $item): bool
    {
        return (int) $item->organization_id === (int) $user->organization_id;
    }
}
