<?php

namespace App\Policies;

use App\Models\ConfigBundleApplication;
use App\Models\User;

/**
 * Who may roll back an application (migration Phase 6.6).
 *
 * A rollback restores the configuration as it was before a log entry, which is
 * the same power as an apply pointed backwards — so it carries the same
 * permission and the same tenant check.
 */
class ConfigBundleApplicationPolicy
{
    public function view(User $user, ConfigBundleApplication $application): bool
    {
        return $user->can('admin.configuration')
            && (int) $application->organization_id === (int) $user->organization_id;
    }

    public function rollback(User $user, ConfigBundleApplication $application): bool
    {
        return $this->view($user, $application);
    }
}
