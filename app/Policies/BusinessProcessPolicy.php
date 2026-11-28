<?php

namespace App\Policies;

use App\Models\BusinessProcess;
use App\Models\User;

/**
 * Policy for App\Models\BusinessProcess (Standard §3, ADR 0028 Decision 7).
 *
 * Order: permission -> tenancy -> domain rule.
 */
class BusinessProcessPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('process.view');
    }

    public function view(User $user, BusinessProcess $process): bool
    {
        return $user->can('process.view') && $this->sameOrganization($user, $process);
    }

    public function create(User $user): bool
    {
        return $user->can('process.create');
    }

    public function update(User $user, BusinessProcess $process): bool
    {
        return $user->can('process.update') && $this->sameOrganization($user, $process);
    }

    public function delete(User $user, BusinessProcess $process): bool
    {
        return $user->can('process.update') && $this->sameOrganization($user, $process);
    }

    private function sameOrganization(User $user, BusinessProcess $process): bool
    {
        return (int) $process->organization_id === (int) $user->organization_id;
    }
}
