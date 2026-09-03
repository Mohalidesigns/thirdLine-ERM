<?php

namespace Tests\Feature\Grid;

use App\Grids\Column;
use App\Grids\GridRegistry;
use App\Models\User;
use App\Presenters\GridPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * Migration Phase 2 — every registered grid definition presents through
 * GridPresenter, its permission is a seeded one, and every grid endpoint
 * refuses a user without it.
 */
class GridPresenterCoversEveryDefinitionTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function grids(): array
    {
        $cases = [];

        foreach (self::registeredGridNames() as $name) {
            $cases[$name] = [$name];
        }

        return $cases;
    }

    /** @return list<string> */
    private static function registeredGridNames(): array
    {
        $property = new \ReflectionProperty(GridRegistry::class, 'grids');

        return array_keys($property->getValue());
    }

    #[Test]
    #[DataProvider('grids')]
    public function the_presenter_mirrors_the_definition(string $name): void
    {
        $this->bootDomainFixtures();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $definition = GridRegistry::resolve($name);

        $this->assertTrue(
            Permission::where('name', $definition->permission())->exists(),
            "Grid [{$name}] requires [{$definition->permission()}], which the seeder never creates."
        );

        $this->actor->givePermissionTo($definition->permission());
        $this->actingAs($this->actor);

        $presented = app(GridPresenter::class)->present($definition, Request::create('/', 'GET'), $this->actor);

        $this->assertSame($name, $presented['name']);
        $this->assertSame(
            collect($definition->columns())->pluck('key')->all(),
            array_column($presented['columns'], 'key'),
            "Grid [{$name}]: presented columns differ from the definition."
        );
        $this->assertSame(
            collect($definition->filters())->pluck('key')->all(),
            array_column($presented['filters'], 'key'),
        );
        $this->assertSame(
            collect($definition->columns())->filter(fn (Column $c) => $c->visibleByDefault)->pluck('key')->all(),
            $presented['state']['columns'],
        );
        [$sort, $dir] = $definition->defaultSort();
        $this->assertSame($sort, $presented['state']['sort']);
        $this->assertSame($dir, $presented['state']['dir']);
        $this->assertSame($definition->perPageOptions()[0], $presented['state']['perPage']);
        $this->assertArrayHasKey('data', $presented['rows']);
        $this->assertSame($definition->emptyMessage(), $presented['emptyMessage']);
    }

    #[Test]
    #[DataProvider('grids')]
    public function every_endpoint_refuses_a_user_without_the_permission(string $name): void
    {
        $this->bootDomainFixtures();
        $definition = GridRegistry::resolve($name);
        Permission::findOrCreate($definition->permission());

        $outsider = User::create([
            'name' => 'No Permissions',
            'email' => 'outsider-'.$name.'@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);

        $this->actingAs($outsider)->get(route('risk.grids.show', $name))->assertForbidden();
        $this->actingAs($outsider)->post(route('risk.grids.cell', $name), ['id' => 1, 'key' => 'x', 'value' => 'y'])->assertForbidden();
        $this->actingAs($outsider)->post(route('risk.grids.bulk', [$name, 'delete']), ['ids' => ['1']])->assertForbidden();
        $this->actingAs($outsider)->post(route('risk.grids.views.store', $name), ['name' => 'x'])->assertForbidden();
        $this->actingAs($outsider)->delete(route('risk.grids.views.destroy', [$name, 1]))->assertForbidden();
        $this->actingAs($outsider)->get(route('risk.grids.export', [$name, 'csv']))->assertForbidden();
    }

    #[Test]
    public function an_unknown_grid_is_forbidden_rather_than_an_error(): void
    {
        $this->bootDomainFixtures();

        $this->actingAs($this->actor)->get(route('risk.grids.show', 'no-such-grid'))->assertForbidden();
    }
}
