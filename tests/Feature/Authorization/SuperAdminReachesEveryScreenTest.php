<?php

namespace Tests\Feature\Authorization;

use App\Authorization\RiskPermissionCatalog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Http\Middleware\CheckPermission;

/**
 * A super-admin is refused nothing by the `permission:` middleware.
 *
 * WHY THIS EXISTS. The product says "super-admin can do everything" in three
 * places — the `Gate::before` in AppServiceProvider, the catalog's
 * `'super-admin' => ['*']`, and HandleInertiaRequests, which puts every
 * permission into the page props so the sidebar renders every link. The
 * `permission:` middleware is NOT Spatie's; it is CheckPermission, and it used
 * to read the grant table, which sees none of that.
 *
 * The grant table falls behind on its own. The catalog is applied by a seeder,
 * a seeder never re-runs on a deployed tenant, and every grant migration since
 * has listed the roles it touches — leaving super-admin out each time on the
 * assumption that the gate covered it. So each new module quietly 403'd the one
 * role meant to see everything, on a link its own sidebar had just drawn.
 * RCSA v2 was the module that made it visible.
 *
 * These two tests are the pair that keeps it shut: one checks the mechanism
 * (the middleware trusts the role), the other checks the data (the catalog and
 * the grant table agree). Either alone leaves a hole.
 */
class SuperAdminReachesEveryScreenTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_permission_middleware_lets_a_super_admin_through_a_permission_they_have_not_been_granted(): void
    {
        $role = Role::findOrCreate('super-admin');

        $user = $this->makeUser();
        $user->assignRole($role);

        // Deliberately NOT granted — this is the state a deployed tenant is in
        // the day a new module adds a permission its seeder will never re-apply.
        Permission::findOrCreate('some_new_module.view');

        $this->assertFalse(
            $user->fresh()->hasPermissionTo('some_new_module.view'),
            'The grant table should not hold it; that is the situation under test.'
        );

        $this->actingAs($user);

        $reached = false;

        $response = (new CheckPermission)->handle(
            request(),
            function () use (&$reached) {
                $reached = true;

                return response('ok');
            },
            'some_new_module.view'
        );

        $this->assertTrue($reached, 'A super-admin must not be refused by the permission middleware.');
        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function a_user_without_the_permission_is_still_refused(): void
    {
        // The bypass must be the role, not a hole. Everyone else is unchanged.
        Permission::findOrCreate('some_new_module.view');

        $user = $this->makeUser();
        $user->assignRole(Role::findOrCreate('risk-analyst'));

        $this->actingAs($user);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->expectExceptionMessage('Unauthorized action.');

        (new CheckPermission)->handle(request(), fn () => response('ok'), 'some_new_module.view');
    }

    #[Test]
    public function super_admin_holds_every_permission_the_catalog_defines(): void
    {
        // The seeder ran as part of this test's database setup; the assertion
        // is that the catalog's `['*']` really does materialise. It is also
        // what the role-administration screen shows, and a role reading "121 of
        // 127" for the account that is supposed to hold all of them is
        // confusing even when the middleware no longer cares.
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $catalog = new RiskPermissionCatalog;
        $role = Role::query()->where('name', 'super-admin')->firstOrFail();

        $missing = array_values(array_diff(
            $catalog->names(),
            $role->permissions->pluck('name')->all()
        ));

        $this->assertSame([], $missing, sprintf(
            'super-admin is missing %d catalog permissions: %s',
            count($missing),
            implode(', ', $missing)
        ));
    }

    /**
     * Every route guarded by `permission:` names a permission the catalog
     * defines.
     *
     * CheckPermission fails CLOSED on an unknown permission — it logs and
     * refuses — so a route guarded by a permission nobody ever created is a
     * screen that is unreachable for every role including super-admin, and
     * nothing fails at boot to say so.
     */
    #[Test]
    public function every_route_guard_names_a_permission_the_catalog_defines(): void
    {
        $known = (new RiskPermissionCatalog)->names();
        $unknown = [];

        foreach (Route::getRoutes() as $route) {
            /** @var RoutingRoute $route */
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'permission:')) {
                    continue;
                }

                foreach (explode('|', substr($middleware, strlen('permission:'))) as $permission) {
                    if ($permission !== '' && ! in_array($permission, $known, true)) {
                        $unknown[$permission] = $route->uri();
                    }
                }
            }
        }

        $this->assertSame([], $unknown, 'Routes guarded by permissions the catalog does not define: '
            .json_encode($unknown, JSON_PRETTY_PRINT));
    }

    private function makeUser(): User
    {
        $organization = Organization::create([
            'name' => 'Guard Test Bank',
            'short_name' => 'GTB',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        return User::create([
            'name' => 'Guard Test User',
            'email' => 'guard-'.uniqid().'@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $organization->id,
            'is_active' => true,
        ]);
    }
}
