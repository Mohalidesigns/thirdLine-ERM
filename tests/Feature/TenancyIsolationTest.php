<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Risk;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\Support\TenantFixture;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;
use ThirdLine\Platform\Tenancy\TenantContext;

class TenancyIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private TenantFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = new TenantFixture;

        $this->orgA = Organization::create([
            'name' => 'Alpha Bank PLC',
            'short_name' => 'ALPHA',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        $this->orgB = Organization::create([
            'name' => 'Beta Bank PLC',
            'short_name' => 'BETA',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  Coverage: no tenant table may quietly opt out */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function every_model_backed_by_an_organization_id_column_uses_the_tenancy_trait(): void
    {
        $missing = [];

        foreach (self::eloquentModels() as $class) {
            $model = new $class;
            $table = $model->getTable();

            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'organization_id')) {
                continue;
            }

            if (! in_array(BelongsToOrganization::class, class_uses_recursive($class), true)) {
                $missing[] = $class;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'These models are backed by a table with organization_id but do not use BelongsToOrganization: '
            .implode(', ', $missing)
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Model layer */
    /* ------------------------------------------------------------------ */

    #[Test]
    #[DataProvider('tenantScopedModelProvider')]
    public function organization_b_rows_are_invisible_to_organization_a(string $class): void
    {
        $model = new $class;
        $table = $model->getTable();

        $idA = $this->fixture->make($table, $this->orgA->id);
        $idB = $this->fixture->make($table, $this->orgB->id);

        TenantContext::set($this->orgA->id);

        // index
        $visible = $class::query()->pluck($model->getKeyName())->all();
        $this->assertContains($idA, $visible, "[{$table}] own row missing from index");
        $this->assertNotContains($idB, $visible, "[{$table}] leaked another tenant's row into index");

        // show
        $this->assertNotNull($class::find($idA), "[{$table}] own row not retrievable");
        $this->assertNull($class::find($idB), "[{$table}] another tenant's row was retrievable");

        // update
        [$scratchColumn, $scratchValue] = $this->scratchColumn($table);
        $updated = $class::where($model->getKeyName(), $idB)->update([$scratchColumn => $scratchValue]);
        $this->assertSame(0, $updated, "[{$table}] update reached another tenant's row");

        // delete
        $deleted = $class::where($model->getKeyName(), $idB)->delete();
        $this->assertSame(0, $deleted, "[{$table}] delete reached another tenant's row");

        // and the row really is still there
        $this->assertDatabaseHas($table, [$model->getKeyName() => $idB]);
    }

    #[Test]
    #[DataProvider('tenantScopedModelProvider')]
    public function bypass_tenancy_is_the_only_way_to_see_across_organizations(string $class): void
    {
        $model = new $class;
        $table = $model->getTable();

        $idA = $this->fixture->make($table, $this->orgA->id);
        $idB = $this->fixture->make($table, $this->orgB->id);

        TenantContext::set($this->orgA->id);

        $this->assertSame(1, $class::query()->whereIn($model->getKeyName(), [$idA, $idB])->count());

        $both = $class::bypassTenancy('isolation test')
            ->whereIn($model->getKeyName(), [$idA, $idB])
            ->count();

        $this->assertSame(2, $both, "[{$table}] bypassTenancy did not lift the scope");

        // The bypass must not leak: the next ordinary query is scoped again.
        $this->assertSame(1, $class::query()->whereIn($model->getKeyName(), [$idA, $idB])->count());
    }

    #[Test]
    public function creating_a_record_stamps_the_current_tenant(): void
    {
        TenantContext::set($this->orgB->id);

        $categoryId = $this->fixture->make('risk_categories', $this->orgB->id);

        $risk = Risk::create([
            'risk_code' => 'RK-TEST-0001',
            'title' => 'Unstamped risk',
            'description' => 'created without an explicit organization_id',
            'category_id' => $categoryId,
            'inherent_likelihood' => 3,
            'inherent_impact' => 3,
            'status' => 'active',
        ]);

        $this->assertSame($this->orgB->id, $risk->organization_id);
    }

    #[Test]
    public function an_explicit_organization_id_is_not_overwritten(): void
    {
        TenantContext::set($this->orgA->id);

        $categoryId = $this->fixture->make('risk_categories', $this->orgA->id);

        $risk = Risk::create([
            'organization_id' => $this->orgA->id,
            'risk_code' => 'RK-TEST-0002',
            'title' => 'Explicit risk',
            'description' => 'created with an explicit organization_id',
            'category_id' => $categoryId,
            'inherent_likelihood' => 2,
            'inherent_impact' => 2,
            'status' => 'active',
        ]);

        $this->assertSame($this->orgA->id, $risk->organization_id);
    }

    #[Test]
    public function the_scope_is_inert_when_no_tenant_is_resolved(): void
    {
        $this->fixture->make('risks', $this->orgA->id);
        $this->fixture->make('risks', $this->orgB->id);

        TenantContext::clear();

        // Console and system contexts run untenanted and must still see data;
        // HTTP can never reach this state because ResolveTenant aborts first.
        $this->assertSame(2, Risk::query()->count());
    }

    #[Test]
    public function requesting_the_tenant_without_one_resolved_throws_rather_than_defaulting(): void
    {
        TenantContext::clear();

        $this->expectException(\RuntimeException::class);

        TenantContext::organizationId();
    }

    /* ------------------------------------------------------------------ */
    /*  HTTP layer */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_user_without_an_organization_is_refused(): void
    {
        $orphan = User::create([
            'name' => 'Orphan User',
            'email' => 'orphan@example.test',
            'password' => Hash::make('password'),
            'organization_id' => null,
            'is_active' => true,
        ]);

        $this->actingAs($orphan)
            ->get('/risk/dashboard')
            ->assertForbidden();
    }

    #[Test]
    public function http_reads_of_another_tenants_records_are_not_found(): void
    {
        $userA = $this->userFor($this->orgA);

        $riskB = $this->fixture->make('risks', $this->orgB->id);
        $controlB = $this->fixture->make('controls', $this->orgB->id);
        $issueB = $this->fixture->make('issues', $this->orgB->id);
        $lossEventB = $this->fixture->make('loss_events', $this->orgB->id);
        $kriB = $this->fixture->make('key_risk_indicators', $this->orgB->id);
        $treatmentB = $this->fixture->make('treatment_plans', $this->orgB->id);

        $this->actingAs($userA);

        foreach ([
            "/risk/register/{$riskB}",
            "/risk/controls/{$controlB}",
            "/risk/issues/{$issueB}",
            "/risk/loss-events/{$lossEventB}",
            "/risk/kri/{$kriB}",
            "/risk/treatments/{$treatmentB}",
        ] as $url) {
            $response = $this->get($url);

            // 404 specifically, not 403: the caller is a super-admin, so a
            // 403 here would mean some other check refused the request and the
            // tenant scope was never actually exercised.
            $this->assertSame(
                404,
                $response->getStatusCode(),
                "GET {$url} returned {$response->getStatusCode()}; another tenant's record must be invisible, not merely forbidden"
            );
        }
    }

    #[Test]
    public function http_writes_to_another_tenants_records_do_not_take_effect(): void
    {
        $userA = $this->userFor($this->orgA);
        $riskB = $this->fixture->make('risks', $this->orgB->id, ['title' => 'Beta original title']);

        $this->actingAs($userA)
            ->put("/risk/register/{$riskB}", ['title' => 'Overwritten by Alpha']);

        $this->assertSame(
            'Beta original title',
            DB::table('risks')->where('id', $riskB)->value('title'),
            "another tenant's risk was modified over HTTP"
        );

        $this->actingAs($userA)->delete("/risk/register/{$riskB}");

        $this->assertNotNull(
            DB::table('risks')->where('id', $riskB)->value('id'),
            "another tenant's risk was deleted over HTTP"
        );
    }

    #[Test]
    public function index_listings_only_contain_the_callers_own_records(): void
    {
        $userA = $this->userFor($this->orgA);

        $this->fixture->make('risks', $this->orgA->id, ['title' => 'Alpha visible risk', 'risk_code' => 'RK-A-0001']);
        $this->fixture->make('risks', $this->orgB->id, ['title' => 'Beta hidden risk', 'risk_code' => 'RK-B-0001']);

        $this->actingAs($userA)
            ->get('/risk/register')
            ->assertOk()
            ->assertSee('Alpha visible risk')
            ->assertDontSee('Beta hidden risk');
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    /**
     * A column safe to write to when probing whether an update escapes the
     * tenant scope. Not every table carries timestamps — risk_audit_trail is
     * deliberately append-only and has none.
     *
     * @return array{0: string, 1: mixed}
     */
    private function scratchColumn(string $table): array
    {
        if (Schema::hasColumn($table, 'updated_at')) {
            return ['updated_at', now()];
        }

        foreach (Schema::getColumns($table) as $column) {
            if (! $column['nullable'] || $column['auto_increment']) {
                continue;
            }

            if (in_array($column['name'], ['organization_id', 'uuid'], true)) {
                continue;
            }

            if (str_ends_with($column['name'], '_id')) {
                continue;
            }

            if (in_array(strtolower($column['type_name']), ['varchar', 'text', 'char', 'string'], true)) {
                return [$column['name'], 'tenancy-probe'];
            }
        }

        $this->fail("No writable scratch column found on [{$table}] to probe cross-tenant updates.");
    }

    /**
     * A fully privileged user in the given organization.
     *
     * super-admin on purpose: this suite is about tenancy, and a user who
     * lacks a permission would be refused for the wrong reason, letting a
     * broken tenant scope pass unnoticed behind a 403.
     */
    private function userFor(Organization $organization): User
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $user = User::create([
            'name' => "User of {$organization->short_name}",
            'email' => strtolower($organization->short_name).'@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $organization->id,
            'is_active' => true,
        ]);

        $user->assignRole('super-admin');

        return $user;
    }

    /** @return list<array{0: class-string<Model>}> */
    public static function tenantScopedModelProvider(): array
    {
        return array_map(
            fn (string $class) => [$class],
            array_values(array_filter(
                self::tenantScopedModels(),
                // Users are tenant-scoped but exercised through the auth flow
                // rather than the generic CRUD assertions: creating a second
                // one inside the provider fights the actingAs() fixtures.
                fn (string $class) => $class !== User::class
            ))
        );
    }

    /**
     * Every Eloquent model in app/Models, INCLUDING SUBDIRECTORIES.
     *
     * This used to glob `app/Models/*.php` only. That was correct while every
     * model sat at the top level and quietly stopped being correct the moment
     * a module namespaced its own: `app/Models/Rcsa` and `app/Models/Tprm`
     * were invisible to this provider, so the guard reported green over models
     * it had never looked at — the worst failure mode available to a guard,
     * because the build stays green and the coverage silently shrinks.
     *
     * Recursive now. A new module directory is covered on the day it is
     * created rather than on the day somebody remembers this file.
     *
     * @return list<class-string<Model>>
     */
    private static function eloquentModels(): array
    {
        $models = [];

        // Resolved from __DIR__ rather than app_path(): PHPUnit evaluates data
        // providers before the Laravel container exists.
        $modelPath = dirname(__DIR__, 2).'/app/Models';

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($modelPath, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($modelPath) + 1, -4);
            $class = 'App\\Models\\'.str_replace('/', '\\', $relative);

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }

            $models[] = $class;
        }

        sort($models);

        return $models;
    }

    /** @return list<class-string<Model>> models carrying the tenancy trait */
    private static function tenantScopedModels(): array
    {
        return array_values(array_filter(
            self::eloquentModels(),
            fn (string $class) => in_array(BelongsToOrganization::class, class_uses_recursive($class), true)
        ));
    }
}
