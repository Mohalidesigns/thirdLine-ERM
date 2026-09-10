<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Foundation for node-scoped authorization (WP-00 TASK 3).
 *
 * entities is the organisational graph — risks, controls, issues, loss events
 * and KRIs all hang off it via entity_id — but it only stored parent_id, so
 * "everything at or below this node" needed a recursive query on every read.
 * A materialised path turns that into an indexed prefix match.
 *
 * Path format is /1/7/23/ — leading and trailing delimiters included, so
 * LIKE '/1/7/%' cannot accidentally match entity 70 or 71.
 *
 * users.scope_entity_id names the subtree a user is confined to. NULL means
 * organization-wide, which is the existing behaviour and therefore the default
 * for every current row.
 *
 * Additive only: two nullable columns, no drops, no retypes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('entities', 'hierarchy_path')) {
            Schema::table('entities', function (Blueprint $table) {
                $table->string('hierarchy_path', 500)->nullable()->after('level');
                $table->index('hierarchy_path');
            });
        }

        if (! Schema::hasColumn('users', 'scope_entity_id')) {
            Schema::table('users', function (Blueprint $table) {
                // restrictOnDelete, not nullOnDelete: this column is an
                // authorization boundary. Nulling it when the node is removed
                // would silently promote a subtree-limited user to org-wide
                // visibility — a fail-open. Deleting a node that users are
                // pinned to must be an explicit re-assignment instead.
                $table->foreignId('scope_entity_id')->nullable()->after('business_unit_id')
                    ->constrained('entities')->restrictOnDelete();
            });
        }

        $this->backfillEntityPaths();
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'scope_entity_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropConstrainedForeignId('scope_entity_id');
            });
        }

        if (Schema::hasColumn('entities', 'hierarchy_path')) {
            Schema::table('entities', function (Blueprint $table) {
                $table->dropIndex(['hierarchy_path']);
                $table->dropColumn('hierarchy_path');
            });
        }
    }

    /**
     * Walk the tree breadth-first from the roots, so each row's parent path is
     * already written by the time the child is reached.
     */
    private function backfillEntityPaths(): void
    {
        $paths = [];
        $pending = DB::table('entities')->select('id', 'parent_id')->get()
            ->mapWithKeys(fn ($row) => [(int) $row->id => $row->parent_id === null ? null : (int) $row->parent_id])
            ->all();

        $guard = 0;

        while ($pending !== [] && $guard++ < 100) {
            $progressed = false;

            foreach ($pending as $id => $parentId) {
                if ($parentId === null) {
                    $paths[$id] = "/{$id}/";
                } elseif (isset($paths[$parentId])) {
                    $paths[$id] = $paths[$parentId].$id.'/';
                } else {
                    continue;
                }

                unset($pending[$id]);
                $progressed = true;
            }

            // A cycle, or a parent pointing outside the table, would otherwise
            // spin forever. Treat the remainder as roots rather than leaving
            // them with a NULL path, which would make them invisible to every
            // subtree-limited user.
            if (! $progressed) {
                foreach ($pending as $id => $parentId) {
                    $paths[$id] = "/{$id}/";
                }
                break;
            }
        }

        foreach ($paths as $id => $path) {
            DB::table('entities')->where('id', $id)->update(['hierarchy_path' => $path]);
        }
    }
};
