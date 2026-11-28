<?php

namespace App\Policies;

use App\Models\ProcessGroup;
use App\Models\User;

/**
 * Policy for App\Models\ProcessGroup (Standard §3, ADR 0028 Decision 7).
 *
 * Order: permission -> tenancy -> domain rule.
 */
class ProcessGroupPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('process.view');
    }

    public function view(User $user, ProcessGroup $processGroup): bool
    {
        return $user->can('process.view') && $this->sameOrganization($user, $processGroup);
    }

    public function create(User $user): bool
    {
        return $user->can('process_group.manage');
    }

    public function update(User $user, ProcessGroup $processGroup): bool
    {
        return $user->can('process_group.manage') && $this->sameOrganization($user, $processGroup);
    }

    public function delete(User $user, ProcessGroup $processGroup): bool
    {
        return $user->can('process_group.manage') && $this->sameOrganization($user, $processGroup);
    }

    private function sameOrganization(User $user, ProcessGroup $processGroup): bool
    {
        return (int) $processGroup->organization_id === (int) $user->organization_id;
    }
}
