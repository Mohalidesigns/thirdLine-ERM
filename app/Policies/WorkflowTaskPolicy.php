<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkflowTask;
use App\Services\Workflow\WorkflowEngine;
use App\Support\Tenancy\TenantContext;

/**
 * Migration Phase 3.7. Permission first, tenancy second, then the engine's
 * own answer to "is this decision this person's to make" (assignee,
 * delegate, candidate role) — one source of truth for the screen, the
 * endpoint and the policy.
 */
class WorkflowTaskPolicy
{
    public function __construct(private readonly WorkflowEngine $engine) {}

    public function viewAny(User $user): bool
    {
        return $user->can('task.view');
    }

    public function view(User $user, WorkflowTask $task): bool
    {
        return $user->can('task.view') && $this->ownedByTenant($task);
    }

    public function act(User $user, WorkflowTask $task): bool
    {
        return $user->can('task.act') && $this->ownedByTenant($task) && $this->engine->canAct($task, $user);
    }

    public function delegate(User $user, WorkflowTask $task): bool
    {
        return $this->act($user, $task);
    }

    public function return(User $user, WorkflowTask $task): bool
    {
        return $this->act($user, $task);
    }

    private function ownedByTenant(WorkflowTask $task): bool
    {
        return (int) $task->organization_id === (int) TenantContext::organizationId();
    }
}
