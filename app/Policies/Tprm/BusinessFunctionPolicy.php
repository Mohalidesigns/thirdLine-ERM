<?php

namespace App\Policies\Tprm;

use App\Models\Tprm\BusinessFunction;
use App\Models\User;
use App\Policies\Tprm\Concerns\ChecksTprmAccess;

/**
 * The business function catalogue.
 *
 * `tprm.admin` to change, and that grant is heavier than it looks: clearing
 * `is_prohibited_outsourcing` on internal audit would remove the AC-01 block
 * that stops a bank outsourcing a function it may not outsource. The Form
 * Request for that column asks for the same permission and records the change
 * in the audit trail; the policy is the first of the two locks.
 */
class BusinessFunctionPolicy
{
    use ChecksTprmAccess;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'tprm.view');
    }

    public function view(User $user, BusinessFunction $function): bool
    {
        return $this->allows($user, 'tprm.view', $function);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'tprm.admin');
    }

    public function update(User $user, BusinessFunction $function): bool
    {
        return $this->allows($user, 'tprm.admin', $function);
    }

    public function delete(User $user, BusinessFunction $function): bool
    {
        return $this->allows($user, 'tprm.admin', $function);
    }
}
