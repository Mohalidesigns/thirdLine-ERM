<?php

namespace App\Policies\Rcsa;

use App\Models\Rcsa\RcsaRegisterRisk;
use App\Models\User;

/**
 * RCSA v2, P1. Permission first, then tenancy, then the domain rule.
 *
 * DISCOVERED, NOT REGISTERED. The model is `App\Models\Rcsa\RcsaRegisterRisk`,
 * and Laravel's guesser rewrites `\Models\` to `\Policies\` in the namespace,
 * so this file's location IS the wiring. `RcsaUniversePolicyTest` asserts it
 * with `Gate::getPolicyFor`, because a policy in the wrong namespace is not an
 * error — every ability silently returns false, which reads as a permissions
 * problem and is not one. The legacy RCSA module's `RcsaPolicy` is the
 * exception in this product, hand-registered against a stateless subject
 * because that module has no model; this one does, so it is discovered like
 * the other twelve.
 *
 * BUSINESS-UNIT SCOPING IS NOT ENFORCED HERE YET, AND THAT IS DELIBERATE.
 * §11 of the plan requires that a risk champion in Retail Operations cannot
 * read Treasury's rows, and the plan schedules that for P7 along with the rest
 * of the permission matrix ("penetration of cross-BU access fails on every
 * route"). What this policy enforces today is the permission and the tenant
 * boundary. `reachable()` is the single seam P7 changes — every ability already
 * routes through it, so the restriction lands in one method rather than
 * thirteen call sites.
 *
 * `users.business_unit_id` exists but is ONE unit, and the plan's requirement
 * is a set of assignments ("the units the user may see"). Reading the single
 * column as if it were that set would silently hide a risk manager's own
 * estate the moment anyone filled the field in.
 */
class RcsaRegisterRiskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('rcsa_universe.view');
    }

    public function view(User $user, RcsaRegisterRisk $risk): bool
    {
        return $user->can('rcsa_universe.view') && $this->reachable($user, $risk);
    }

    public function create(User $user): bool
    {
        return $user->can('rcsa_universe.create');
    }

    public function update(User $user, RcsaRegisterRisk $risk): bool
    {
        return $user->can('rcsa_universe.update') && $this->reachable($user, $risk);
    }

    public function delete(User $user, RcsaRegisterRisk $risk): bool
    {
        return $user->can('rcsa_universe.delete') && $this->reachable($user, $risk);
    }

    /**
     * Copying a risk into another unit CREATES a row, so it is create — plus
     * the right to read the one being copied. Treating it as update would let
     * someone with edit-only rights populate a unit they cannot otherwise
     * write to.
     */
    public function duplicate(User $user, RcsaRegisterRisk $risk): bool
    {
        return $user->can('rcsa_universe.create') && $this->reachable($user, $risk);
    }

    /**
     * Publishing is its own permission, not a stronger update.
     *
     * It is the act that lets a row into every future assessment, which is a
     * governance decision about master data rather than an edit — the whole
     * reason rule 1 of the process flow can say the system populates from
     * APPROVED data. Whoever writes the universe need not be whoever approves it
     * (§14 Q8 asks the bank exactly that), and separating the permissions is
     * what makes answering it a role change rather than a code change.
     */
    public function publish(User $user, RcsaRegisterRisk $risk): bool
    {
        return $user->can('rcsa_universe.publish') && $this->reachable($user, $risk);
    }

    /** Retiring withdraws a row from future cycles; same authority as publishing. */
    public function retire(User $user, RcsaRegisterRisk $risk): bool
    {
        return $this->publish($user, $risk);
    }

    /** Uploading a workbook writes master data in bulk. P2 builds the pipeline. */
    public function import(User $user): bool
    {
        return $user->can('rcsa_universe.import');
    }

    /**
     * Same organisation.
     *
     * The global tenancy scope already keeps another tenant's rows out of every
     * query, so this is the second line for a row reached some other way — a
     * service, a queued job, an id a page put in a link. P7 adds the
     * business-unit restriction here.
     */
    private function reachable(User $user, RcsaRegisterRisk $risk): bool
    {
        return $user->organization_id === $risk->organization_id;
    }
}
