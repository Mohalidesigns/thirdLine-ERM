<?php

namespace App\Policies;

use App\Models\OrganizationSsoSetting;
use App\Models\User;

/**
 * Who may configure the identity provider (migration Phase 6.2).
 *
 * Behind `admin.sso` rather than `admin.settings`, which is the separation
 * SsoSettingsController's docblock has always argued for: a mistake here is an
 * authentication bypass, so the ability to make it should be grantable
 * independently of the rest of the organisation profile. This class is where
 * that argument becomes enforceable from more than one entry point.
 */
class OrganizationSsoSettingPolicy
{
    public function view(User $user, OrganizationSsoSetting $setting): bool
    {
        return $user->can('admin.sso') && $this->own($user, $setting);
    }

    public function update(User $user, OrganizationSsoSetting $setting): bool
    {
        return $this->view($user, $setting);
    }

    private function own(User $user, OrganizationSsoSetting $setting): bool
    {
        return (int) $setting->organization_id === (int) $user->organization_id;
    }
}
