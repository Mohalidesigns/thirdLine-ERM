<?php

namespace App\Policies;

use App\Models\Connector;
use App\Models\User;

/**
 * Who may configure connectors (migration Phase 6.7).
 *
 * `connector.view` reads; `connector.manage` changes. A connector holds
 * credentials to somebody else's system and writes into this one, so running
 * one is a change even when it reads nothing.
 */
class ConnectorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('connector.view');
    }

    public function view(User $user, Connector $connector): bool
    {
        return $user->can('connector.view') && $this->own($user, $connector);
    }

    public function create(User $user): bool
    {
        return $user->can('connector.manage');
    }

    public function update(User $user, Connector $connector): bool
    {
        return $user->can('connector.manage') && $this->own($user, $connector);
    }

    public function delete(User $user, Connector $connector): bool
    {
        return $this->update($user, $connector);
    }

    /** Reach the source with the settings as they stand, changing nothing. */
    public function test(User $user, Connector $connector): bool
    {
        return $this->update($user, $connector);
    }

    /**
     * A run writes into this organisation, so it is not a read — and it carries
     * its own permission on the route (`connector.run`), granted separately
     * from the ability to change the connector's settings.
     */
    public function run(User $user, Connector $connector): bool
    {
        return $user->can('connector.run') && $this->own($user, $connector);
    }

    private function own(User $user, Connector $connector): bool
    {
        return (int) $connector->organization_id === (int) $user->organization_id;
    }
}
