<?php

use App\Support\MorphTypes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Rewrite polymorphic type values that were stored as a fully-qualified class
 * name to the alias in App\Support\MorphTypes::map().
 *
 * WHY. 26a7697 (ERM remodel WP-00..06, 2026-08-10) registered
 * Relation::enforceMorphMap(MorphTypes::map()). From that commit Eloquent, and
 * therefore Spatie, queries `model_type = 'user'`. Nothing rewrote the rows
 * written before the alias existed, so on a server seeded earlier
 * `model_has_roles` still holds `model_type = 'App\Models\User'`: the roles are
 * all there, no query can see them, `getRoleNames()` is `[]` for every user and
 * every login 403s on `/risk/dashboard`. A database seeded AFTER the map never
 * has the problem, which is why no test caught it.
 *
 * WHAT IT TOUCHES. Only a value that is a class in the map, compared the way
 * the database compares (case-insensitively, so 'app\models\user' is rewritten
 * too: PHP class names are case-insensitive and Spatie cannot see the variant
 * either), only in the columns listed in targets() — every one of them is a
 * column Eloquent resolves through the enforced morph map. A value that is not
 * a map class (an alias, a bare basename, a class that has no alias) is left
 * alone; this migration normalises, it does not guess.
 *
 * COLLISIONS. model_has_roles and model_has_permissions are keyed
 * (role_id|permission_id, model_id, model_type), and three BCMS tables carry a
 * unique key that includes the type column. If the alias row already exists for
 * the same key, an UPDATE would violate the key, so the stale FQCN row is
 * DELETED instead: the alias row is the one the application reads and writes,
 * and it already says the same thing. A delete from a table that carries a
 * payload is logged with the row first.
 *
 * WHAT IT DELIBERATELY DOES NOT TOUCH (see EXCLUDED, which the guard test reads):
 *
 *  - risk_audit_trail.entity_type. Append-only (BEFORE UPDATE / BEFORE DELETE
 *    triggers, 2026_08_09_100004) and hash-chained with entity_type inside the
 *    digest. Rewriting it means disabling the triggers and re-sealing every
 *    chain, which makes this migration's edit indistinguishable from tampering.
 *    Reads already accept every spelling: MorphTypes::spellingsFor().
 *  - tp_audit_logs.auditable_type and bcms_audit_logs.auditable_type. These are
 *    written as the literal FQCN (`static::class`) BY DESIGN, today and
 *    forever, and read back with a plain hasMany() on `static::class` — not
 *    through morphTo — so an alias would orphan every row. tp_audit_logs is
 *    also hash-chained (ADR 0025).
 *  - Columns that look like morphs but are local kind registries, not the global
 *    map: bcms_evidence.owner_type, tp_documents.owner_type,
 *    tp_screening_checks.subject_type, tp_waivers.waivable_type.
 *
 * Idempotent: a second run finds no FQCN value and changes nothing. Portable:
 * query-builder only, and collision handling is row by row because MySQL 8
 * refuses a DELETE whose subquery reads the same table (error 1093).
 */
return new class extends Migration
{
    /**
     * Columns that are polymorphic by design but must not be rewritten, with
     * the reason. tests/Feature/Migration/RewriteLegacyMorphClassNamesTest
     * reads this: a morph-shaped column that is neither here nor in targets()
     * fails the build, so a new one cannot be added without a decision.
     *
     * @var array<string, string>
     */
    public const EXCLUDED = [
        'risk_audit_trail.entity_type' => 'append-only (triggers) and hash-chained; reads accept every spelling via MorphTypes::spellingsFor()',
        'tp_audit_logs.auditable_type' => 'hash-chained (ADR 0025); written as the literal FQCN by TprmAuditable and read with hasMany()',
        'bcms_audit_logs.auditable_type' => 'written as the literal FQCN by BcmsAuditable and read with hasMany(); an alias would orphan the rows',
        'bcms_evidence.owner_type' => 'local kind registry (Evidence::ownerModels), not the global morph map',
        'tp_documents.owner_type' => 'local kind registry (Document::ownerModels), not the global morph map',
        'tp_screening_checks.subject_type' => 'local kind registry (ScreeningCheck constants), not the global morph map',
        'tp_waivers.waivable_type' => 'local kind registry (Waiver), not the global morph map',
        'tp_audit_logs.actor_type' => "a short actor-kind literal ('user', 'system' or 'portal'), never a class name",
        'tp_assessment_messages.author_type' => 'a short role literal (internal or vendor side), never a class name',
    ];

    public function up(): void
    {
        $classToAlias = array_flip(MorphTypes::map());

        foreach ($this->targets() as $target) {
            $this->rewrite($target['table'], $target['column'], $target['keys'], $classToAlias);
        }

        // The grant cache is keyed by role and permission, not by user, so it
        // is not stale in the way a role-assignment cache would be, but a
        // deploy that has just changed what every user resolves to should not
        // depend on that. A cache store that is down must not fail a migration
        // whose data work is already done and is safe to re-run.
        try {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        } catch (Throwable $exception) {
            logger()->warning('Morph rewrite: could not clear the permission cache', [
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Not reversible. Once rewritten, nothing distinguishes a row that was
     * legacy from one the application wrote as an alias, so there is nothing
     * principled to restore — and restoring the FQCN is the defect itself.
     */
    public function down(): void
    {
        // Intentionally empty.
    }

    /**
     * Every column that Eloquent resolves through the enforced morph map.
     *
     * `keys` are the OTHER columns of a unique key that includes the type
     * column; empty when the table has no such key.
     *
     * @return list<array{table: string, column: string, keys: list<string>}>
     */
    public function targets(): array
    {
        $names = config('permission.table_names');
        $columns = config('permission.column_names');
        $modelKey = $columns['model_morph_key'] ?? 'model_id';
        $teamKey = config('permission.teams') ? [$columns['team_foreign_key'] ?? 'team_id'] : [];

        return [
            // The reported defect. Spatie's pivots; PK includes model_type.
            ['table' => $names['model_has_roles'] ?? 'model_has_roles', 'column' => 'model_type',
                'keys' => [...$teamKey, $columns['role_pivot_key'] ?? 'role_id', $modelKey]],
            ['table' => $names['model_has_permissions'] ?? 'model_has_permissions', 'column' => 'model_type',
                'keys' => [...$teamKey, $columns['permission_pivot_key'] ?? 'permission_id', $modelKey]],

            // Sanctum: `tokenable_type` is morphs('tokenable'); User::createToken stores the morph class.
            ['table' => 'personal_access_tokens', 'column' => 'tokenable_type', 'keys' => []],

            // The tables MorphTypes names in its docblock. 2026_08_10_110003
            // normalised these once; they are re-swept because that ran at one
            // moment and a server restored from an older dump skips it.
            ['table' => 'approval_requests', 'column' => 'entity_type', 'keys' => []],
            ['table' => 'workflow_instances', 'column' => 'entity_type', 'keys' => []],
            ['table' => 'workflow_definitions', 'column' => 'entity_type', 'keys' => []],

            // Object graph: the alias of the source model (ObjectSyncService).
            ['table' => 'objects', 'column' => 'source_model_type', 'keys' => []],
            ['table' => 'object_merge_candidates', 'column' => 'left_source_type', 'keys' => []],
            ['table' => 'object_merge_candidates', 'column' => 'right_source_type', 'keys' => []],

            // morphTo() subjects, written through getMorphClass().
            ['table' => 'job_runs', 'column' => 'subject_type', 'keys' => []],
            ['table' => 'llm_usage_events', 'column' => 'subject_type', 'keys' => []],

            // BCMS morphs over the enforced map; each has a unique key that
            // includes the type column, so a collision is possible in principle.
            ['table' => 'bcms_dependencies', 'column' => 'dependable_type', 'keys' => ['assessment_id', 'dependable_id']],
            ['table' => 'bcms_programme_scope', 'column' => 'scopable_type', 'keys' => ['programme_id', 'scopable_id']],
            ['table' => 'bcms_raci_assignments', 'column' => 'assignable_type', 'keys' => ['assignable_id', 'user_id', 'raci_role']],
        ];
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string, string>  $classToAlias
     */
    private function rewrite(string $table, string $column, array $keys, array $classToAlias): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        // Loop over the MAP, not over what the column happens to hold.
        //
        // Reading the distinct values back and checking each against the map's
        // exact spelling looks equivalent and is not: the collation is
        // case-insensitive (utf8mb4_unicode_ci on both engines), so
        // `DISTINCT ... WHERE type IN ('App\Models\User')` may return the
        // variant 'app\models\user' for every matching row, and the exact-
        // spelling check then skips the lot while the migration is recorded as
        // run. Which spelling comes back depends on index order.
        //
        // Matching is therefore case-insensitive on purpose. PHP class names
        // are, and Spatie cannot see a case variant either, so rewriting one
        // to the alias is right.
        foreach ($classToAlias as $class => $alias) {
            if (! DB::table($table)->where($column, $class)->exists()) {
                continue;
            }

            DB::transaction(function () use ($table, $column, $keys, $class, $alias): void {
                if ($keys !== []) {
                    $this->deleteRowsShadowedByAlias($table, $column, $keys, $class, $alias);
                }

                DB::table($table)->where($column, $class)->update([$column => $alias]);
            });
        }
    }

    /**
     * Delete each FQCN row whose alias twin already exists, so the UPDATE that
     * follows cannot violate the key.
     *
     * A table that carries more than its key and type column (the BCMS tables)
     * loses that row's payload, so the row is logged before it goes: a delete
     * from a table with data in it is never silent. The Spatie pivots hold
     * nothing but the key, so there is nothing to record.
     *
     * @param  list<string>  $keys
     */
    private function deleteRowsShadowedByAlias(string $table, string $column, array $keys, string $value, string $alias): void
    {
        $hasPayload = array_diff(Schema::getColumnListing($table), $keys, [$column]) !== [];

        foreach (DB::table($table)->where($column, $value)->get() as $row) {
            $twin = DB::table($table)->where($column, $alias);
            $stale = DB::table($table)->where($column, $value);

            foreach ($keys as $key) {
                $twin->where($key, $row->{$key});
                $stale->where($key, $row->{$key});
            }

            if (! $twin->exists()) {
                continue;
            }

            if ($hasPayload) {
                logger()->warning('Morph rewrite: deleting a row whose alias twin already exists (colliding key)', [
                    'table' => $table,
                    'column' => $column,
                    'row' => (array) $row,
                ]);
            }

            $stale->delete();
        }
    }
};
