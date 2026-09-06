<?php

namespace App\Policies;

use App\Models\ConfigBundle;
use App\Models\User;

/**
 * Who may export, diff, apply and roll back configuration (migration Phase 6.6).
 *
 * `admin.configuration` is the narrowest grant in the product, and deliberately
 * so: an import rewrites the tenant's whole definition set — object types,
 * fields, lifecycles, relationship types, scoring profiles — in one
 * transaction. That is why it sits in its own middleware group rather than
 * under `admin.metadata`.
 *
 * The controller already asserted tenant ownership on every bound model, with
 * the reasoning that a bundle is a complete statement of another organisation's
 * configuration and route-model binding on an explicit id is the one place
 * worth checking twice. That check moves here, so a console command or an API
 * asks the same question the screen does.
 */
class ConfigBundlePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('admin.configuration');
    }

    public function view(User $user, ConfigBundle $bundle): bool
    {
        return $user->can('admin.configuration') && $this->own($user, $bundle);
    }

    public function create(User $user): bool
    {
        return $user->can('admin.configuration');
    }

    /**
     * Apply a bundle to this organisation's configuration.
     *
     * Not a separate ability from `view` by accident — it is the same grant,
     * named separately so that a future read-only role has somewhere to stop.
     */
    public function apply(User $user, ConfigBundle $bundle): bool
    {
        return $this->view($user, $bundle);
    }

    private function own(User $user, ConfigBundle $bundle): bool
    {
        return (int) $bundle->organization_id === (int) $user->organization_id;
    }
}
