<?php

namespace App\Policies;

use App\Models\RiskAppetite;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Migration Phase 3.6. Permission first, then tenancy: a statement from
 * another organisation is never visible, whatever the grant.
 */
class RiskAppetitePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('appetite.view');
    }

    public function view(User $user, RiskAppetite $appetite): bool
    {
        return $user->can('appetite.view') && $this->ownedByTenant($appetite);
    }

    public function create(User $user): bool
    {
        return $user->can('appetite.manage');
    }

    public function update(User $user, RiskAppetite $appetite): bool
    {
        return $user->can('appetite.manage') && $this->ownedByTenant($appetite);
    }

    public function delete(User $user, RiskAppetite $appetite): bool
    {
        return $user->can('appetite.manage') && $this->ownedByTenant($appetite);
    }

    public function approve(User $user, RiskAppetite $appetite): bool
    {
        return $user->can('appetite.approve') && $this->ownedByTenant($appetite);
    }

    private function ownedByTenant(RiskAppetite $appetite): bool
    {
        return (int) $appetite->organization_id === (int) TenantContext::organizationId();
    }
}
