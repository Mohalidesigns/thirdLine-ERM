<?php

namespace Tests\Feature\Widgets;

use App\Http\Controllers\Risk\HqController;
use App\Models\BusinessUnit;
use App\Models\Dashboard;
use App\Services\Widgets\DashboardResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * "No role restriction" is stored two ways: SQL NULL, and the JSON empty
 * array `[]`. MariaDB 10.4 keeps a json column as
 * LONGTEXT, so `role_ids = '[]'` compared text and matched. MySQL 8 stores it
 * natively, and the same predicate compares an array with the JSON *string*
 * "[]", which is never equal. Two sites relied on the text comparison:
 *
 *   - DashboardResolver::pick() could not reach a `[]` dashboard (it rendered
 *     "No dashboard published" for a dashboard that plainly existed);
 *   - HqController::roleBlockedDashboards() listed EVERY unrestricted
 *     dashboard as "hidden by roles" (`!= '[]'` was always true).
 *
 * Each test asserts the DATABASE is holding what the test thinks it is
 * holding, then the behaviour, so it cannot pass on a column that silently
 * normalised the fixture away. Run on both engines.
 */
class DashboardRoleListNativeJsonTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private BusinessUnit $retail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        foreach (['hq.view', 'dashboard.view', 'dashboard.manage', 'risk.view'] as $permission) {
            Permission::findOrCreate($permission);
        }

        $this->actor->givePermissionTo(['hq.view', 'dashboard.view', 'dashboard.manage', 'risk.view']);

        $this->retail = BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'code' => 'BU-RT',
            'name' => 'Retail Banking',
            'is_active' => true,
        ]);
    }

    private function dashboard(string $name, ?array $roleIds, bool $published = true): Dashboard
    {
        $widget = \App\Models\WidgetDefinition::withoutGlobalScopes()->firstOrCreate(
            ['code' => 'wg-rolelist'],
            [
                'organization_id' => null,
                'name' => 'Active Risks',
                'widget_type' => 'kpi_tile',
                'query' => ['source' => 'risks', 'aggregate' => 'count'],
                'context_binding' => 'inherit_subtree',
                'period_binding' => 'selected',
                'is_system' => true,
            ],
        );

        $dashboard = Dashboard::create([
            'organization_id' => $this->organization->id,
            'code' => 'dash-'.str()->lower(str()->random(8)),
            'name' => $name,
            'object_type_id' => $this->retail->graphObject()->object_type_id,
            'role_ids' => $roleIds,
            'tabs' => [['code' => 'main', 'label' => 'Dashboard', 'layout' => [
                ['widget_id' => $widget->id, 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3, 'overrides' => []],
            ]]],
            'published_tabs' => null,
            'is_published' => false,
            'version' => 1,
        ]);

        if ($published) {
            $dashboard->publish();
        }

        return $dashboard->fresh();
    }

    /** What the database holds, as JSON_LENGTH sees it. NULL stays NULL. */
    private function storedLength(Dashboard $dashboard): ?int
    {
        $row = DB::selectOne('SELECT JSON_LENGTH(role_ids) AS n FROM dashboards WHERE id = ?', [$dashboard->id]);

        return $row->n === null ? null : (int) $row->n;
    }

    /** @return list<string> names the controller would call "hidden by roles" */
    private function roleBlockedNames(): array
    {
        $controller = app(HqController::class);
        $method = new ReflectionMethod($controller, 'roleBlockedDashboards');
        $method->setAccessible(true);

        $user = $this->actor->fresh();

        return $method->invoke($controller, $this->retail->graphObject(), $user)
            ->pluck('name')->sort()->values()->all();
    }

    #[Test]
    public function role_blocked_lists_only_dashboards_restricted_to_roles_the_viewer_lacks(): void
    {
        $held = Role::findOrCreate('held-role');
        $other = Role::findOrCreate('board-member');
        $this->actor->assignRole($held);

        $nullList = $this->dashboard('Unrestricted NULL', null);
        $emptyList = $this->dashboard('Unrestricted empty', []);
        $blocked = $this->dashboard('Board only', [$other->id]);
        $this->dashboard('Mine', [$held->id]);
        $this->dashboard('Mine and theirs', [$held->id, $other->id]);
        $this->dashboard('Draft board only', [$other->id], published: false);

        // The fixtures are what the test says they are, on this engine.
        $this->assertNull($this->storedLength($nullList));
        $this->assertSame(0, $this->storedLength($emptyList), 'the [] fixture must reach the column as an empty array');
        $this->assertSame(1, $this->storedLength($blocked));

        $this->assertSame(
            ['Board only'],
            $this->roleBlockedNames(),
            'an unrestricted dashboard (NULL or []) is reachable by everyone and must never be reported as role-blocked; a draft is not published so is not blocked',
        );
    }

    #[Test]
    public function role_blocked_does_not_list_an_empty_role_list_for_a_viewer_with_no_roles(): void
    {
        $this->dashboard('Unrestricted empty', []);

        $this->assertSame([], $this->roleBlockedNames());
    }

    #[Test]
    public function the_resolver_reaches_a_dashboard_whose_role_list_is_empty(): void
    {
        $empty = $this->dashboard('Reachable Anyway', []);

        $this->assertSame(0, $this->storedLength($empty));

        $picked = app(DashboardResolver::class)->resolveFor($this->retail->graphObject(), $this->actor->fresh());

        $this->assertNotNull($picked, 'a published dashboard with role_ids = [] is for every role');
        $this->assertSame($empty->id, $picked->id);
    }

    #[Test]
    public function the_resolver_does_not_reach_a_dashboard_restricted_to_a_role_the_viewer_lacks(): void
    {
        $other = Role::findOrCreate('board-member');
        $this->dashboard('Board only', [$other->id]);

        $this->assertNull(
            app(DashboardResolver::class)->resolveFor($this->retail->graphObject(), $this->actor->fresh()),
            'the empty-list fix must not widen a non-empty restriction',
        );
    }

    #[Test]
    public function business_hq_renders_an_empty_role_list_dashboard_and_reports_nothing_role_blocked(): void
    {
        $this->dashboard('Reachable Anyway', []);

        $this->actingAs($this->actor)->get('/hq/'.$this->retail->graphObject()->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('dashboard.name', 'Reachable Anyway')
                ->where('roleBlocked', []));
    }

    #[Test]
    public function business_hq_names_only_the_genuinely_blocked_dashboard_when_nothing_resolves(): void
    {
        $other = Role::findOrCreate('board-member');
        $this->dashboard('Board only', [$other->id]);

        $this->actingAs($this->actor)->get('/hq/'.$this->retail->graphObject()->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('dashboard', null)
                ->has('roleBlocked', 1)
                ->where('roleBlocked.0.name', 'Board only'));
    }
}
