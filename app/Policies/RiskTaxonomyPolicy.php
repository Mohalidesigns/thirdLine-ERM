<?php

namespace App\Policies;

use App\Models\RiskTaxonomy;
use App\Models\User;

/**
 * Who may maintain the risk taxonomy (migration Phase 5.3).
 *
 * The taxonomy is the framework tree a bank maps its register onto — Basel
 * event types, CBN ORMS categories — and it is reached from the regulatory
 * module, so it asks for `regulatory.*` rather than minting a permission set
 * no tenant has ever been given.
 *
 * Reach is the tenant. Gate::before grants super-admin every ability first.
 */
class RiskTaxonomyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('regulatory.view');
    }

    public function view(User $user, RiskTaxonomy $node): bool
    {
        return $user->can('regulatory.view') && $this->sameTenant($user, $node);
    }

    public function create(User $user): bool
    {
        return $user->can('regulatory.manage');
    }

    public function update(User $user, RiskTaxonomy $node): bool
    {
        return $user->can('regulatory.manage') && $this->sameTenant($user, $node);
    }

    private function sameTenant(User $user, RiskTaxonomy $node): bool
    {
        return (int) $node->organization_id === (int) $user->organization_id;
    }
}
