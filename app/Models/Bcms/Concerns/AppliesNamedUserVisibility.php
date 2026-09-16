<?php

namespace App\Models\Bcms\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * ADR 0017 §4 point 5 — "a user named on the row itself sees the row."
 *
 * Shared by `ScopedToOrgHierarchy` (the anchor's own `scopeVisibleTo()`) and
 * `BindsToVisibleRecord` (a derived model's binding-time predicate), so the
 * one small piece of parsing — a bare column versus a `relation.column`
 * pair — is written once. Neither host trait declares
 * `orgVisibilityNamedUsers()` itself; a model opts in by declaring the method
 * on itself, and both callers merely check `method_exists()` before reading
 * it, exactly as `ScopedToOrgHierarchy::orgScopeColumn()` is a convention
 * rather than an interface.
 *
 * STRICTLY A UNION, NEVER A NARROWING. Every use is `orWhere`/`orWhereHas`:
 * being named on a row is one more way in, not a replacement for the
 * org-hierarchy rule.
 */
trait AppliesNamedUserVisibility
{
    /**
     * @param  Builder<*>|Relation<*, *, *>  $query
     * @param  list<string>  $specs  each either a local column (`owner_id`) or
     *                               a `relation.column` pair (`participants.user_id`)
     */
    private function orNamedUserVisibility(Builder|Relation $query, array $specs, ?User $user): void
    {
        if ($user === null) {
            return;
        }

        foreach ($specs as $spec) {
            if (str_contains($spec, '.')) {
                [$relation, $column] = explode('.', $spec, 2);
                $query->orWhereHas($relation, fn (Builder $q) => $q->where($column, $user->getKey()));

                continue;
            }

            $query->orWhere($spec, $user->getKey());
        }
    }
}
