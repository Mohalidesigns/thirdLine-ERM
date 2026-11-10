<?php

namespace App\Policies\Sm;

use App\Models\Sm\Note;
use App\Models\User;

/**
 * Named for its model: `App\Models\Sm\Note` → this class (standard §3).
 *
 * THERE IS NO `update` AND NO `delete` ABILITY HERE, AND THAT IS THE POINT.
 * §A1.7: "APPEND-ONLY: Phase 1 registers no update and no destroy route. A corrected
 * note is a new note. This is the bias-mitigation trail D4 rests on, and a dissent
 * its author can quietly edit is not a dissent trail." Enforced by the absence of
 * routes AND of these abilities, plus a store-only Form Request — not by a DB
 * trigger, which the product has none of.
 *
 * `create` ASKS `scenario.note`, NOT `scenario.update`. §A1.10 splits them because
 * they are different authorities: editing a scenario is first-line work, recording a
 * dissent against somebody else's is second-line work, and one permission for both
 * lets the designer write the dissent log the trail exists to keep. Per §A1.10's
 * grants, `risk-analyst`, `risk-owner` and `compliance-officer` hold
 * `scenario.view` + `scenario.note` ONLY — so a risk owner can open a scenario they
 * cannot edit and dissent against it. Spec 01 §5.5's table is the user-visible half
 * of the same rule: the Notes column is gated on `can.note`, and the one status where
 * notes close is `archived`, where the record is out of circulation.
 */
class NotePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('scenario.view');
    }

    public function view(User $user, Note $note): bool
    {
        return $user->can('scenario.view') && $this->reachable($user, $note);
    }

    /**
     * Takes no model: a store ability is asked before the row exists, and the
     * tenancy check that matters is on the PARENT scenario, which the route binds
     * through `ResolveTenant` + `BelongsToOrganization` before this runs.
     *
     * The `archived` refusal is NOT here. It is a lifecycle rule, and standard §3
     * keeps those in the controller answering with a flash rather than a 403.
     */
    public function create(User $user): bool
    {
        return $user->can('scenario.note');
    }

    private function reachable(User $user, Note $note): bool
    {
        if ((int) $note->organization_id !== (int) $user->organization_id) {
            return false;
        }

        $scenario = $note->scenario;

        return $scenario !== null
            && (int) $scenario->organization_id === (int) $user->organization_id;
    }
}
