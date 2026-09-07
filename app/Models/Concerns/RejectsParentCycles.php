<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Refuses a self-referential parent that would close a loop in the tree.
 *
 * A `parent_id` that names the row itself, or any row already beneath it,
 * turns the tree into a ring. Nothing downstream survives that: the graph
 * projection walks the same edge (ObjectSourceMap declares `parent` for the
 * typed models using this trait, and `objects.parent_id` is the copy of it the
 * walk actually reads), Entity::refreshHierarchyPath() cascades down it, and
 * the recursive `children` / `ancestors` relations eager-load along it. A ring
 * makes each of those run until the process is out of memory — after the
 * offending save has already committed, so the row survives to take the next
 * request down as well.
 *
 * WHY ON THE MODEL AND NOT IN A FORM REQUEST. The tree tables have more than
 * one writer and, for `business_units`, no HTTP writer at all today — units
 * arrive from seeders, from the entity unification pass and from imports. A
 * rule in the one Form Request that happens to exist leaves the other writers
 * free to write the row that kills the process, and the next writer added
 * inherits nothing. UpdateEntityRequest keeps its equivalent rule so the user
 * gets a field error next to the input rather than a 500; this is the floor
 * beneath it, and the two agree.
 *
 * The guards downstream are still worth having — see the $visited set in
 * ObjectSyncService::materialisePath() — because rows written before this
 * trait existed, or by a direct UPDATE, are not covered by it.
 */
trait RejectsParentCycles
{
    public static function bootRejectsParentCycles(): void
    {
        // `self` rather than Model: inside a trait it resolves to the class
        // using it, so the call below is statically checkable.
        static::saving(function (self $model): void {
            $model->assertParentIsNotACycle();
        });
    }

    /**
     * The self-referential column. Overridden by models that spell it
     * differently — Risk's is `parent_risk_id`.
     */
    public function parentCycleColumn(): string
    {
        return 'parent_id';
    }

    /**
     * What to call this thing in the error the user reads.
     */
    public function parentCycleSubject(): string
    {
        return Str::lower(Str::headline(class_basename(static::class)));
    }

    /**
     * @throws ValidationException when the proposed parent is this row or one
     *                             of its descendants
     */
    public function assertParentIsNotACycle(): void
    {
        $column = $this->parentCycleColumn();

        // Only when the parent is actually being written. Every other save on
        // these tables — a rename, a status change, the graph stamping node_id
        // back — costs nothing.
        if (! $this->isDirty($column)) {
            return;
        }

        $proposed = $this->getAttribute($column);

        if ($proposed === null || $proposed === '') {
            return;
        }

        $key = $this->getKey();

        // A row that does not exist yet has no descendants to be moved under.
        if ($key === null) {
            return;
        }

        $subject = $this->parentCycleSubject();

        if ((string) $proposed === (string) $key) {
            throw ValidationException::withMessages([
                $column => "A {$subject} cannot be its own parent.",
            ]);
        }

        // Walk up from the PROPOSED parent looking for this row. $seen bounds
        // the walk, so data that is already cyclic — written before this trait
        // existed, or by a direct UPDATE — makes the check terminate instead
        // of joining the loop it was meant to detect. Soft-deleted ancestors
        // are followed on purpose: a trashed row still carries a parent_id and
        // the ring it closes is still real in the table.
        $seen = [];
        $cursor = $proposed;

        while ($cursor !== null && ! isset($seen[(string) $cursor])) {
            if ((string) $cursor === (string) $key) {
                throw ValidationException::withMessages([
                    $column => "A {$subject} cannot be moved under one of its own descendants.",
                ]);
            }

            $seen[(string) $cursor] = true;

            $cursor = DB::table($this->getTable())
                ->where($this->getKeyName(), $cursor)
                ->value($column);
        }
    }
}
