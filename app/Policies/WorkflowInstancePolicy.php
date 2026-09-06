<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkflowInstance;
use ThirdLine\Platform\Tenancy\TenantContext;

class WorkflowInstancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('workflow.view');
    }

    public function view(User $user, WorkflowInstance $instance): bool
    {
        return $user->can('workflow.view') && $this->ownedByTenant($instance);
    }

    /** Acting on a step is the task's business (WorkflowTaskPolicy); this is the page-level grant. */
    public function act(User $user, WorkflowInstance $instance): bool
    {
        return $user->can('workflow.act') && $this->ownedByTenant($instance);
    }

    public function cancel(User $user, WorkflowInstance $instance): bool
    {
        return $user->can('workflow.manage') && $this->ownedByTenant($instance);
    }

    private function ownedByTenant(WorkflowInstance $instance): bool
    {
        return (int) $instance->organization_id === (int) TenantContext::organizationId();
    }
}
