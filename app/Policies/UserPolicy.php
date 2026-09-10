<?php

namespace App\Policies;

use App\Models\User;

/**
 * Who may administer user accounts (migration Phase 6.1).
 *
 * `admin.users` is the permission the routes have always carried. What this
 * adds is the questions nothing was asking: whether the subject is in the
 * actor's own institution, and whether an account may act on itself.
 *
 * THE TENANT BOUNDARY IS ALREADY THERE, and it is worth saying where: `User`
 * uses `BelongsToOrganization`, so route-model binding never resolves another
 * institution's account and every one of these routes 404s rather than 403s.
 * The check below is the belt to that brace — the same reasoning
 * ReportController's `assertSameTenant()` carried — so a caller that reaches a
 * User some other way still cannot act on it.
 *
 * SELF-ACTION IS REFUSED for deactivation and role changes, which the
 * controller enforced inline for `destroy` and `toggleActive` and NOT for
 * `update`. An administrator editing their own roles is the one case where the
 * "you may manage users" permission is being used to change what the actor
 * themselves may do.
 *
 * Gate::before grants super-admin every ability before any of this runs.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('admin.users');
    }

    public function view(User $user, User $subject): bool
    {
        return $user->can('admin.users') && $this->sameTenant($user, $subject);
    }

    public function create(User $user): bool
    {
        return $user->can('admin.users');
    }

    public function update(User $user, User $subject): bool
    {
        return $user->can('admin.users') && $this->sameTenant($user, $subject);
    }

    /**
     * Deactivate, or flip the active flag.
     *
     * Never yourself: an administrator who deactivates their own account locks
     * themselves out of the tool that would let them undo it.
     */
    public function deactivate(User $user, User $subject): bool
    {
        return $this->update($user, $subject) && $user->id !== $subject->id;
    }

    /**
     * Issue a new temporary password.
     *
     * Never yourself — that is what the profile screen and the password reset
     * flow are for, and both prove possession of the account first.
     */
    public function resetPassword(User $user, User $subject): bool
    {
        return $this->update($user, $subject) && $user->id !== $subject->id;
    }

    /**
     * Grant the role that grants everything.
     *
     * ONLY A SUPER-ADMIN MAY CREATE ONE. `Gate::before` returns true for every
     * ability a super-admin is asked about, so `super-admin` is not a role like
     * the others — it is the whole platform. Until Phase 6.1 the roles field
     * was `required|array|min:1` with no rule on its elements, so anyone
     * holding `admin.users` could assign it: to a colleague, or to themselves
     * through the edit form. That is a straight path from "may manage users" to
     * "may do anything".
     */
    public function grantSuperAdmin(User $user): bool
    {
        return $user->hasRole(self::SUPER_ADMIN);
    }

    public const SUPER_ADMIN = 'super-admin';

    private function sameTenant(User $user, User $subject): bool
    {
        return (int) $subject->organization_id === (int) $user->organization_id;
    }
}
