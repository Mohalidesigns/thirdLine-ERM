<?php

namespace Tests\Feature\Grid;

use App\Grids\GridRegistry;
use App\Models\Control;
use App\Models\DataGridView;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * WP-09 TASK 2 — the shared data grid, now on GridPresenter + GridController
 * (migration Phase 2).
 *
 * The properties under test are the ones the hand-rolled tables never had
 * and the ones a shared component must not get wrong: server-side state
 * (search/filter/sort come from the URL and survive the round trip),
 * tenancy (a forged bulk id list cannot cross organizations), and
 * authorization (every endpoint carries its own gate, because the client
 * can call any of them directly).
 */
class DataGridTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        foreach (['control.view', 'control.edit', 'control.delete'] as $permission) {
            Permission::findOrCreate($permission);
        }
        $this->actor->givePermissionTo(['control.view', 'control.edit', 'control.delete']);

        $this->actingAs($this->actor);
    }

    private function makeControl(array $attributes = []): Control
    {
        static $sequence = 0;
        $sequence++;

        return Control::create(array_merge([
            'organization_id' => $this->organization->id,
            'control_code' => sprintf('CTL-%03d', $sequence),
            'name' => "Control {$sequence}",
            'control_type' => 'preventive',
            'status' => 'active',
            'created_by' => $this->actor->id,
        ], $attributes));
    }

    private function makeUser(string $name, string $email): User
    {
        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => bcrypt('secret-password'),
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);
    }

    /** @return list<string> */
    private function names(array $query = []): array
    {
        $names = [];

        $this->get(route('risk.controls.index', $query))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$names) {
                $page->where('grid.rows.data', function ($rows) use (&$names) {
                    $names = collect($rows)->map(fn ($row) => $row['cells']['name']['text'])->all();

                    return true;
                });
            });

        return $names;
    }

    #[Test]
    public function it_renders_rows_and_searches_server_side(): void
    {
        $this->makeControl(['name' => 'Privileged access review']);
        $this->makeControl(['name' => 'Backup restoration drill']);

        $this->assertEqualsCanonicalizing(['Privileged access review', 'Backup restoration drill'], $this->names());
        $this->assertSame(['Privileged access review'], $this->names(['search' => 'Privileged']));
    }

    #[Test]
    public function filters_only_accept_declared_option_values(): void
    {
        $this->makeControl(['name' => 'Preventive thing', 'control_type' => 'preventive']);
        $this->makeControl(['name' => 'Detective thing', 'control_type' => 'detective']);

        $this->assertSame(['Detective thing'], $this->names(['filters' => ['control_type' => 'detective']]));

        // An undeclared value is ignored, not passed into SQL.
        $this->assertCount(2, $this->names(['filters' => ['control_type' => "x' OR 1=1 --"]]));
    }

    #[Test]
    public function sorting_honours_direction_and_rejects_unknown_columns(): void
    {
        $this->makeControl(['control_code' => 'CTL-AAA']);
        $this->makeControl(['control_code' => 'CTL-ZZZ']);

        // Flipping the direction is the client's job; the server honours it.
        $this->get(route('risk.controls.index', ['sort' => 'control_code', 'dir' => 'desc']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('grid.state.sort', 'control_code')
                ->where('grid.state.dir', 'desc')
                ->where('grid.rows.data.0.cells.control_code.text', 'CTL-ZZZ')
                ->where('grid.rows.data.1.cells.control_code.text', 'CTL-AAA'));

        // Declared but not sortable falls back to the default sort.
        $this->get(route('risk.controls.index', ['sort' => 'control_nature']))
            ->assertInertia(fn (Assert $page) => $page->where('grid.state.sort', 'control_code'));

        $this->get(route('risk.controls.index', ['sort' => 'no_such_column']))
            ->assertInertia(fn (Assert $page) => $page->where('grid.state.sort', 'control_code'));
    }

    #[Test]
    public function another_tenants_rows_never_render_and_bulk_ids_cannot_reach_them(): void
    {
        $mine = $this->makeControl(['name' => 'Our control']);

        $otherOrg = Organization::create(['name' => 'Other Bank', 'slug' => 'other-bank']);
        $theirs = Control::create([
            'organization_id' => $otherOrg->id,
            'control_code' => 'CTL-FOREIGN',
            'name' => 'Their control',
            'control_type' => 'preventive',
            'status' => 'active',
            'created_by' => $this->actor->id,
        ]);

        $this->assertSame(['Our control'], $this->names());

        // Forge a selection containing the foreign id, then bulk delete.
        $this->post(route('risk.grids.bulk', ['controls', 'delete']), [
            'ids' => [(string) $mine->id, (string) $theirs->id],
        ])->assertRedirect();

        $this->assertSoftDeleted('controls', ['id' => $mine->id]);
        $this->assertNull($theirs->fresh()->deleted_at, 'the foreign row must be untouched');
    }

    #[Test]
    public function the_grid_permission_gates_the_page_and_every_endpoint(): void
    {
        $control = $this->makeControl();

        $outsider = $this->makeUser('No Permissions', 'outsider@example.test');

        $this->actingAs($outsider)->get(route('risk.controls.index'))->assertForbidden();
        $this->actingAs($outsider)->get(route('risk.grids.show', 'controls'))->assertForbidden();
        $this->actingAs($outsider)
            ->postJson(route('risk.grids.cell', 'controls'), ['id' => $control->id, 'key' => 'effectiveness_rating', 'value' => 'effective'])
            ->assertForbidden();
        $this->actingAs($outsider)
            ->post(route('risk.grids.bulk', ['controls', 'delete']), ['ids' => [(string) $control->id]])
            ->assertForbidden();
        $this->actingAs($outsider)->get(route('risk.grids.export', ['controls', 'csv']))->assertForbidden();

        $this->assertDatabaseHas('controls', ['id' => $control->id, 'deleted_at' => null]);
    }

    #[Test]
    public function bulk_actions_require_their_own_permission(): void
    {
        $control = $this->makeControl();

        $viewer = $this->makeUser('Read Only', 'viewer@example.test');
        $viewer->givePermissionTo('control.view');

        $this->actingAs($viewer)
            ->post(route('risk.grids.bulk', ['controls', 'delete']), ['ids' => [(string) $control->id]])
            ->assertForbidden();

        $this->assertDatabaseHas('controls', ['id' => $control->id, 'deleted_at' => null]);

        // The action is not even offered to a user who cannot run it.
        $this->actingAs($viewer)->get(route('risk.controls.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('grid.bulkActions', [])
                ->where('grid.selection.mode', 'none'));
    }

    #[Test]
    public function inline_edit_writes_only_declared_editable_columns_with_valid_values(): void
    {
        $control = $this->makeControl(['effectiveness_rating' => 'ineffective']);

        $this->postJson(route('risk.grids.cell', 'controls'), [
            'id' => (string) $control->id,
            'key' => 'effectiveness_rating',
            'value' => 'effective',
        ])->assertOk()->assertJsonPath('ok', true);

        $this->assertSame('effective', $control->fresh()->effectiveness_rating);

        // A non-editable column never reaches updateCell.
        $this->postJson(route('risk.grids.cell', 'controls'), [
            'id' => (string) $control->id,
            'key' => 'name',
            'value' => 'Renamed',
        ])->assertStatus(422);

        $this->assertSame($control->name, $control->fresh()->name);

        // A value outside the declared options is rejected.
        $this->postJson(route('risk.grids.cell', 'controls'), [
            'id' => (string) $control->id,
            'key' => 'effectiveness_rating',
            'value' => 'made_up_rating',
        ])->assertStatus(422);

        $this->assertSame('effective', $control->fresh()->effectiveness_rating);

        // Without the edit permission the column is not editable at all.
        $viewer = $this->makeUser('Read Only', 'viewer@example.test');
        $viewer->givePermissionTo('control.view');

        $this->actingAs($viewer)->postJson(route('risk.grids.cell', 'controls'), [
            'id' => (string) $control->id,
            'key' => 'effectiveness_rating',
            'value' => 'ineffective',
        ])->assertForbidden();

        $this->assertSame('effective', $control->fresh()->effectiveness_rating);
    }

    #[Test]
    public function saved_views_are_personal_and_restore_the_grid_state(): void
    {
        $this->makeControl();

        $this->post(route('risk.grids.views.store', 'controls').'?'.http_build_query([
            'search' => 'access',
            'filters' => ['control_type' => 'preventive'],
        ]), ['name' => 'My preventive view'])->assertRedirect();

        $view = DataGridView::where('grid', 'controls')->first();
        $this->assertNotNull($view);
        $this->assertSame($this->actor->id, $view->user_id);
        $this->assertSame('access', $view->state['search']);

        // A bare request is the cleared grid; naming the view restores it.
        $this->get(route('risk.controls.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('grid.state.search', '')
                ->where('grid.views.0.name', 'My preventive view'));

        $this->get(route('risk.controls.index', ['view' => $view->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('grid.state.search', 'access')
                ->where('grid.state.filters.control_type', 'preventive')
                ->where('grid.state.viewId', $view->id));

        // Another user cannot apply or delete someone else's view.
        $other = $this->makeUser('Someone Else', 'someone-else@example.test');
        $other->givePermissionTo('control.view');

        $this->actingAs($other)->get(route('risk.controls.index', ['view' => $view->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('grid.state.search', '')
                ->where('grid.state.viewId', null)
                ->where('grid.views', []));

        $this->actingAs($other)->delete(route('risk.grids.views.destroy', ['controls', $view->id]))->assertRedirect();

        $this->assertDatabaseHas('data_grid_views', ['id' => $view->id]);
    }

    #[Test]
    public function the_column_chooser_cannot_invent_a_column_and_falls_back_to_the_defaults(): void
    {
        $this->makeControl();

        $defaults = collect(GridRegistry::resolve('controls')->columns())
            ->filter(fn ($c) => $c->visibleByDefault)
            ->pluck('key')
            ->all();

        // Unknown keys are dropped; declared ones are kept in definition order.
        $this->get(route('risk.controls.index', ['columns' => 'not_a_column,name,control_code']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('grid.state.columns', ['control_code', 'name'])
                ->has('grid.rows.data.0.cells', 2)
                ->missing('grid.rows.data.0.cells.not_a_column'));

        // A list with nothing declared in it falls back to the defaults, so
        // the grid can never render with no columns at all.
        $this->get(route('risk.controls.index', ['columns' => 'not_a_column']))
            ->assertInertia(fn (Assert $page) => $page->where('grid.state.columns', $defaults));

        $this->get(route('risk.controls.index', ['columns' => '']))
            ->assertInertia(fn (Assert $page) => $page->where('grid.state.columns', $defaults));
    }

    #[Test]
    public function csv_export_honours_search_and_visible_columns(): void
    {
        $this->makeControl(['name' => 'Privileged access review']);
        $this->makeControl(['name' => 'Backup restoration drill']);

        ob_start();
        $this->get(route('risk.grids.export', ['controls', 'csv', 'search' => 'Privileged']))->assertOk()->sendContent();
        $csv = ob_get_clean();

        $this->assertStringContainsString('Privileged access review', $csv);
        $this->assertStringNotContainsString('Backup restoration drill', $csv);
        $this->assertStringContainsString('Control ID', $csv);

        ob_start();
        $this->get(route('risk.grids.export', ['controls', 'csv', 'columns' => 'name']))->assertOk()->sendContent();
        $csv = ob_get_clean();

        $this->assertStringContainsString('Privileged access review', $csv);
        $this->assertStringNotContainsString('Control ID', $csv);
    }

    #[Test]
    public function the_controls_index_page_renders_the_grid(): void
    {
        $control = $this->makeControl(['name' => 'Privileged access review']);

        $this->get(route('risk.controls.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Controls/Index')
                ->where('total', 1)
                ->where('grid.name', 'controls')
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.name.text', 'Privileged access review')
                ->where('grid.rows.data.0.cells.control_code.text', $control->control_code));

        $this->assertFileDoesNotExist(resource_path('views/risk/controls/index.blade.php'));
        $this->assertTrue(\App\Support\Migration\Ported::isRoute('risk.controls.index'));
    }

    #[Test]
    public function an_unknown_grid_name_is_an_explicit_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        GridRegistry::resolve('no-such-grid');
    }
}
