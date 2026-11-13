<?php

namespace Tests\Feature\Sm;

use App\Authorization\RiskPermissionCatalog;
use App\Models\Sm\Scenario;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;

/**
 * Permission gating per role (gate checklist §B, §A1.10).
 *
 * NO TEST HERE RUNS AS SUPER-ADMIN. `Gate::before` in AppServiceProvider short-circuits every
 * ability to true for that role, so a permission test written against it passes whether or not
 * the grant exists — the guard reports green over a hole. `userWith()` builds a user holding
 * exactly the named permissions and no others.
 *
 * THE SEPARATION THIS MODULE TURNS ON: `scenario.note` is not `scenario.update`. §A1.10 splits
 * them because editing a scenario is first-line work and recording a dissent against somebody
 * else's is second-line work; one permission for both lets the designer write the dissent log
 * the trail exists to keep. So the three roles that hold view + note ONLY can open a scenario
 * they cannot edit and dissent against it — and cannot move it, sign it or change it.
 */
class SmPermissionGatingTest extends SmTestCase
{
    #[Test]
    public function the_catalog_declares_exactly_seven_scenario_permissions(): void
    {
        // §A1.10: SEVEN scenario permissions (Phase 1 six + Phase 2 scenario.design).
        // Adding them later costs NO MIGRATION,
        // because deploy.sh runs RolesAndPermissionsSeeder whose syncCatalog() is additive.
        $this->assertSame(
            [
                'scenario.view' => 'See operational-risk scenarios, their causes, allocations and sign-offs.',
                'scenario.create' => 'Design a new scenario and give it a reference.',
                'scenario.update' => "Change a scenario's identity, qualitative report, causes and business-line allocation.",
                'scenario.design' => 'Configure and calibrate quantitative scenario models (scorecard, frequency, impact, insurance).',
                'scenario.validate' => 'Move a scenario through validation — return it for rework or mark it validated.',
                'scenario.sign_off' => 'Record a sign-off against a scenario as one of its three sources.',
                'scenario.note' => 'Add an assumption, dissent, challenge or workshop minute to the scenario record.',
            ],
            (new RiskPermissionCatalog)->modules()['Scenario modelling']
        );

        // The deferred ones must NOT be present, and `scenario.delete` was refused outright.
        foreach (['scenario.manage_actions', 'scenario.run_export', 'scenario.manage_library', 'scenario.delete'] as $absent) {
            $this->assertFalse(
                (new RiskPermissionCatalog)->has($absent),
                "{$absent} is not a Phase 1 or 2 permission"
            );
        }
    }

    #[Test]
    #[DataProvider('roleGrantProvider')]
    public function each_role_holds_exactly_the_scenario_permissions_a1_10_grants_it(string $role, array $expected): void
    {
        $catalog = new RiskPermissionCatalog;

        $held = array_values(array_filter(
            $catalog->permissionsFor($role),
            fn (string $permission) => str_starts_with($permission, 'scenario.')
        ));

        sort($held);
        sort($expected);

        $this->assertSame($expected, $held, "role [{$role}]");
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function roleGrantProvider(): array
    {
        $all = ['scenario.view', 'scenario.create', 'scenario.update', 'scenario.design', 'scenario.validate', 'scenario.sign_off', 'scenario.note'];
        $viewAndNote = ['scenario.view', 'scenario.note'];

        return [
            // All six, and `chief-risk-officer` inherits them by spreading $riskManager, so no
            // extra line is needed there — which is itself worth pinning.
            'risk-manager' => ['risk-manager', $all],
            'chief-risk-officer' => ['chief-risk-officer', $all],
            // "Challenges every line, decides nothing" — the catalog's own words for the ORM
            // Analyst. `scenario.sign_off` was REFUSED for this role explicitly: independent
            // challenge is a FLAG ON A SIGN-OFF ROW, not a reason to let the analyst sign the
            // record they are challenging.
            'risk-analyst' => ['risk-analyst', $viewAndNote],
            // The first-line owner contributes causes and dissent about their unit and does not
            // sign the scenario off.
            'risk-owner' => ['risk-owner', $viewAndNote],
            'compliance-officer' => ['compliance-officer', $viewAndNote],
            // VIEW ONLY, and note is withheld too: a board minute is not a dissent against the
            // design and the notes log is that trail.
            'board-member' => ['board-member', ['scenario.view']],
            'super-admin' => ['super-admin', $all],
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Routes */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_user_without_scenario_view_gets_a_403_on_the_index_and_a_404_on_a_scenario(): void
    {
        $outsider = $this->userWith(['risk.view'], 'outsider@example.test');

        $this->actingAs($outsider)->get(route('scenario.index'))->assertForbidden();

        // Not 404: the scenario is in the outsider's OWN tenant, so the tenant scope resolves
        // it and the policy refuses it. A 404 here would mean the binding, not the permission,
        // did the work — and the two must not be confused (compare SmTenancyIsolationTest).
        $this->actingAs($outsider)->get(route('scenario.show', $this->makeScenario()))->assertForbidden();
    }

    #[Test]
    #[DataProvider('routePermissionProvider')]
    public function every_route_is_refused_without_its_own_permission(string $method, string $routeName, array $body, string $permission): void
    {
        // A user holding every scenario permission EXCEPT the one this route needs.
        $held = array_values(array_diff(self::ALL_PERMISSIONS, [$permission]));
        $user = $this->userWith($held, 'partial-'.str_replace('.', '-', $permission).'@example.test');

        $scenario = $this->scenarioFor($routeName);

        $response = $this->actingAs($user)->json($method, route($routeName, ['scenario' => $scenario->id]), $body);

        $this->assertSame(
            403,
            $response->getStatusCode(),
            "{$method} {$routeName} answered {$response->getStatusCode()} without {$permission}"
        );
    }

    /** @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: string}> */
    public static function routePermissionProvider(): array
    {
        return [
            'index needs view' => ['GET', 'scenario.index', [], 'scenario.view'],
            'create page needs create' => ['GET', 'scenario.create', [], 'scenario.create'],
            'show needs view' => ['GET', 'scenario.show', [], 'scenario.view'],
            'edit needs update' => ['GET', 'scenario.edit', [], 'scenario.update'],
            'update needs update' => ['PUT', 'scenario.update', ['name' => 'Renamed'], 'scenario.update'],
            'causes need update' => ['PUT', 'scenario.causes.update', ['causes' => []], 'scenario.update'],
            'allocations need update' => ['PUT', 'scenario.allocations.update', ['allocations' => []], 'scenario.update'],
            'status needs validate' => ['POST', 'scenario.status', ['to' => 'in_design'], 'scenario.validate'],
            'signoffs need sign_off' => ['POST', 'scenario.signoffs.store', ['signoffs' => [], 'review_due_on' => '2027-11-10'], 'scenario.sign_off'],
            'notes need note' => ['POST', 'scenario.notes.store', ['kind' => 'assumption', 'body' => 'A note.'], 'scenario.note'],
            'scorecard needs design' => ['PUT', 'scenario.scorecard.update', ['control_nature' => 'mixed', 'components' => [['component_code' => 'control_environment', 'efficiency_level' => 3, 'weighting_factor' => 3]]], 'scenario.design'],
            'frequency needs design' => ['PUT', 'scenario.frequency.update', ['drivers' => [['driver_type' => 'events', 'criterion' => 'Events', 'computation_role' => 'count', 'value' => 5]]], 'scenario.design'],
            'impact needs design' => ['PUT', 'scenario.impact.update', ['types' => []], 'scenario.design'],
            'insurance needs design' => ['PUT', 'scenario.insurance.update', ['coverages' => []], 'scenario.design'],
        ];
    }

    #[Test]
    public function a_designer_who_can_update_but_not_validate_gets_no_transition_buttons(): void
    {
        // Spec 01 §3's surprising consequence, stated so the screen cannot stay silent about
        // it: "a designer holding `scenario.update` alone can fill in every field and CANNOT
        // advance the scenario out of `draft`. The header must therefore never render a
        // transition button for a user who lacks `scenario.validate`, and when an editable
        // scenario's user holds update but not validate, the readiness strip says so in plain
        // words. Silence there would read as a broken button."
        $designer = $this->userWith(['scenario.view', 'scenario.update'], 'designer@example.test');
        $scenario = $this->makeScenario(['status' => 'draft']);

        $this->actingAs($designer)
            ->get(route('scenario.show', $scenario))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('transitions', [])
                ->where('can.update', true)
                ->where('can.validate', false)
                // The gate is still computed and sent, so the readiness strip can say what is
                // missing even though this user cannot act on it.
                ->has('gate.missing'));

        // And the route itself refuses them.
        $this->actingAs($designer)
            ->post(route('scenario.status', $scenario), ['to' => 'in_design'])
            ->assertForbidden();

        $this->assertSame('draft', $scenario->fresh()->status);
    }

    #[Test]
    public function the_can_prop_carries_seven_flags_including_design(): void
    {
        // Phase 2 activates `scenario.design` (ADR 0027 Amendment 2), so `can` has seven keys.
        $scenario = $this->makeScenario();

        $this->actingAs($this->actor)
            ->get(route('scenario.show', $scenario))
            ->assertInertia(fn ($page) => $page->where('can', function ($can) {
                // AssertableInertia hands nested props over as a Collection, so unwrap with
                // collect()->all() — a bare `(array)` cast yields the object's own properties.
                $flags = collect($can)->all();

                $this->assertSame(
                    ['view', 'create', 'update', 'design', 'validate', 'sign_off', 'note'],
                    array_keys($flags)
                );
                $this->assertArrayHasKey('design', $flags);

                return true;
            }));
    }

    #[Test]
    public function can_note_closes_only_at_archived_and_can_update_closes_at_in_validation(): void
    {
        // Spec 01 §5.5's table, expressed as the two flags the screen reads. Notes stay open in
        // `signed_off` because "a note is commentary, not a change"; content closes from
        // `in_validation` because "a validator reading a record the designer can still change is
        // validating nothing".
        $expected = [
            'draft' => ['update' => true, 'note' => true],
            'in_design' => ['update' => true, 'note' => true],
            'designed' => ['update' => true, 'note' => true],
            'returned_for_rework' => ['update' => true, 'note' => true],
            'in_validation' => ['update' => false, 'note' => true],
            'validated' => ['update' => false, 'note' => true],
            'signed_off' => ['update' => false, 'note' => true],
            'archived' => ['update' => false, 'note' => false],
        ];

        foreach ($expected as $status => $flags) {
            $scenario = $this->makeScenario(['status' => $status]);

            $this->actingAs($this->actor)
                ->get(route('scenario.show', $scenario))
                ->assertInertia(fn ($page) => $page
                    ->where('can.update', $flags['update'])
                    ->where('can.note', $flags['note'])
                    ->where('scenario.frozen', in_array($status, Scenario::FROZEN_STATUSES, true)));
        }
    }

    #[Test]
    public function the_grid_export_is_gated_by_scenario_view_and_not_by_a_phase_5_permission(): void
    {
        // Spec 00 §3.5: the whole `risk.grids.{grid}` group sits behind `can:view-grid,grid`,
        // which resolves to `ScenariosGrid::permission()`. `scenario.run_export` is reserved for
        // Phase 5's LS-workbook and board-pack documents, so it must NOT be what guards a CSV.
        $this->assertSame('scenario.view', app(\App\Grids\GridRegistry::class)::resolve('scenarios')->permission());

        $viewer = $this->userWith(['scenario.view'], 'viewer@example.test');

        $this->makeScenario(['name' => 'Exportable scenario']);

        $this->actingAs($viewer)
            ->get(route('risk.grids.export', ['grid' => 'scenarios', 'format' => 'csv']))
            ->assertOk();

        $outsider = $this->userWith(['risk.view'], 'no-scenarios@example.test');

        $this->actingAs($outsider)
            ->get(route('risk.grids.export', ['grid' => 'scenarios', 'format' => 'csv']))
            ->assertForbidden();
    }

    #[Test]
    public function every_role_granted_a_scenario_permission_sees_the_nav_items_it_is_entitled_to(): void
    {
        // Spec 05 §2: risk-manager sees both items; risk-analyst, risk-owner,
        // compliance-officer and board-member see Scenarios ONLY. The nav is the first place the
        // module's separation of duties becomes visible.
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        foreach ([
            'risk-manager' => ['scenario.index', 'scenario.create'],
            'chief-risk-officer' => ['scenario.index', 'scenario.create'],
            'risk-analyst' => ['scenario.index'],
            'risk-owner' => ['scenario.index'],
            'compliance-officer' => ['scenario.index'],
            'board-member' => ['scenario.index'],
        ] as $roleName => $routes) {
            Role::findOrCreate($roleName);
            $user = $this->userWith([], strtolower(str_replace('-', '', $roleName)).'@nav.example.test');
            $user->syncRoles([$roleName]);
            $user = $user->fresh();

            $sections = $this->actingAs($user)->get('/my')->assertOk()->inertiaProps('navigation.sections');

            // A user with no `my.view` still gets the shared navigation prop; the assertion is
            // about which Sm routes appear, not about whether the page is theirs.
            $sm = collect($sections)->firstWhere('key', 'scenario_modelling');

            $this->assertNotNull($sm, "role [{$roleName}] should see the Scenario Modelling section");
            $this->assertSame(
                $routes,
                collect($sm['items'])->pluck('route')->all(),
                "role [{$roleName}] nav items"
            );
        }
    }

    /** A scenario in a state the given route can act on, so a 403 is about permission only. */
    private function scenarioFor(string $routeName): Scenario
    {
        return match ($routeName) {
            'scenario.status' => $this->makeScenario(['status' => 'draft']),
            'scenario.signoffs.store' => $this->makeScenario(['status' => 'validated']),
            default => $this->makeScenario(['status' => 'in_design']),
        };
    }
}
