<?php

namespace App\Policies\Tprm;

use App\Models\Tprm\Contract;
use App\Models\User;
use App\Policies\Tprm\Concerns\ChecksTprmAccess;

/**
 * Reading a contract and changing one are separate permissions, and both are
 * separate from waiving a clause.
 *
 * `tprm.contract.view` is wide — a relationship owner needs to see what the
 * agreement commits them to. `tprm.contract.manage` records and amends it.
 * Waiving a blocking clause is `tprm.waiver.approve`, held by the risk
 * function, because it admits a vendor a required term does not cover and it
 * is reported to the risk committee.
 */
class ContractPolicy
{
    use ChecksTprmAccess;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'tprm.contract.view');
    }

    public function view(User $user, Contract $contract): bool
    {
        return $this->allows($user, 'tprm.contract.view', $contract);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'tprm.contract.manage');
    }

    public function update(User $user, Contract $contract): bool
    {
        return $this->allows($user, 'tprm.contract.manage', $contract);
    }

    /**
     * Deleting a contract is deliberately absent as its own permission and
     * routed through `manage`, because a contract is not deleted in practice —
     * it is terminated, and the record of what was agreed has to survive the
     * relationship ending.
     */
    public function delete(User $user, Contract $contract): bool
    {
        return $this->allows($user, 'tprm.contract.manage', $contract);
    }
}
