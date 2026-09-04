<?php

namespace App\Policies;

use App\Models\ApprovalRequest;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

class ApprovalRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('approval.view');
    }

    public function approve(User $user, ApprovalRequest $approval): bool
    {
        return $user->can('approval.act') && $this->ownedByTenant($approval);
    }

    public function reject(User $user, ApprovalRequest $approval): bool
    {
        return $this->approve($user, $approval);
    }

    private function ownedByTenant(ApprovalRequest $approval): bool
    {
        return (int) $approval->organization_id === (int) TenantContext::organizationId();
    }
}
