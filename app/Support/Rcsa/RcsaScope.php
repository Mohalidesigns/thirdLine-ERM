<?php

namespace App\Support\Rcsa;

use App\Models\BusinessUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

/**
 * §11's scoping rule, in one place: "a risk champion in Retail Operations must
 * not be able to read Treasury's assessment, or export it."
 *
 * THE WHOLE MODULE ASKS THIS CLASS AND NOTHING ELSE. P1 through P6 each left a
 * seam — `reachable()` on three policies, `reachableUnitIds()` on the export
 * service, an unscoped `lines()` on the dashboard — and every one of them now
 * calls `unitIdsFor()`. That is the point of having deferred it: scoping
 * implemented five times is scoping that disagrees with itself five ways, and
 * the disagreements are invisible until somebody exports what they cannot see
 * on screen.
 *
 * NULL MEANS "EVERY UNIT", AND ONLY A PERMISSION PRODUCES IT.
 * `rcsa_scope.all_units` is the Head of ORM, the CRO and Internal Audit —
 * §11's "read-only, full estate". A user with no assignments and no permission
 * gets an EMPTY LIST, not null. Those two are the same query shape and
 * opposite meanings, and conflating them is the classic way an RBAC system
 * fails open on exactly the accounts nobody configured.
 *
 * AN EMPTY LIST HAS TO REACH THE SCREEN AS A SENTENCE. `describe()` exists so
 * that a user assigned to nothing reads "you are not assigned to any business
 * unit" rather than an empty table, which is indistinguishable from "the bank
 * has no risks" and gets reported as a bug every time.
 *
 * DESCENDANTS ARE EXPANDED ON READ, NOT STORED. An assignment says "Retail, and
 * everything under it"; the set of things under Retail changes whenever
 * somebody adds a branch. Storing the expansion would go stale silently, which
 * on an authorisation boundary means a new branch is either invisible to its
 * own head or visible to everybody.
 */
class RcsaScope
{
    /** Holding this means the whole estate. */
    public const ALL_UNITS = 'rcsa_scope.all_units';

    /**
     * The business units this user may see, or null for all of them.
     *
     * @return list<int>|null
     */
    public function unitIdsFor(?User $user): ?array
    {
        if ($user === null) {
            // No user: a queue worker, the scheduler, a console command. These
            // run as the system and are already bounded by TenantContext; a
            // scheduled reminder that could only see one unit's overdue plans
            // would be worse than useless.
            return null;
        }

        if ($user->can(self::ALL_UNITS)) {
            return null;
        }

        return $this->expand($this->assignmentsFor($user), (int) $user->organization_id);
    }

    /**
     * Whether this user is bounded at all — used by screens to explain an
     * empty table rather than leaving the user to guess.
     */
    public function isBounded(?User $user): bool
    {
        return $this->unitIdsFor($user) !== null;
    }

    /**
     * A sentence for a screen that has come back empty.
     */
    public function describe(?User $user): ?string
    {
        $units = $this->unitIdsFor($user);

        if ($units === null) {
            return null;
        }

        if ($units === []) {
            return 'You are not assigned to any business unit, so there is nothing here to show you. '
                .'Ask an administrator to assign you to the units you are responsible for.';
        }

        return null;
    }

    /**
     * Constrain a query whose table carries `business_unit_id`.
     *
     * TAKES A RELATION AS WELL AS A BUILDER. Half the call sites start from a
     * model — `$cycle->assessments()` — and a `HasMany` is not an
     * `Eloquent\Builder`, so a signature accepting only the latter forces every
     * such caller to remember `->getQuery()`. A scoping helper somebody has to
     * remember something about is one somebody will forget.
     *
     * @template TQuery of Builder|Relation
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function apply(Builder|Relation $query, ?User $user, string $column = 'business_unit_id'): Builder|Relation
    {
        $units = $this->unitIdsFor($user);

        if ($units === null) {
            return $query;
        }

        // whereIn with an empty array is `where 0 = 1` in Laravel, which is
        // exactly right: no assignments means nothing, not everything.
        return $query->whereIn($column, $units);
    }

    /**
     * Constrain a query that reaches its unit through a relation.
     *
     * @template TQuery of Builder|Relation
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function applyThrough(
        Builder|Relation $query,
        ?User $user,
        string $relation,
        string $column = 'business_unit_id',
    ): Builder|Relation {
        $units = $this->unitIdsFor($user);

        if ($units === null) {
            return $query;
        }

        return $query->whereHas($relation, fn ($q) => $q->whereIn($column, $units));
    }

    /**
     * Whether this user may see one particular unit.
     */
    public function reaches(?User $user, int|string|null $unitId): bool
    {
        if ($unitId === null) {
            // A record with no unit — a cycle, a methodology — is not
            // unit-scoped and is not narrowed by this.
            return true;
        }

        $units = $this->unitIdsFor($user);

        return $units === null || in_array((int) $unitId, $units, true);
    }

    /* ------------------------------------------------------------------ */
    /*  Internals */
    /* ------------------------------------------------------------------ */

    /**
     * The rows of `business_unit_user` for this user.
     *
     * @return array<int, bool> unit id => includes descendants
     */
    private function assignmentsFor(User $user): array
    {
        return DB::table('business_unit_user')
            ->where('user_id', $user->id)
            ->pluck('includes_descendants', 'business_unit_id')
            ->map(fn ($value) => (bool) $value)
            ->all();
    }

    /**
     * Walk each assignment down the tree.
     *
     * ONE QUERY FOR THE WHOLE TENANT'S TREE, then the walk in PHP. A recursive
     * CTE would be one query too, but `WITH RECURSIVE` is spelled differently
     * enough across MySQL and SQLite that the version which only runs on the
     * production driver is the version no test can hold — the same argument
     * P6's dashboards made. A business-unit tree is tens of rows.
     *
     * @param  array<int, bool>  $assignments
     * @return list<int>
     */
    private function expand(array $assignments, int $organizationId): array
    {
        if ($assignments === []) {
            return [];
        }

        $needsTree = in_array(true, $assignments, true);

        if (! $needsTree) {
            return array_values(array_map('intval', array_keys($assignments)));
        }

        $children = [];

        foreach (
            BusinessUnit::query()
                ->withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->get(['id', 'parent_id']) as $unit
        ) {
            $children[(int) ($unit->parent_id ?? 0)][] = (int) $unit->id;
        }

        $seen = [];
        $queue = [];

        foreach ($assignments as $unitId => $includesDescendants) {
            $unitId = (int) $unitId;
            $seen[$unitId] = true;

            if ($includesDescendants) {
                $queue[] = $unitId;
            }
        }

        // Breadth-first, guarded by $seen: a tree with a cycle in it — which a
        // bad import can produce — would otherwise spin here for ever.
        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($children[$current] ?? [] as $child) {
                if (! isset($seen[$child])) {
                    $seen[$child] = true;
                    $queue[] = $child;
                }
            }
        }

        return array_values(array_map('intval', array_keys($seen)));
    }
}
