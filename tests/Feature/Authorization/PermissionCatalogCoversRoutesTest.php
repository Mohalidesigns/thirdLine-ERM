<?php

namespace Tests\Feature\Authorization;

use App\Authorization\RiskPermissionCatalog;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Authorization\PermissionCatalog;

/**
 * RouteAuthorizationTest's missing half (migration Phase 7.1d).
 *
 * That test asks whether every `permission:` string names a permission the
 * SEEDER creates. It answers by seeding a database and reading it back, which
 * means it can only see what a fresh install would have — and every deployed
 * tenant is a different thing, patched by four `grant_*_permissions` migrations
 * that repeat parts of the same list.
 *
 * This asks the question against the DECLARATION instead: does every guard in
 * routes/web.php name something the catalog defines, and does the catalog
 * define anything nobody can hold? No database, so it is fast enough to be
 * unmissable, and it fails on the declaration rather than on a side effect of
 * running a seeder.
 *
 * THE BUG SHAPE IT EXISTS FOR. ThirdLine references an `Audit Supervisor` role
 * that nothing creates: the application asks for it, the seeder never made it,
 * and Spatie's answer to a role that does not exist is to grant nothing. A
 * permission behaves identically — `permission:risk.veiw` fails closed, which
 * is safe, and silently locks every role out of that screen, which is not.
 */
class PermissionCatalogCoversRoutesTest extends TestCase
{
    private PermissionCatalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalog = new RiskPermissionCatalog;
    }

    #[Test]
    public function every_permission_named_by_a_route_is_defined_in_the_catalog(): void
    {
        $undefined = [];

        foreach ($this->routePermissions() as $permission => $uris) {
            if (! $this->catalog->has($permission)) {
                $undefined[$permission] = $uris;
            }
        }

        $this->assertSame(
            [],
            $undefined,
            "These routes are guarded by permissions the catalog does not define. Spatie grants an\n"
            ."unknown permission to nobody, so each of these screens is unreachable by every role:\n"
            .json_encode($undefined, JSON_PRETTY_PRINT)
        );
    }

    #[Test]
    public function every_permission_in_the_catalog_is_described(): void
    {
        $undescribed = [];

        foreach ($this->catalog->all() as $permission => $description) {
            if (trim($description) === '') {
                $undescribed[] = $permission;
            }
        }

        $this->assertSame(
            [],
            $undescribed,
            'A permission with no description cannot be granted safely by whoever assigns roles — '
            .'admin.metadata, admin.scoring and admin.configuration all read as "administration" '
            .'and are three different kinds of authority. Undescribed: '.implode(', ', $undescribed)
        );
    }

    #[Test]
    public function no_role_grants_a_permission_the_catalog_does_not_define(): void
    {
        // Spatie drops an unknown permission silently, leaving the role quietly
        // narrower than it reads here. syncCatalog() throws on this too; the
        // test says so without running a seeder.
        $this->assertSame([], $this->catalog->undefinedRoleGrants());
    }

    #[Test]
    public function every_permission_in_the_catalog_is_held_by_some_role(): void
    {
        $held = [];

        foreach ($this->catalog->roleNames() as $role) {
            $held = [...$held, ...$this->catalog->permissionsFor($role)];
        }

        $unheld = array_values(array_diff($this->catalog->names(), array_unique($held)));

        $this->assertSame(
            [],
            $unheld,
            'These permissions exist but no role holds them, so whatever they guard is unreachable: '
            .implode(', ', $unheld)
        );
    }

    #[Test]
    public function the_catalog_groups_every_permission_under_a_module(): void
    {
        // moduleOf() drives how the role-assignment screen groups the list, and
        // a permission in no module simply does not render there — present in
        // the system, absent from the only page that grants it.
        $orphaned = array_values(array_filter(
            $this->catalog->names(),
            fn (string $permission) => $this->catalog->moduleOf($permission) === null
        ));

        $this->assertSame([], $orphaned, 'Permissions belonging to no module: '.implode(', ', $orphaned));
    }

    #[Test]
    public function every_historical_grant_migration_names_only_catalog_permissions(): void
    {
        // The four grant_*_permissions migrations pre-date the catalog and are
        // NOT rewritten: they have already run on every deployment, so editing
        // them changes nothing that has happened and risks a fresh install's
        // migration order for no gain. What they can still do is drift — a
        // permission granted there and never declared is granted to nobody.
        //
        // New ones extend ThirdLine\Platform\Authorization\GrantPermissionsMigration,
        // which makes that impossible rather than merely detectable.
        $undeclared = [];

        foreach (glob(database_path('migrations/*grant*permissions*.php')) as $file) {
            $source = file_get_contents($file);

            preg_match_all("/'([a-z0-9_]+\\.[a-z0-9_]+)'/", $source, $matches);

            foreach (array_unique($matches[1]) as $permission) {
                if (! $this->catalog->has($permission)) {
                    $undeclared[basename($file)][] = $permission;
                }
            }
        }

        $this->assertSame(
            [],
            $undeclared,
            "A grant migration hands out a permission the catalog does not define. Spatie grants an\n"
            ."unknown permission to nobody, so the role is quietly narrower than the migration reads:\n"
            .json_encode($undeclared, JSON_PRETTY_PRINT)
        );
    }

    /**
     * Every permission named by a `permission:` or `scope:` guard, and where.
     *
     * `scope:` names the same permissions for API tokens, so a typo in one is
     * exactly as damaging as in the other.
     *
     * @return array<string, list<string>> permission => uris
     */
    private function routePermissions(): array
    {
        $found = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware)) {
                    continue;
                }

                $prefix = match (true) {
                    str_starts_with($middleware, 'permission:') => 'permission:',
                    str_starts_with($middleware, 'scope:') => 'scope:',
                    default => null,
                };

                if ($prefix === null) {
                    continue;
                }

                $argument = substr($middleware, strlen($prefix));

                foreach (preg_split('/[|,]/', $argument) as $permission) {
                    $permission = trim($permission);

                    if ($permission !== '') {
                        $found[$permission][] = $route->uri();
                    }
                }
            }
        }

        ksort($found);

        return $found;
    }
}
