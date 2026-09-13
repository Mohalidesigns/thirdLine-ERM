<?php

namespace App\Policies\Tprm;

use App\Models\Tprm\Obligation;
use App\Models\User;
use App\Policies\Tprm\Concerns\ChecksTprmAccess;

/**
 * The obligation register.
 *
 * Recording that a duty was performed sits on `tprm.contract.manage` rather
 * than on a permission of its own: closing an obligation is a statement about
 * the contract, and the person who can amend the contract is the person who
 * can say what was done under it.
 */
class ObligationPolicy
{
    use ChecksTprmAccess;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'tprm.contract.view');
    }

    public function view(User $user, Obligation $obligation): bool
    {
        return $this->allows($user, 'tprm.contract.view', $obligation);
    }

    public function update(User $user, Obligation $obligation): bool
    {
        return $this->allows($user, 'tprm.contract.manage', $obligation);
    }
}
