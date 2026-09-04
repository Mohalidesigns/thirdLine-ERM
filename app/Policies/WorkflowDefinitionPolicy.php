<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Support\Tenancy\TenantContext;

class WorkflowDefinitionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('workflow.view');
    }

    public function create(User $user): bool
    {
        return $user->can('workflow.manage');
    }

    public function update(User $user, WorkflowDefinition $definition): bool
    {
        return $user->can('workflow.manage') && $this->ownedByTenant($definition);
    }

    public function publish(User $user, WorkflowDefinition $definition): bool
    {
        return $this->update($user, $definition);
    }

    public function start(User $user, WorkflowDefinition $definition): bool
    {
        return $this->update($user, $definition);
    }

    private function ownedByTenant(WorkflowDefinition $definition): bool
    {
        return (int) $definition->organization_id === (int) TenantContext::organizationId();
    }
}
