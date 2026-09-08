<?php

namespace App\Models\Bcms\Concerns;

use App\Models\User;
use App\Support\Rcsa\RcsaScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * The org-hierarchy visibility rule for BCMS: a branch manager sees the Kano
 * drill calendar, a group risk officer sees the whole group.
 *
 * IT RESOLVES NOTHING ITSELF. Orchestration §5 names `ScopedToOrgHierarchy` as
 * a cross-track contract and forbids any track writing its own scoping, and
 * this product already has the engine: `business_unit_user` (assignments,
 * plural, with `includes_descendants`), the `rcsa_scope.all_units` permission,
 * and `App\Support\Rcsa\RcsaScope` which expands the tree. That class's own
 * migration says the pivot is deliberately general and that "a second module
 * needing it should not invent a second table" — BCMS is the second module.
 * Reimplementing the walk here would be scoping implemented twice, which is
 * scoping that disagrees with itself, and the disagreement is invisible until
 * somebody exports what they cannot see on screen.
 *
 * (The engine's namespace is now wrong for what it does. Renaming it is a
 * refactor of RCSA's call sites, not a Phase 0 decision; it is recorded as a
 * known gap in the Phase 0 handoff.)
 *
 * WHAT IS DIFFERENT HERE IS THE NULL ARM, and it is a real difference rather
 * than an oversight. `RcsaScope::apply()` is a plain `whereIn`, because an RCSA
 * assessment always belongs to a unit. A BCMS row often does not: the group
 * BCP, the enterprise crisis plan and the corporate exercise definition belong
 * to the organisation, and every branch that has to follow them must see them.
 * `business_unit_id IS NULL` is therefore visible to everyone in the tenant,
 * which is the same rule `RcsaScope::reaches()` already applies to a null unit.
 *
 * TENANCY IS A DIFFERENT QUESTION and is not this trait's job.
 * `BelongsToOrganization`'s global scope has already removed every other
 * organisation's rows before this filter runs. Conflating the two is how a
 * scoping bug becomes a cross-tenant leak.
 */
trait ScopedToOrgHierarchy
{
    /**
     * The column this model scopes on. Override where it is not the default;
     * a model with no unit at all should not use this trait.
     */
    public function orgScopeColumn(): string
    {
        return 'business_unit_id';
    }

    /**
     * The generic is not decoration: this returns the builder it was handed, so
     * a caller with a `Builder<CallTree>` gets one back. Declared as
     * `Builder<Model>` it made every typed caller a variance error and pushed
     * them towards widening their own return types to match.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @return \Illuminate\Database\Eloquent\Builder<TModel>
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        $units = app(RcsaScope::class)->unitIdsFor($user);

        // Null means the whole estate — the permission, or a system context
        // (queue worker, scheduler, console command) which is already bounded
        // by TenantContext.
        if ($units === null) {
            return $query;
        }

        $column = $this->orgScopeColumn();

        // An empty list is "assigned to nothing", not "everything". It still
        // sees organisation-level rows, and nothing belonging to a unit.
        return $query->where(function (Builder $q) use ($column, $units) {
            $q->whereNull($column);

            if ($units !== []) {
                $q->orWhereIn($column, $units);
            }
        });
    }

    /**
     * The same filter, applied to a builder a closure was handed.
     *
     * `whereHas('process', fn ($q) => $q->visibleTo($user))` gives static
     * analysis a bare `Builder` with no idea the scope exists. This is the same
     * rule reachable statically, so a nested constraint does not have to choose
     * between being analysable and being scoped.
     *
     * The scope is reached through the QUERY'S OWN MODEL rather than a fresh
     * instance: `new static` inside a trait is unsafe when the using class has
     * a constructor of its own, and the builder already carries the instance
     * that owns the scope.
     *
     * The generic is not decoration. This returns the builder it was handed,
     * so a caller with a `Builder<CallTree>` must get one back; declaring the
     * parameter as `Builder<Model>` made every typed caller a variance error
     * and pushed them towards widening their own return types to match.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @return \Illuminate\Database\Eloquent\Builder<TModel>
     */
    public static function visibleQuery(Builder $query, ?User $user): Builder
    {
        /** @var static $model */
        $model = $query->getModel();

        return $model->scopeVisibleTo($query, $user);
    }

    /**
     * Whether this particular row is visible to the user, for a policy.
     */
    public function isVisibleTo(?User $user): bool
    {
        $unitId = $this->getAttribute($this->orgScopeColumn());

        return app(RcsaScope::class)->reaches($user, $unitId);
    }
}
