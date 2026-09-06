<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

/**
 * Who may read and change an institution's own settings (migration Phase 6.2).
 *
 * `admin.settings` is the permission the routes carry. What this adds is the
 * question nothing was asking: whether the organisation being edited is the
 * actor's own. `OrganizationSettingsController` resolved it from
 * `TenantContext` and never compared, which was safe by construction and
 * silent about it; a later refactor that accepted an id from the request would
 * have found nothing in its way.
 *
 * Single sign-on is deliberately NOT here — see OrganizationSsoSettingPolicy.
 * A mistake there is an authentication bypass, not a cosmetic setting, so it
 * carries its own permission.
 *
 * Gate::before grants super-admin every ability before any of this runs.
 */
class OrganizationPolicy
{
    public function viewSettings(User $user, Organization $organization): bool
    {
        return $user->can('admin.settings') && $this->own($user, $organization);
    }

    public function updateSettings(User $user, Organization $organization): bool
    {
        return $this->viewSettings($user, $organization);
    }

    private function own(User $user, Organization $organization): bool
    {
        return (int) $organization->id === (int) $user->organization_id;
    }
}
