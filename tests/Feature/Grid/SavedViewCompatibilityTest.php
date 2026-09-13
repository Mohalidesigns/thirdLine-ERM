<?php

namespace Tests\Feature\Grid;

use App\Grids\GridRegistry;
use App\Models\Control;
use App\Models\DataGridView;
use App\Presenters\GridPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * Migration Phase 2 — a data_grid_views row written by the Livewire grid
 * applies to the presenter unchanged.
 *
 * The fixture JSON below is the exact shape DataGrid::saveView() wrote
 * (search, filters, sort, dir, perPage, columns), captured before the change.
 */
class SavedViewCompatibilityTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private const LEGACY_STATE = [
        'search' => 'access',
        'filters' => ['control_type' => 'preventive'],
        'sort' => 'name',
        'dir' => 'desc',
        'perPage' => 25,
        'columns' => ['control_code', 'name', 'control_type'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        foreach (['control.view', 'control.edit'] as $permission) {
            Permission::findOrCreate($permission);
        }
        $this->actor->givePermissionTo(['control.view', 'control.edit']);
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
    public function a_legacy_default_view_is_applied_to_a_bare_request(): void
    {
        $this->makeControl(['name' => 'Privileged access review', 'control_type' => 'preventive']);
        $this->makeControl(['name' => 'Backup restoration drill', 'control_type' => 'detective']);
        $this->makeControl(['name' => 'Access log review', 'control_type' => 'detective']);

        DataGridView::create([
            'user_id' => $this->actor->id,
            'grid' => 'controls',
            'name' => 'Legacy preventive access',
            'state' => self::LEGACY_STATE,
            'is_default' => true,
        ]);

        $presented = app(GridPresenter::class)->present(
            GridRegistry::resolve('controls'),
            Request::create('/risk/controls', 'GET'),
            $this->actor,
        );

        $this->assertSame('access', $presented['state']['search']);
        $this->assertSame(['control_type' => 'preventive'], (array) $presented['state']['filters']);
        $this->assertSame('name', $presented['state']['sort']);
        $this->assertSame('desc', $presented['state']['dir']);
        $this->assertSame(25, $presented['state']['perPage']);
        $this->assertSame(['control_code', 'name', 'control_type'], $presented['state']['columns']);
        $this->assertNotNull($presented['state']['viewId']);

        $names = collect($presented['rows']['data'])->map(fn ($row) => $row['cells']['name']['text'])->all();
        $this->assertSame(['Privileged access review'], $names, 'search AND filter from the legacy view narrow the rows');
    }

    #[Test]
    public function explicit_request_state_wins_over_the_default_view(): void
    {
        $this->makeControl(['name' => 'Backup restoration drill', 'control_type' => 'detective']);

        DataGridView::create([
            'user_id' => $this->actor->id,
            'grid' => 'controls',
            'name' => 'Legacy',
            'state' => self::LEGACY_STATE,
            'is_default' => true,
        ]);

        $presented = app(GridPresenter::class)->present(
            GridRegistry::resolve('controls'),
            Request::create('/risk/controls', 'GET', ['search' => 'Backup']),
            $this->actor,
        );

        $this->assertSame('Backup', $presented['state']['search']);
        $this->assertSame([], (array) $presented['state']['filters']);
        $this->assertNull($presented['state']['viewId']);
        $this->assertCount(1, $presented['rows']['data']);
    }

    #[Test]
    public function a_view_saved_by_the_endpoint_has_the_legacy_shape(): void
    {
        $this->post(route('risk.grids.views.store', 'controls').'?'.http_build_query([
            'search' => 'access',
            'filters' => ['control_type' => 'preventive'],
            'sort' => 'name',
            'dir' => 'desc',
            'per_page' => 25,
            'columns' => 'control_code,name,control_type',
        ]), ['name' => 'From React', 'as_default' => true])->assertRedirect();

        $view = DataGridView::where('grid', 'controls')->firstOrFail();

        $this->assertSame($this->actor->id, $view->user_id);
        $this->assertTrue($view->is_default);
        $this->assertSame(self::LEGACY_STATE, $view->state);
    }
}
