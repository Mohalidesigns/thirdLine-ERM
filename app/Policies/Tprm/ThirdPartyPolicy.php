<?php

namespace App\Policies\Tprm;

use App\Models\Tprm\ThirdParty;
use App\Models\User;
use App\Policies\Tprm\Concerns\ChecksTprmAccess;

/**
 * Named for its model, in the namespace Laravel's discovery looks in.
 *
 * Development standard §3 records two outages caused by getting this wrong:
 * an ability written on the wrong policy class returns false for everybody,
 * silently, and nothing fails until a user reports a 403 they should not have.
 */
class ThirdPartyPolicy
{
    use ChecksTprmAccess;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'tprm.view');
    }

    public function view(User $user, ThirdParty $thirdParty): bool
    {
        return $this->allows($user, 'tprm.view', $thirdParty);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'tprm.create');
    }

    public function update(User $user, ThirdParty $thirdParty): bool
    {
        return $this->allows($user, 'tprm.edit', $thirdParty);
    }

    public function delete(User $user, ThirdParty $thirdParty): bool
    {
        return $this->allows($user, 'tprm.delete', $thirdParty);
    }

    /**
     * Merging two records rewrites history for both, so it needs the delete
     * authority rather than the edit one.
     */
    public function merge(User $user, ThirdParty $thirdParty): bool
    {
        return $this->allows($user, 'tprm.delete', $thirdParty);
    }

    public function screen(User $user, ThirdParty $thirdParty): bool
    {
        return $this->allows($user, 'tprm.screening.view', $thirdParty);
    }

    public function managePortal(User $user, ThirdParty $thirdParty): bool
    {
        return $this->allows($user, 'tprm.portal.manage', $thirdParty);
    }
}
