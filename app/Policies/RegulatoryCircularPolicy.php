<?php

namespace App\Policies;

use App\Models\RegulatoryCircular;
use App\Models\User;

/**
 * Who may maintain the circular register (migration Phase 5.3).
 *
 * ASSESSING COMPLIANCE ASKS FOR `regulatory.manage`, NOT `regulatory.file`,
 * and that is deliberate. It is the permission the update-compliance route has
 * always carried, and `regulatory.file` is what SUBMITTING A RETURN against a
 * deadline asks for — a different act. Tightening this ability to `file` would
 * read plausibly and would silently lock the compliance panel for every
 * existing role that holds `manage` without `file`, which is not a change a
 * port gets to make. Same reasoning as EmergingRiskPolicy asking for `risk.*`.
 *
 * Reach is the tenant. Gate::before grants super-admin every ability first.
 */
class RegulatoryCircularPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('regulatory.view');
    }

    public function view(User $user, RegulatoryCircular $circular): bool
    {
        return $user->can('regulatory.view') && $this->sameTenant($user, $circular);
    }

    public function create(User $user): bool
    {
        return $user->can('regulatory.manage');
    }

    public function update(User $user, RegulatoryCircular $circular): bool
    {
        return $user->can('regulatory.manage') && $this->sameTenant($user, $circular);
    }

    /**
     * Record this institution's compliance position against the circular.
     *
     * Its own ability, because "are we compliant with this circular?" is a
     * different question from "may this record be edited" — but the same
     * permission the route has always required, for the reason in the class
     * note above.
     */
    public function assessCompliance(User $user, RegulatoryCircular $circular): bool
    {
        return $this->update($user, $circular);
    }

    private function sameTenant(User $user, RegulatoryCircular $circular): bool
    {
        return (int) $circular->organization_id === (int) $user->organization_id;
    }
}
