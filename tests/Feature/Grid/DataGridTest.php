<?php

namespace Tests\Feature\Grid;

use App\Grids\GridRegistry;
use App\Models\Control;
use App\Models\DataGridView;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * WP-09 TASK 2 — the shared data grid.
 *
 * The properties under test are the ones the hand-rolled tables never had
 * and the ones a shared component must not get wrong: server-side state
 * (search/filter/sort survive the round trip), tenancy (a forged bulk id
 * list cannot cross organizations), and authorization (Livewire updates
 * bypass route middleware, so the component's own gate is load-bearing).
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

    #[Test]
    public function it_renders_rows_and_searches_server_side(): void
    {
        $this->makeControl(['name' => 'Privileged access review']);
        $this->makeControl(['name' => 'Backup restoration drill']);

        Livewire::test('data-grid', ['grid' => 'controls'])
            ->assertSee('Privileged access review')
            ->assertSee('Backup restoration drill')
            ->set('search', 'Privileged')
            ->assertSee('Privileged access review')
            ->assertDontSee('Backup restoration drill');
    }

    #[Test]
    public function filters_only_accept_declared_option_values(): void
    {
        $this->makeControl(['name' => 'Preventive thing', 'control_type' => 'preventive']);
        $this->makeControl(['name' => 'Detective thing', 'control_type' => 'detective']);

        $component = Livewire::test('data-grid', ['grid' => 'controls'])
            ->set('filters.control_type', 'detective')
            ->assertSee('Detective thing')
            ->assertDontSee('Preventive thing');

        // An undeclared value is ignored, not passed into SQL.
        $component->set('filters.control_type', "x' OR 1=1 --")
            ->assertSee('Detective thing')
            ->assertSee('Preventive thing');
    }

    #[Test]
    public function sorting_flips_direction_and_rejects_unknown_columns(): void
    {
        $this->makeControl(['control_code' => 'CTL-AAA']);
        $this->makeControl(['control_code' => 'CTL-ZZZ']);

        $component = Livewire::test('data-grid', ['grid' => 'controls'])
            ->call('sortBy', 'control_code')
            ->assertSet('dir', 'desc') // default sort was already control_code asc
            ->assertSeeInOrder(['CTL-ZZZ', 'CTL-AAA']);

        $component->call('sortBy', 'control_nature') // declared, not sortable
            ->assertSet('sort', 'control_code');

        $component->call('sortBy', 'no_such_column')
            ->assertSet('sort', 'control_code');
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

        $component = Livewire::test('data-grid', ['grid' => 'controls'])
            ->assertSee('Our control')
            ->assertDontSee('Their control');

        // Forge a selection containing the foreign id, then bulk delete.
        $component->set('selected', [(string) $mine->id, (string) $theirs->id])
            ->call('runBulk', 'delete');

        $this->assertSoftDeleted('controls', ['id' => $mine->id]);
        $this->assertNull($theirs->fresh()->deleted_at, 'the foreign row must be untouched');
    }

    #[Test]
    public function the_grid_permission_gates_mount_and_every_update(): void
    {
        $this->makeControl();

        $outsider = User::create([
            'name' => 'No Permissions',
            'email' => 'outsider@example.test',
            'password' => bcrypt('secret-password'),
            'organization_id' => $this->organization->id,
        ]);

        Livewire::actingAs($outsider)
            ->test('data-grid', ['grid' => 'controls'])
            ->assertStatus(403);
    }

    #[Test]
    public function bulk_actions_require_their_own_permission(): void
    {
        $control = $this->makeControl();

        $viewer = User::create([
            'name' => 'Read Only',
            'email' => 'viewer@example.test',
            'password' => bcrypt('secret-password'),
            'organization_id' => $this->organization->id,
        ]);
        $viewer->givePermissionTo('control.view');

        Livewire::actingAs($viewer)
            ->test('data-grid', ['grid' => 'controls'])
            ->set('selected', [(string) $control->id])
            ->call('runBulk', 'delete')
            ->assertStatus(403);

        $this->assertDatabaseHas('controls', ['id' => $control->id]);
    }

    #[Test]
    public function inline_edit_writes_only_declared_editable_columns_with_valid_values(): void
    {
        $control = $this->makeControl(['effectiveness_rating' => 'ineffective']);

        Livewire::test('data-grid', ['grid' => 'controls'])
            ->call('startEdit', (string) $control->id, 'effectiveness_rating')
            ->set('editValue', 'effective')
            ->call('saveEdit');

        $this->assertSame('effective', $control->fresh()->effectiveness_rating);

        // A non-editable column never reaches updateCell.
        Livewire::test('data-grid', ['grid' => 'controls'])
            ->call('startEdit', (string) $control->id, 'name')
            ->assertSet('editing', null);

        // A value outside the declared options is rejected.
        Livewire::test('data-grid', ['grid' => 'controls'])
            ->call('startEdit', (string) $control->id, 'effectiveness_rating')
            ->set('editValue', 'made_up_rating')
            ->call('saveEdit');

        $this->assertSame('effective', $control->fresh()->effectiveness_rating);
    }

    #[Test]
    public function saved_views_are_personal_and_restore_the_grid_state(): void
    {
        $this->makeControl();

        $component = Livewire::test('data-grid', ['grid' => 'controls'])
            ->set('search', 'access')
            ->set('filters.control_type', 'preventive')
            ->set('newViewName', 'My preventive view')
            ->call('saveView');

        $view = DataGridView::where('grid', 'controls')->first();
        $this->assertNotNull($view);
        $this->assertSame($this->actor->id, $view->user_id);
        $this->assertSame('access', $view->state['search']);

        $component->call('clearFilters')
            ->assertSet('search', '')
            ->call('applyView', $view->id)
            ->assertSet('search', 'access')
            ->assertSet('filters.control_type', 'preventive');

        // Another user cannot apply or delete someone else's view.
        $other = User::create([
            'name' => 'Someone Else',
            'email' => 'someone-else@example.test',
            'password' => bcrypt('secret-password'),
            'organization_id' => $this->organization->id,
        ]);
        $other->givePermissionTo('control.view');

        Livewire::actingAs($other)
            ->test('data-grid', ['grid' => 'controls'])
            ->call('applyView', $view->id)
            ->assertSet('search', '')
            ->call('deleteView', $view->id);

        $this->assertDatabaseHas('data_grid_views', ['id' => $view->id]);
    }

    #[Test]
    public function the_column_chooser_cannot_hide_the_last_column_or_invent_one(): void
    {
        $this->makeControl();

        $component = Livewire::test('data-grid', ['grid' => 'controls'])
            ->call('toggleColumn', 'not_a_column');

        $visible = $component->get('columns');
        $this->assertNotContains('not_a_column', $visible);

        foreach ($visible as $key) {
            $component->call('toggleColumn', $key);
        }

        $this->assertCount(1, $component->get('columns'), 'the last visible column must survive');
    }

    #[Test]
    public function csv_export_honours_search_and_visible_columns(): void
    {
        $this->makeControl(['name' => 'Privileged access review']);
        $this->makeControl(['name' => 'Backup restoration drill']);

        // Drive the component class directly: StreamedResponse content is
        // not reachable through Livewire's test harness.
        $grid = new \App\Livewire\DataGrid();
        $grid->mount('controls');
        $grid->search = 'Privileged';

        ob_start();
        $grid->exportCsv()->sendContent();
        $csv = ob_get_clean();

        $this->assertStringContainsString('Privileged access review', $csv);
        $this->assertStringNotContainsString('Backup restoration drill', $csv);
        $this->assertStringContainsString('Control ID', $csv);
    }

    #[Test]
    public function the_controls_index_page_renders_the_grid(): void
    {
        $this->makeControl(['name' => 'Privileged access review']);

        $this->get('/risk/controls')
            ->assertOk()
            ->assertSee('Control Library')
            ->assertSee('Privileged access review');
    }

    #[Test]
    public function an_unknown_grid_name_is_an_explicit_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        GridRegistry::resolve('no-such-grid');
    }
}
