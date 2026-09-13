<?php

namespace App\Policies\Tprm;

use App\Models\Tprm\Finding;
use App\Models\User;
use App\Policies\Tprm\Concerns\ChecksTprmAccess;

/**
 * Reading a finding, working it, and deciding not to fix it are three
 * different acts on three different permissions.
 *
 * `acceptRisk` is the one that matters. It is not `manage` with a flag: it is
 * the authority to say the institution will carry a control failure in a third
 * party, and it belongs with the risk function rather than with whoever is
 * chasing the vendor. `RiskAcceptanceService` checks the severity-specific
 * permission again on top of this, because a Critical acceptance is a
 * different decision from a Low one.
 */
class FindingPolicy
{
    use ChecksTprmAccess;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'tprm.finding.view');
    }

    public function view(User $user, Finding $finding): bool
    {
        return $this->allows($user, 'tprm.finding.view', $finding);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'tprm.finding.manage');
    }

    public function update(User $user, Finding $finding): bool
    {
        return $this->allows($user, 'tprm.finding.manage', $finding);
    }

    public function acceptRisk(User $user, Finding $finding): bool
    {
        return $this->allows($user, 'tprm.finding.accept_risk', $finding);
    }
}
