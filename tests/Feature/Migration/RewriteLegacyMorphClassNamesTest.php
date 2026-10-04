<?php

namespace Tests\Feature\Migration;

use App\Models\Organization;
use App\Models\Risk;
use App\Models\User;
use App\Support\MorphTypes;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Every user on a server seeded before the morph map lost their roles.
 *
 * 26a7697 registered Relation::enforceMorphMap(), so Spatie began querying
 * `model_type = 'user'`, and nothing rewrote the `'App\Models\User'` rows that
 * already existed. A database seeded after the map never shows it, which is why
 * no other test noticed. This test builds the production state by hand: legacy
 * rows, written raw, exactly as the old code stored them.
 *
 * The second half is a guard. A new morph-shaped column has to be either added
 * to the migration's targets() or excluded WITH A REASON; it cannot simply
 * appear and be forgotten.
 */
class RewriteLegacyMorphClassNamesTest extends TestCase
{
    use RefreshDatabase;

    private const LEGACY_USER = 'App\\Models\\User';

    private const MIGRATION = 'database/migrations/2026_10_03_100001_rewrite_legacy_morph_class_names_to_aliases.php';

    private function migration(): Migration
    {
        return require base_path(self::MIGRATION);
    }

    /**
     * The migration is an anonymous class, so its methods are reached by name
     * rather than through a type static analysis can see.
     */
    private function runMigration(Migration $migration): void
    {
        call_user_func([$migration, 'up']);
    }

    /**
     * @return list<array{table: string, column: string, keys: list<string>}>
     */
    private function targets(Migration $migration): array
    {
        return call_user_func([$migration, 'targets']);
    }

    /**
     * @return array<string, string>
     */
    private function excluded(Migration $migration): array
    {
        return constant($migration::class.'::EXCLUDED');
    }

    #[Test]
    public function a_user_whose_role_rows_carry_the_legacy_class_name_has_no_roles_until_the_migration_runs(): void
    {
        [$user, $role] = $this->legacyUserWithRole();

        $this->assertSame([], $user->fresh()->getRoleNames()->all(), 'The defect: the role is assigned and invisible.');
        $this->assertFalse($user->fresh()->hasRole($role->name));
        $this->assertFalse($user->fresh()->can('dashboard.view'));

        $this->runMigration($this->migration());

        $fresh = $user->fresh();
        $this->assertSame([$role->name], $fresh->getRoleNames()->all());
        $this->assertTrue($fresh->hasRole($role->name));
        $this->assertTrue($fresh->can('dashboard.view'));
        $this->assertSame(0, DB::table('model_has_roles')->where('model_type', self::LEGACY_USER)->count());
    }

    #[Test]
    public function a_legacy_direct_permission_resolves_after_the_migration(): void
    {
        $user = $this->makeUser();
        $permission = Permission::findOrCreate('dashboard.view');

        DB::table('model_has_permissions')->insert([
            'permission_id' => $permission->id,
            'model_id' => $user->id,
            'model_type' => self::LEGACY_USER,
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse($user->fresh()->hasDirectPermission('dashboard.view'));

        $this->runMigration($this->migration());

        $this->assertTrue($user->fresh()->hasDirectPermission('dashboard.view'));
        $this->assertTrue($user->fresh()->can('dashboard.view'));
    }

    #[Test]
    public function a_legacy_row_that_collides_with_its_alias_twin_is_deleted_not_updated(): void
    {
        [$user, $role] = $this->legacyUserWithRole();

        // The user was re-assigned after the map landed, so BOTH rows exist and
        // an UPDATE of the legacy one would violate the primary key.
        DB::table('model_has_roles')->insert([
            'role_id' => $role->id,
            'model_id' => $user->id,
            'model_type' => 'user',
        ]);

        $this->runMigration($this->migration());

        $rows = DB::table('model_has_roles')->where('role_id', $role->id)->where('model_id', $user->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('user', $rows->first()->model_type);
    }

    #[Test]
    public function every_target_with_a_unique_key_is_deduplicated_not_failed(): void
    {
        Schema::withoutForeignKeyConstraints(function (): void {
            $migration = $this->migration();
            $checked = 0;

            foreach ($this->targets($migration) as $target) {
                if ($target['keys'] === []) {
                    continue;
                }

                $this->insertRow($target['table'], [$target['column'] => self::LEGACY_USER]);
                $this->insertRow($target['table'], [$target['column'] => 'user']);
                $checked++;
            }

            $this->assertGreaterThanOrEqual(5, $checked, 'Spatie x2 and the three BCMS keyed tables.');

            $this->runMigration($migration);

            foreach ($this->targets($migration) as $target) {
                if ($target['keys'] === []) {
                    continue;
                }

                $this->assertSame(1, DB::table($target['table'])->count(), $target['table'].' keeps exactly the alias row.');
                $this->assertSame('user', DB::table($target['table'])->value($target['column']), $target['table']);
            }
        });
    }

    #[Test]
    public function every_target_column_is_rewritten_and_a_second_run_changes_nothing(): void
    {
        Schema::withoutForeignKeyConstraints(function (): void {
            $migration = $this->migration();

            foreach ($this->targets($migration) as $target) {
                $this->insertRow($target['table'], [$target['column'] => self::LEGACY_USER]);
            }

            $this->runMigration($migration);

            foreach ($this->targets($migration) as $target) {
                $this->assertSame(
                    0,
                    DB::table($target['table'])->where($target['column'], self::LEGACY_USER)->count(),
                    $target['table'].'.'.$target['column'].' still holds the legacy class name.'
                );
                $this->assertGreaterThan(
                    0,
                    DB::table($target['table'])->where($target['column'], 'user')->count(),
                    $target['table'].'.'.$target['column'].' was not rewritten to the alias.'
                );
            }

            $before = $this->snapshot($migration);
            $this->runMigration($migration);
            $this->assertSame($before, $this->snapshot($migration), 'A second up() must be a no-op.');
        });
    }

    #[Test]
    public function only_values_that_are_map_classes_are_touched(): void
    {
        Schema::withoutForeignKeyConstraints(function (): void {
            // An alias, a bare basename, and a class that has no alias: none is ours.
            foreach (['user', 'Risk', 'App\\Models\\NoSuchModel'] as $value) {
                $this->insertRow('job_runs', ['subject_type' => $value, 'subject_id' => 1]);
            }
            $this->insertRow('job_runs', ['subject_type' => Risk::class, 'subject_id' => 2]);

            $this->runMigration($this->migration());

            $this->assertEqualsCanonicalizing(
                ['user', 'Risk', 'App\\Models\\NoSuchModel', 'risk'],
                DB::table('job_runs')->pluck('subject_type')->all()
            );
        });
    }

    #[Test]
    public function the_sealed_and_by_design_fqcn_tables_are_left_exactly_as_written(): void
    {
        Schema::withoutForeignKeyConstraints(function (): void {
            $migration = $this->migration();
            $tables = [
                'risk_audit_trail' => 'entity_type',
                'tp_audit_logs' => 'auditable_type',
                'bcms_audit_logs' => 'auditable_type',
            ];

            foreach ($tables as $table => $column) {
                $this->insertRow($table, [$column => self::LEGACY_USER]);
            }

            $before = collect($tables)->map(fn ($column, $table) => DB::table($table)->get()->toJson())->all();

            $this->runMigration($migration);

            foreach ($tables as $table => $column) {
                $this->assertSame($before[$table], DB::table($table)->get()->toJson(), $table.' must not be rewritten.');
                $this->assertArrayHasKey($table.'.'.$column, $this->excluded($migration));
            }
        });
    }

    #[Test]
    public function a_legacy_class_name_still_resolves_to_its_model_under_the_enforced_morph_map(): void
    {
        // Why the excluded audit tables are safe to leave: where anything does
        // go through morphTo, an FQCN falls back to itself rather than throwing.
        $this->assertSame(User::class, (new User)->getActualClassNameForMorph(self::LEGACY_USER));
        $this->assertSame(User::class, (new User)->getActualClassNameForMorph('user'));
    }

    #[Test]
    public function after_the_migration_no_morph_column_holds_a_class_name_from_the_map(): void
    {
        $this->legacyUserWithRole();

        $migration = $this->migration();
        $this->runMigration($migration);

        $offenders = [];
        $classes = array_values(MorphTypes::map());

        foreach ($this->typeColumns() as $table => $columns) {
            foreach ($columns as $column) {
                if (isset($this->excluded($migration)[$table.'.'.$column])) {
                    continue;
                }

                $count = DB::table($table)->whereIn($column, $classes)->count();
                if ($count > 0) {
                    $offenders[] = "{$table}.{$column} ({$count} rows)";
                }
            }
        }

        $this->assertSame([], $offenders, 'Legacy class names survive in: '.implode(', ', $offenders));
    }

    #[Test]
    public function every_morph_shaped_column_in_the_schema_is_either_rewritten_or_excluded_with_a_reason(): void
    {
        $migration = $this->migration();

        $covered = collect($this->targets($migration))
            ->map(fn (array $target) => $target['table'].'.'.$target['column'])
            ->all();
        $excluded = array_keys($this->excluded($migration));

        $this->assertSame([], array_intersect($covered, $excluded), 'A column cannot be both rewritten and excluded.');

        $unaccounted = [];

        foreach ($this->typeColumns(morphShapedOnly: true) as $table => $columns) {
            foreach ($columns as $column) {
                $name = $table.'.'.$column;

                if (! in_array($name, $covered, true) && ! in_array($name, $excluded, true)) {
                    $unaccounted[] = $name;
                }
            }
        }

        $this->assertSame(
            [],
            $unaccounted,
            'A new `*_type` + `*_id` pair appeared. If Eloquent resolves it through the morph map add it to targets() in '
            .self::MIGRATION.'; otherwise add it to EXCLUDED with the reason.'
        );

        foreach ($this->excluded($migration) as $name => $reason) {
            $this->assertNotSame('', trim($reason), $name.' needs a reason.');
        }

        foreach ($covered as $name) {
            [$table, $column] = explode('.', $name);
            $this->assertTrue(Schema::hasColumn($table, $column), $name.' no longer exists; the target is stale.');
        }
    }

    /**
     * Collect every warning logged from now on.
     *
     * @return \Closure(): list<MessageLogged>
     */
    private function captureWarnings(): \Closure
    {
        $captured = [];

        Event::listen(MessageLogged::class, function (MessageLogged $log) use (&$captured): void {
            if ($log->level === 'warning') {
                $captured[] = $log;
            }
        });

        return function () use (&$captured): array {
            return $captured;
        };
    }

    /**
     * A user with a role granting dashboard.view, assigned the way the OLD code
     * stored it: a raw row, model_type the class name.
     *
     * @return array{0: User, 1: \Spatie\Permission\Contracts\Role}
     */
    private function legacyUserWithRole(): array
    {
        $user = $this->makeUser();
        $role = Role::findOrCreate('risk-officer');
        $role->givePermissionTo(Permission::findOrCreate('dashboard.view'));

        DB::table('model_has_roles')->insert([
            'role_id' => $role->id,
            'model_id' => $user->id,
            'model_type' => self::LEGACY_USER,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return [$user, $role];
    }

    private function makeUser(): User
    {
        $organization = Organization::create([
            'name' => 'Morph Test Bank',
            'short_name' => 'MTB',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        return User::create([
            'name' => 'Morph Test User',
            'email' => 'morph-'.uniqid().'@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $organization->id,
            'is_active' => true,
        ]);
    }

    /**
     * Every string `*_type` column, grouped by table. With $morphShapedOnly,
     * only those with a sibling `*_id` — the shape morphs() creates.
     *
     * @return array<string, list<string>>
     */
    private function typeColumns(bool $morphShapedOnly = false): array
    {
        // The schema does not change within a run, and reading every table's
        // columns is the slow part, so it is read once per process.
        static $schema = null;

        if ($schema === null) {
            $schema = [];

            foreach (Schema::getTables() as $table) {
                $columns = Schema::getColumns($table['name']);
                $names = array_column($columns, 'name');

                foreach ($columns as $column) {
                    $name = $column['name'];

                    if (str_ends_with($name, '_type')
                        && in_array($column['type_name'], ['varchar', 'char', 'text', 'string'], true)) {
                        $schema[$table['name']][$name] = in_array(substr($name, 0, -5).'_id', $names, true);
                    }
                }
            }
        }

        $found = [];

        foreach ($schema as $table => $columns) {
            foreach ($columns as $name => $hasSiblingId) {
                if (! $morphShapedOnly || $hasSiblingId) {
                    $found[$table][] = $name;
                }
            }
        }

        return $found;
    }

    /**
     * @return array<string, string>
     */
    private function snapshot(Migration $migration): array
    {
        $out = [];

        foreach ($this->targets($migration) as $target) {
            $rows = DB::table($target['table'])->get()->map(fn ($row) => json_encode((array) $row))->sort()->values();
            $out[$target['table']] = $rows->implode("\n");
        }

        return $out;
    }

    /**
     * Insert one row with every NOT NULL column that has no default filled in,
     * so a test can name only the columns it cares about. Foreign keys are
     * switched off by the caller; this fills columns, not relationships.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function insertRow(string $table, array $overrides): void
    {
        $row = [];

        foreach (Schema::getColumns($table) as $column) {
            $name = $column['name'];

            if (array_key_exists($name, $overrides)) {
                $row[$name] = $overrides[$name];

                continue;
            }

            if ($column['nullable'] || $column['default'] !== null || $column['auto_increment'] || ! empty($column['generation'])) {
                continue;
            }

            $row[$name] = $this->placeholder($column);
        }

        DB::table($table)->insert($row);
    }

    /**
     * @param  array<string, mixed>  $column
     */
    private function placeholder(array $column): mixed
    {
        $type = strtolower((string) $column['type_name']);

        return match (true) {
            // Unique per call: aggregate roots carry a unique uuid.
            $column['name'] === 'uuid' => (string) Str::uuid(),
            in_array($type, ['int', 'integer', 'bigint', 'smallint', 'mediumint', 'tinyint', 'decimal', 'float', 'double'], true) => 1,
            $type === 'enum' => preg_match("/enum\\('([^']*)'/i", (string) $column['type'], $m) ? $m[1] : 'x',
            $type === 'date' => '2026-01-01',
            in_array($type, ['datetime', 'timestamp'], true) => '2026-01-01 00:00:00',
            $type === 'time' => '00:00:00',
            // MariaDB reports json as longtext with a json_valid() CHECK, so a
            // text placeholder has to be valid JSON as well as valid text.
            in_array($type, ['text', 'mediumtext', 'longtext', 'json'], true) => '{}',
            default => 'x',
        };
    }

    #[Test]
    public function the_permission_cache_is_flushed_and_a_failing_cache_store_does_not_fail_the_migration(): void
    {
        $this->mock(PermissionRegistrar::class, function ($mock): void {
            $mock->shouldReceive('forgetCachedPermissions')->once()->andThrow(new \RuntimeException('cache store down'));
        });

        Schema::withoutForeignKeyConstraints(function (): void {
            $this->insertRow('job_runs', ['subject_type' => self::LEGACY_USER, 'subject_id' => 1]);
            $this->runMigration($this->migration());
        });

        $this->assertSame('user', DB::table('job_runs')->value('subject_type'), 'The data work survives a cache store that is down.');
    }

    #[Test]
    public function on_a_colliding_key_the_alias_row_payload_survives_not_the_legacy_one(): void
    {
        Schema::withoutForeignKeyConstraints(function (): void {
            $this->insertRow('bcms_dependencies', ['dependable_type' => self::LEGACY_USER, 'criticality' => 'legacy-payload']);
            $this->insertRow('bcms_dependencies', ['dependable_type' => 'user', 'criticality' => 'alias-payload']);

            $this->runMigration($this->migration());

            $this->assertSame(['alias-payload'], DB::table('bcms_dependencies')->pluck('criticality')->all());
            $this->assertSame(['user'], DB::table('bcms_dependencies')->pluck('dependable_type')->all());
        });
    }

    #[Test]
    public function a_second_run_and_a_run_on_an_already_correct_database_execute_no_write_at_all(): void
    {
        Schema::withoutForeignKeyConstraints(function (): void {
            $migration = $this->migration();
            foreach ($this->targets($migration) as $target) {
                $this->insertRow($target['table'], [$target['column'] => self::LEGACY_USER]);
            }
            $this->runMigration($migration);

            $writes = [];
            DB::listen(function ($query) use (&$writes): void {
                if (! preg_match('/^\s*select\b/i', $query->sql)) {
                    $writes[] = $query->sql;
                }
            });

            $this->runMigration($migration);

            $this->assertSame([], $writes, 'An already-correct database must see zero UPDATE/DELETE statements.');
        });
    }

    #[Test]
    public function a_case_variant_of_the_class_name_does_not_stop_the_real_rows_being_rewritten(): void
    {
        // The collation is case-insensitive, so a read-back of the distinct
        // values can return 'app\models\user' for ALL of them, and a check
        // against the map's exact spelling then skips the whole column while
        // the migration is still recorded as run. Which spelling comes back
        // depends on index order, so the variant is inserted FIRST.
        Schema::withoutForeignKeyConstraints(function (): void {
            $this->insertRow('job_runs', ['subject_type' => strtolower(self::LEGACY_USER), 'subject_id' => 1]);
            $this->insertRow('job_runs', ['subject_type' => self::LEGACY_USER, 'subject_id' => 2]);

            $this->runMigration($this->migration());

            $this->assertSame(['user', 'user'], DB::table('job_runs')->orderBy('subject_id')->pluck('subject_type')->all());
        });
    }

    #[Test]
    public function every_morph_alias_belongs_to_exactly_one_class(): void
    {
        // The migration flips the map. array_flip keeps the LAST alias for a
        // class that has two, while getMorphClass() returns the FIRST, so a
        // duplicate would rewrite rows to a value Eloquent never queries.
        $classes = array_values(MorphTypes::map());

        $this->assertSame(
            [],
            array_keys(array_filter(array_count_values($classes), fn (int $count) => $count > 1)),
            'Two aliases map to one class.'
        );
    }

    #[Test]
    public function a_collision_delete_on_a_table_with_a_payload_is_logged_with_the_row(): void
    {
        $warnings = $this->captureWarnings();

        Schema::withoutForeignKeyConstraints(function (): void {
            $this->insertRow('bcms_dependencies', ['dependable_type' => self::LEGACY_USER, 'criticality' => 'legacy-payload']);
            $this->insertRow('bcms_dependencies', ['dependable_type' => 'user', 'criticality' => 'alias-payload']);

            $this->runMigration($this->migration());
        });

        $collisions = array_values(array_filter($warnings(), fn (MessageLogged $log) => str_contains($log->message, 'colliding key')));

        $this->assertCount(1, $collisions);
        $this->assertSame('bcms_dependencies', $collisions[0]->context['table']);
        $this->assertSame('legacy-payload', $collisions[0]->context['row']['criticality']);
        $this->assertArrayHasKey('id', $collisions[0]->context['row']);
    }

    #[Test]
    public function a_collision_delete_on_a_payloadless_spatie_pivot_is_not_logged(): void
    {
        $warnings = $this->captureWarnings();

        [$user, $role] = $this->legacyUserWithRole();
        DB::table('model_has_roles')->insert(['role_id' => $role->id, 'model_id' => $user->id, 'model_type' => 'user']);

        $this->runMigration($this->migration());

        $this->assertSame(1, DB::table('model_has_roles')->where('model_id', $user->id)->count(), 'The collision did happen.');
        $this->assertSame([], array_values(array_filter($warnings(), fn (MessageLogged $log) => str_contains($log->message, 'colliding key'))));
    }

    #[Test]
    public function the_dashboard_is_forbidden_on_legacy_rows_and_reachable_after_the_migration(): void
    {
        // The reported symptom, end to end: a real login 403s on
        // /risk/dashboard (permission:dashboard.view) until the rows are fixed.
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = $this->makeUser();
        $user->assignRole('chief-risk-officer');

        DB::table('model_has_roles')->update(['model_type' => self::LEGACY_USER]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs(User::findOrFail($user->id))->get('/risk/dashboard')->assertForbidden();

        $this->runMigration($this->migration());

        $this->actingAs(User::findOrFail($user->id))->get('/risk/dashboard')->assertSuccessful();
    }
}
