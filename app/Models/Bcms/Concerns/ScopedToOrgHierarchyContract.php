<?php

namespace App\Models\Bcms\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The real (non-duck-typed) half of "anchor" in ADR 0017 §2.
 *
 * `ScopedToOrgHierarchy` is a trait, and a trait gives static analysis no way
 * to know a class has it — `method_exists($this, 'scopeVisibleTo')` is true at
 * runtime and invisible to PHPStan, which is exactly backwards for a security
 * boundary: it means the compiler cannot tell an anchor from a derived model
 * either. Every model using `ScopedToOrgHierarchy` also declares
 * `implements ScopedToOrgHierarchyContract`, and `BindsToVisibleRecord`
 * narrows on `instanceof` rather than `method_exists()`, so the call it makes
 * — `$anchor->scopeVisibleTo($query, $user)`, a perfectly ordinary method call
 * rather than the magic `$query->visibleTo()` scope forwarding — is one
 * PHPStan can verify exists on every class it is ever reached for.
 */
interface ScopedToOrgHierarchyContract
{
    public function orgScopeColumn(): string;

    /**
     * A PER-CALL template (`TModel`), not `Builder<static>`: every call site
     * reaches this through an `instanceof`-narrowed variable of type
     * `Model&ScopedToOrgHierarchyContract`, which is not a concrete class
     * PHPStan can bind `static` to, and `Builder`'s own template parameter is
     * invariant — `Builder<Model>` does not accept `Builder<Plan>` merely
     * because `Plan extends Model`. Binding a fresh `TModel` per call instead
     * ties the query's type to itself, which is what every call site actually
     * has: the `Builder` belongs to the same model class either way, `$this`
     * or `$anchor` is only there to prove the method exists on it.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder;

    public function isVisibleTo(?User $user): bool;
}
