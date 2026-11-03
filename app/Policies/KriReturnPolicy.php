<?php

namespace App\Policies;

use App\Models\KriReturn;
use App\Models\User;
use App\Support\Kri\KriDepartmentScope;

/**
 * Who may do what to a department's KRI return (ADR 0026 section 10).
 *
 * Named for its MODEL: Laravel finds App\Models\KriReturn's policy only as
 * App\Policies\KriReturnPolicy (development standard section 3). The order is
 * permission -> tenancy -> domain rule. Lifecycle rules ("only a submitted
 * return can be reopened") are NOT here; the controller answers them with a
 * flash message, not a 403.
 *
 * Seeing is wider than acting: view and remind reach the user's whole
 * department scope; complete, submit and reopen reach only departments where the
 * user is the configured risk champion, exactly - never a champion's
 * descendants. Risk Officers do not reopen.
 */
class KriReturnPolicy
{
    public function __construct(private readonly KriDepartmentScope $scope) {}

    /** Read-only view of a return. */
    public function view(User $user, KriReturn $return): bool
    {
        if (! $this->sameTenant($user, $return)) {
            return false;
        }

        $unitId = (int) $return->business_unit_id;

        // Any contact of this exact department may view it (Amendment 1, A1.1):
        // team leads, unit heads and group heads receive a link to this page and
        // usually hold no kri.view. Exact match, no descendants.
        if ($this->scope->isContactOf($user, $unitId)) {
            return true;
        }

        if ($user->can(KriDepartmentScope::ALL_DEPARTMENTS)) {
            return true;
        }

        return $user->can('kri.view') && $this->scope->reaches($user, $unitId);
    }

    /** Open the champion's edit screen. */
    public function complete(User $user, KriReturn $return): bool
    {
        return $user->can('kri.submit_return')
            && $this->sameTenant($user, $return)
            && $this->scope->isChampionOf($user, (int) $return->business_unit_id);
    }

    public function submit(User $user, KriReturn $return): bool
    {
        return $this->complete($user, $return);
    }

    public function reopen(User $user, KriReturn $return): bool
    {
        return $this->complete($user, $return);
    }

    public function remind(User $user, KriReturn $return): bool
    {
        return $user->can('kri.send_reminder')
            && $this->sameTenant($user, $return)
            && $this->scope->reaches($user, (int) $return->business_unit_id);
    }

    private function sameTenant(User $user, KriReturn $return): bool
    {
        return (int) $return->organization_id === (int) $user->organization_id;
    }
}
