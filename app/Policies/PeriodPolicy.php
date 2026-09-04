<?php

namespace App\Policies;

use App\Models\Period;
use App\Models\User;

/**
 * Who may read and close the reporting calendar (migration Phase 4.2).
 *
 * Three permissions, three abilities, carried from the routes: period.view,
 * period.close and period.reopen. Reopen is separate from close and always was
 * — it is the one operation that can move a number a board pack has already
 * been built on, so a user who may close a period does not automatically get to
 * unclose one. The seeder gives the standard risk-manager role `period.close`
 * and NOT `period.reopen`.
 *
 * Reach is the tenant only. A period belongs to the organisation's calendar,
 * not to a node: closing March closes March for everyone, so there is no
 * subtree in which a period is partly closed.
 *
 * The lifecycle rules — "already closed", "not closed" — stay in the
 * controller, where they answer with a flash message rather than a 403, as
 * everywhere else in this codebase.
 *
 * Gate::before grants super-admin every ability before any of this runs.
 */
class PeriodPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('period.view');
    }

    public function view(User $user, Period $period): bool
    {
        return $user->can('period.view') && $this->sameTenant($user, $period);
    }

    public function close(User $user, Period $period): bool
    {
        return $user->can('period.close') && $this->sameTenant($user, $period);
    }

    public function reopen(User $user, Period $period): bool
    {
        return $user->can('period.reopen') && $this->sameTenant($user, $period);
    }

    private function sameTenant(User $user, Period $period): bool
    {
        return (int) $period->organization_id === (int) $user->organization_id;
    }
}
