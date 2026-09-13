<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Rcsa\RcsaProgramme;

/**
 * Who may read and file a Risk and Control Self-Assessment
 * (migration Phase 3.8).
 *
 * Registered explicitly — `Gate::policy(RcsaProgramme::class, self::class)` in
 * AppServiceProvider — because RCSA has no model for Laravel to discover a
 * policy from. See App\Support\Rcsa\RcsaProgramme for why that is the right
 * shape rather than a model invented to host it.
 *
 * The abilities are the two permissions the routes already carry, `rcsa.view`
 * and `rcsa.submit`, so this does not introduce a second, competing rule — it
 * gives the module one place that answers the question, which the Form Request
 * can call as well as the controller.
 *
 * NO NODE SCOPE HERE, deliberately. The screens each scope their own listing —
 * the worksheet and the controls table call `visibleTo()`, and the matrix goes
 * through `GraphScope::applyThrough(..., 'risk')` — because what a
 * subtree-limited assessor may SEE is a property of each row, not of the
 * module. A submission is filed against a BusinessUnit, and BusinessUnit does
 * not use ScopedToGraph: there is no node column on it to scope by, so a rule
 * restricting which unit an assessor may file for would have to invent one.
 * Tenancy on that id is enforced by the Form Request's tenant-bound
 * Rule::exists, which is what the controller did.
 *
 * Gate::before grants super-admin every ability before any of this runs.
 */
class RcsaPolicy
{
    /** Read any of the four RCSA screens. */
    public function viewAny(User $user): bool
    {
        return $user->can('rcsa.view');
    }

    /** File a worksheet, as a draft or as a submission. */
    public function submit(User $user): bool
    {
        return $user->can('rcsa.submit');
    }

    /**
     * Laravel passes the class string as the second argument for a class-based
     * check; these signatures accept it so `can('viewAny', RcsaProgramme::class)`
     * resolves here rather than falling through to a missing method.
     *
     * @param  class-string<RcsaProgramme>|null  $subject
     */
    public function view(User $user, ?string $subject = null): bool
    {
        return $this->viewAny($user);
    }
}
