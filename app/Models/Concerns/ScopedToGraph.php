<?php

namespace App\Models\Concerns;

use App\Models\User;
use App\Support\Authorization\GraphScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Adds Model::visibleTo($user) to models that hang off the organizational
 * graph via entity_id.
 *
 * Deliberately an opt-in query scope rather than a global one: node scoping
 * answers "what may this person see", which is a question about a caller, and
 * a global scope would silently narrow background jobs and roll-up
 * calculations that must run across the whole graph.
 *
 * ROUTE-MODEL BINDING IS THE ONE EXCEPTION, and it is not really one. A bound
 * model comes from a URL, so there is always a caller and it is always the
 * authenticated user — the exact case the opt-in rule is protecting. Nothing
 * that runs without a caller (a job, a scheduled command, a report roll-up)
 * resolves a route binding, so scoping it narrows no aggregate. See
 * resolveRouteBindingQuery() below.
 */
trait ScopedToGraph
{
    public function scopeVisibleTo(Builder $query, ?User $user = null): Builder
    {
        return GraphScope::apply(
            $query,
            $user ?? auth()->user(),
            $this->getGraphEntityColumn()
        );
    }

    public function getGraphEntityColumn(): string
    {
        return 'entity_id';
    }

    /**
     * Resolve a bound {risk}, {control}, {issue}, {lossEvent} or {kri} within
     * the caller's subtree, so an id outside it is NOT FOUND.
     *
     * PREVIOUS BEHAVIOUR: binding filtered on the tenancy global scope and the
     * primary key alone. Every index in the product could be scoped perfectly
     * and a branch-pinned user would still read any record in the group by
     * typing its id into the address bar — the list was filtered, the detail
     * page was not. Controllers do carry an explicit check as well, but a check
     * that has to be remembered in each of ~40 methods is a check that will be
     * missing from the one added next quarter.
     *
     * WHY 404 FALLS OUT OF THIS FOR FREE: returning null from binding makes the
     * router throw ModelNotFoundException, which renders as 404 — the same
     * answer TenancyIsolationTest already pins for a cross-tenant read, and the
     * right one, because a 403 confirms the record exists. "There is no
     * LE-0117" and "there is an LE-0117 and you may not read it" are different
     * disclosures, and the second one tells a branch manager that another
     * subsidiary is carrying a loss event he was never meant to know about.
     *
     * Overridden at resolveRouteBindingQuery() rather than at
     * resolveRouteBinding() so the soft-deletable (`withTrashed`) binding path
     * gets the same treatment from one place. The instanceof guard is for the
     * child-binding path, where Laravel passes a Relation rather than a
     * Builder; this application registers no scoped child bindings, and an
     * unscoped fall-through there is the pre-existing behaviour rather than a
     * new hole.
     *
     * @param  \Illuminate\Database\Eloquent\Model|\Illuminate\Contracts\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation  $query
     * @param  mixed  $value
     * @param  string|null  $field
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $query = $query->where($field ?? $this->getRouteKeyName(), $value);

        return $query instanceof Builder ? $query->visibleTo() : $query;
    }
}
