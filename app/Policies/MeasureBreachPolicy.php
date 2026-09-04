<?php

namespace App\Policies;

use App\Models\MeasureBreach;
use App\Models\User;

/**
 * Who may act on a breach in the register (migration Phase 4.1).
 *
 * A SEPARATE POLICY FROM KeyRiskIndicatorPolicy, and it has to be: Laravel
 * resolves a policy from the SUBJECT's class, so `can('acknowledge', $breach)`
 * looks for App\Policies\MeasureBreachPolicy and nothing else. Putting these
 * two abilities on the KRI's policy would have left them unreachable — the
 * check would fall through to a Gate ability that does not exist and deny
 * everyone, silently.
 *
 * Reach is the breach's own organisation. A breach belongs to a MEASURE, and a
 * measure's subject may be a KRI or another object entirely; the register shows
 * them together, so the node scope belongs to the listing rather than here.
 *
 * `kri.acknowledge_breach` rather than `kri.edit`: acknowledging is what makes
 * mean time to acknowledge a real number, and it is a different job from
 * editing an indicator's definition.
 */
class MeasureBreachPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('kri.view');
    }

    public function view(User $user, MeasureBreach $breach): bool
    {
        return $user->can('kri.view') && $this->sameTenant($user, $breach);
    }

    /** Acknowledge an open breach. */
    public function acknowledge(User $user, MeasureBreach $breach): bool
    {
        return $user->can('kri.acknowledge_breach') && $this->sameTenant($user, $breach);
    }

    /** Closing a breach is the same decision as acknowledging it. */
    public function resolve(User $user, MeasureBreach $breach): bool
    {
        return $this->acknowledge($user, $breach);
    }

    private function sameTenant(User $user, MeasureBreach $breach): bool
    {
        return (int) $breach->organization_id === (int) $user->organization_id;
    }
}
