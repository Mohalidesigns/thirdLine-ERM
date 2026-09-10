<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Concerns\ScopedToGraph;
use App\Support\Authorization\GraphScope;
use Illuminate\Database\Eloquent\Model;

/**
 * Node scoping for single-record reads.
 *
 * Route-model binding resolves a record by primary key through the tenancy
 * global scope and nothing else, so before this trait existed a branch-pinned
 * user could type the id of a sibling branch's risk into the address bar and
 * get the whole record — the index they were shown had been filtered, the
 * detail page had not.
 *
 * 404, NOT 403. That is the convention TenancyIsolationTest already pins for
 * cross-tenant reads, and it is the right one here for the same reason: a 403
 * confirms the record exists. "There is no RK-0042" and "there is an RK-0042
 * and you may not read it" are different disclosures, and in a multi-subsidiary
 * banking group the second one is enough to tell a retail branch manager that
 * treasury has forty-two open risks.
 *
 * Deliberately checked with the SAME query the index uses rather than with a
 * re-implementation of the rule in PHP. If GraphScope changes — the unassigned
 * setting, the fail-closed branch, the path format — show() and index() move
 * together instead of drifting apart, which is exactly how a record ends up
 * hidden from a list and readable by URL.
 */
trait EnforcesNodeScope
{
    /**
     * Abort with 404 unless the caller's node scope admits this record.
     *
     * A no-op for models that do not hang off the graph, and for callers who
     * are not subtree-limited — the overwhelming majority of requests, which
     * therefore pay nothing for this.
     */
    protected function abortUnlessNodeVisible(?Model $record): void
    {
        if ($record === null) {
            abort(404);
        }

        if (! in_array(ScopedToGraph::class, class_uses_recursive($record), true)) {
            return;
        }

        if (! GraphScope::isSubtreeLimited(auth()->user())) {
            return;
        }

        abort_unless(
            $record->newQuery()->whereKey($record->getKey())->visibleTo()->exists(),
            404
        );
    }

    /**
     * The same check for a record that has no node of its own and inherits one.
     *
     * A treatment plan, an assessment and a control test all sit under a scoped
     * parent and cascade-delete with it. Their grids are scoped through that
     * parent, so their detail pages have to be as well — a record filtered out
     * of a list but readable by typing its id is the specific failure this whole
     * change exists to remove.
     *
     * @param  string  $relation  a relation whose target uses ScopedToGraph
     */
    protected function abortUnlessNodeVisibleThrough(?Model $record, string $relation): void
    {
        if ($record === null) {
            abort(404);
        }

        if (! GraphScope::isSubtreeLimited(auth()->user())) {
            return;
        }

        abort_unless(
            GraphScope::applyThrough($record->newQuery()->whereKey($record->getKey()), $relation)->exists(),
            404
        );
    }
}
