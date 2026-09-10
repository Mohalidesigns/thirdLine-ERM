<?php

namespace App\Policies\Tprm;

use App\Models\Tprm\Category;
use App\Models\User;
use App\Policies\Tprm\Concerns\ChecksTprmAccess;

/**
 * The vendor taxonomy is programme configuration, not register data.
 *
 * Anyone who can see the register can see the categories — they are on every
 * screen — but reshaping the taxonomy changes how every vendor is classified
 * and where its exposure rolls up in the ERM key risk areas, so it needs
 * `tprm.admin`.
 */
class CategoryPolicy
{
    use ChecksTprmAccess;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'tprm.view');
    }

    public function view(User $user, Category $category): bool
    {
        return $this->allows($user, 'tprm.view', $category);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'tprm.admin');
    }

    public function update(User $user, Category $category): bool
    {
        return $this->allows($user, 'tprm.admin', $category);
    }

    public function delete(User $user, Category $category): bool
    {
        return $this->allows($user, 'tprm.admin', $category);
    }
}
