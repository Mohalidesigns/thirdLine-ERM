<?php

namespace App\Models\Bcms\Concerns;

use App\Models\User;
use App\Support\Rcsa\RcsaScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;
use LogicException;

/*
 * `$anchor->scopeVisibleTo($query, $user)` below is a direct call on an
 * `instanceof`-narrowed `ScopedToOrgHierarchyContract`, not the magic
 * `$query->visibleTo()` scope forwarding — see that interface's docblock for
 * why: a trait gives PHPStan no way to know a class has it, and `instanceof`
 * is the one form of "does this class have this method" static analysis can
 * actually verify.
 */

/**
 * ADR 0017 — BCMS record visibility is resolved at route binding, not in a
 * policy. This is the whole enforcement: an out-of-scope uuid never reaches
 * the field match, so the row simply is not there and Laravel raises
 * `ModelNotFoundException` -> 404, never 403 (§3 — the same reasoning
 * standard §4 gives against a bare `exists:`, a 403 would confirm the record
 * exists at all).
 *
 * OVERRIDES `resolveRouteBindingQuery()`, NOT `resolveRouteBinding()` OR
 * `resolveChildRouteBinding()`. Laravel's own `Model::resolveChildRouteBinding()`
 * already constrains a scoped nested parameter to the parent relation and then
 * calls `$relationship->getRelated()->resolveRouteBindingQuery($relationship,
 * ...)` — the CHILD model's own override, handed the `Relation` in place of a
 * `Builder`. One override point covers a plain top-level `{model}` segment
 * (`resolveRouteBinding()` calls `resolveRouteBindingQuery($this, ...)`) and a
 * `->scopeBindings()` nested segment alike, which is exactly what §5 asks for
 * without a second method to keep in step with the first. `RcsaScope`'s own
 * `Builder|Relation` template already documents why this works: a `Relation`
 * forwards an unknown method call to its underlying query, so `->where()` and
 * `->whereHas()` behave the same on either.
 *
 * A MODEL IS CLASSIFIABLE ONE OF TWO WAYS (§2), and this trait asks which at
 * resolution time rather than at declaration time, so a model that gets the
 * classification wrong fails the very first time its route is hit rather than
 * silently:
 *
 *   - ANCHOR — also uses `ScopedToOrgHierarchy`. The row carries its own unit
 *     column, so the anchor's own `scopeVisibleTo()` is the whole predicate.
 *   - DERIVED — declares `orgAnchorPath(): string`, a dot path of relations
 *     ending on a model that uses `ScopedToOrgHierarchy`. The predicate is a
 *     `whereHas()` chain down that path, terminating in the anchor's own
 *     `scopeVisibleTo()`.
 *
 * A model using this trait and declaring neither throws rather than silently
 * granting or denying every row — the guard test (ADR §6) is what is supposed
 * to catch this before a request ever does, but the trait does not rely on the
 * test remembering to run.
 *
 * ORGANISATION-LEVEL MODELS DO NOT USE THIS TRAIT AT ALL. They are pinned by
 * name in `BcmsRecordVisibilityTest`'s allowlist, with a reason each; nothing
 * here applies to them, which is the point of §2's third category.
 */
trait BindsToVisibleRecord
{
    use AppliesNamedUserVisibility;

    /**
     * @param  Builder<static>|Relation<static, Model, *>|static  $query
     * @return Builder<static>|Relation<static, Model, *>
     */
    public function resolveRouteBindingQuery($query, $value, $field = null): Builder|Relation
    {
        if ($query instanceof Model) {
            $query = $query->newQuery();
        }

        $this->constrainToVisibleRecord($query);

        return $query->where($field ?? $this->getRouteKeyName(), $value);
    }

    /**
     * The predicate itself, exposed so `App\Rules\Bcms\VisibleToUser` can
     * apply the identical filter to an id arriving in a request body (§7)
     * rather than re-deriving it — and so `App\Services\Widgets\
     * WidgetQueryEngine` can apply the SAME rule for an explicit widget
     * context user, never a second implementation (R1, gate 1 code review
     * #2: a prior copy in that class had already drifted — it dropped the
     * named-user arm, lost the "whole estate" short-circuit by adding a
     * `whereHas()` existence requirement `unitIdsFor() === null` exists
     * specifically to avoid, and failed open where this method throws).
     *
     * `$user` DEFAULTS TO `Auth::user()`, so every existing caller
     * (`resolveRouteBindingQuery()`, `App\Rules\Bcms\VisibleToUser`) is
     * behaviourally unchanged — this widens the method to accept an
     * explicit user, it does not change what "no argument" means.
     *
     * @param  Builder<static>|Relation<static, Model, *>  $query
     */
    public function constrainToVisibleRecord(Builder|Relation $query, ?User $user = null): void
    {
        $user ??= Auth::user();

        if ($this instanceof ScopedToOrgHierarchyContract && $query instanceof Builder) {
            // Anchor: the row carries its own unit column, and
            // ScopedToOrgHierarchy already ORs in the named-user arm.
            // `scopeVisibleTo()`'s signature is `Builder`, matching the
            // Eloquent local-scope convention; no route in this product's
            // current set nests an anchor as a CHILD parameter (§5's five
            // nested groups all resolve a derived model), so `$query` is
            // always a plain `Builder` here in practice. If a future route
            // nests an anchor, this narrows to the `Relation` arm below,
            // which throws — a loud failure rather than a silently unscoped
            // bind.
            $this->scopeVisibleTo($query, $user);

            return;
        }

        $path = method_exists($this, 'orgAnchorPath') ? $this->orgAnchorPath() : null;

        if ($path === null) {
            throw new LogicException(
                static::class.' uses BindsToVisibleRecord but declares neither '
                .'ScopedToOrgHierarchy nor orgAnchorPath() — it is not classifiable '
                .'under ADR 0017 §2.'
            );
        }

        // ADR 0017's Consequences: "unauthenticated and system contexts are
        // unaffected" — `unitIdsFor(null)` returning null means the whole
        // estate, exactly as it does for an anchor's own `scopeVisibleTo()`.
        // Wrapping the query in `whereHas($path, ...)` regardless would ADD a
        // predicate this trait's introduction is not supposed to add: an
        // existence requirement on the anchor row. A soft-deleted occurrence
        // (`OccurrenceGenerator::reschedule()` does this) would then silently
        // hide every one of its readiness tasks from the scheduler and from
        // an `rcsa_scope.all_units` holder — the exact defect
        // `GraphScope::applyThrough()` documents and guards against with the
        // same shape (`isSubtreeLimited()`). Mirrored here rather than
        // reimplemented: ask the one engine the question, and if it says
        // "everything", apply nothing at all.
        if (app(RcsaScope::class)->unitIdsFor($user) === null) {
            return;
        }

        $namedUsers = method_exists($this, 'orgVisibilityNamedUsers') ? $this->orgVisibilityNamedUsers() : [];

        $query->where(function (Builder|Relation $q) use ($path, $namedUsers, $user): void {
            self::constrainAnchorPath($q, explode('.', $path), $user);
            $this->orNamedUserVisibility($q, $namedUsers, $user);
        });
    }

    /**
     * Walk a dot path of relations, ending in the anchor's own
     * `scopeVisibleTo()`.
     *
     * `assessment.process` becomes
     * `whereHas('assessment', fn ($q) => $q->whereHas('process', fn ($q) => ...))`.
     * Rule §2.2: every derived model takes the shortest path to an anchor, and
     * it is a single path — this walks exactly one.
     *
     * @param  Builder<*>|Relation<*, *, *>  $query
     * @param  list<string>  $segments
     */
    private static function constrainAnchorPath(Builder|Relation $query, array $segments, ?User $user): void
    {
        $relation = array_shift($segments);

        $query->whereHas($relation, function (Builder $q) use ($segments, $user): void {
            if ($segments === []) {
                // Called directly on the resolved anchor instance, not
                // through `$q->visibleTo()` — the same reason the anchor
                // branch above calls `scopeVisibleTo()` directly rather than
                // through the query: `instanceof` is the form of "does this
                // class have this method" PHPStan can verify across every
                // model this trait is ever used from.
                $anchor = $q->getModel();

                if (! $anchor instanceof ScopedToOrgHierarchyContract) {
                    // Fail loud, not open. A terminal that uses
                    // `ScopedToOrgHierarchy` but forgot `implements
                    // ScopedToOrgHierarchyContract` would otherwise apply NO
                    // predicate here at all — a silent fail-open on exactly
                    // the security boundary this trait exists to enforce.
                    throw new LogicException(
                        $anchor::class.' is the terminal of an orgAnchorPath() chain but does not '
                        .'`implements ScopedToOrgHierarchyContract` — it is not classifiable under '
                        .'ADR 0017 §2.'
                    );
                }

                $anchor->scopeVisibleTo($q, $user);

                return;
            }

            self::constrainAnchorPath($q, $segments, $user);
        });
    }
}
