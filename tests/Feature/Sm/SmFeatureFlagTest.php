<?php

namespace Tests\Feature\Sm;

use App\Models\Organization;
use App\Models\Sm\Scenario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The feature flag (§A1.11, spec 01 §0.2, spec 05 §2).
 *
 * DEFAULT FALSE, AND OFF MEANS 404 — NOT 403. `EnsureFeatureEnabled` aborts 404 because "a
 * disabled surface should be indistinguishable from one that does not exist": a 403 tells the
 * caller the surface exists and they are merely not allowed in, which for a half-built feature is
 * information we do not want to leak and which invites support tickets asking for the permission.
 *
 * THE CALLER IS FULLY PRIVILEGED, AND THAT IS THE POINT. A super-permissioned user holding every
 * `scenario.*` permission still gets a 404, so the answer cannot be a permission check in
 * disguise. Note the flag is checked on the route group, AFTER `auth` — so an ANONYMOUS caller
 * gets a 401/redirect rather than a 404, which is correct and is asserted separately below: the
 * flag governs whether a surface exists for somebody who could otherwise reach it, not whether the
 * login page does.
 *
 * Extends `Tests\TestCase` rather than `SmTestCase` on purpose: the shared fixture turns the flag
 * ON in setUp, and a test that asserts the default cannot inherit a setUp that overrides it.
 */
class SmFeatureFlagTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    private Scenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create([
            'name' => 'Flag Bank PLC',
            'short_name' => 'FLGB',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        TenantContext::set($this->org->id);

        $this->user = User::create([
            'name' => 'Fully Privileged',
            'email' => 'privileged@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);

        foreach (SmTestCase::ALL_PERMISSIONS as $permission) {
            Permission::findOrCreate($permission);
            $this->user->givePermissionTo($permission);
        }

        $this->user = $this->user->fresh();

        $this->scenario = Scenario::create([
            'organization_id' => $this->org->id,
            'reference' => 'SM-2026-001',
            'name' => 'Flag test scenario',
            'nature' => 'local',
            'status' => 'draft',
            'currency' => 'NGN',
            'eligible_to_transfer' => false,
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    #[Test]
    public function the_flag_defaults_to_false(): void
    {
        // Read from the FILE rather than from config(), because config() would show whatever the
        // ambient .env says and the assertion is about the SHIPPED default.
        $contents = (string) file_get_contents(base_path('config/features.php'));

        $this->assertStringContainsString("'scenario_modelling' => env('FEATURE_SCENARIO_MODELLING', false)", $contents);
    }

    #[Test]
    public function the_env_example_carries_a_commented_out_flag_like_every_other_module(): void
    {
        // §A1.11: "plus a commented `# FEATURE_SCENARIO_MODELLING=false` in .env.example as
        // `# FEATURE_BCMS=false` is". Commented, because a value in .env.example that a deploy
        // copies is a value somebody chose without deciding to.
        $contents = (string) file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('# FEATURE_SCENARIO_MODELLING=false', $contents);
    }

    #[Test]
    #[DataProvider('routeProvider')]
    public function every_route_fours_oh_fours_while_the_flag_is_off_even_for_a_fully_privileged_user(
        string $method,
        string $urlTemplate
    ): void {
        config(['features.scenario_modelling' => false]);

        $url = str_replace('{scenario}', (string) $this->scenario->id, $urlTemplate);

        $this->actingAs($this->user)->json($method, $url)->assertNotFound();

        // And the flip side, so the 404 above is demonstrably the FLAG and not some other
        // misconfiguration: the same request with the flag on reaches the application.
        config(['features.scenario_modelling' => true]);

        $this->assertNotSame(
            404,
            $this->actingAs($this->user)->json($method, $url)->getStatusCode(),
            "{$method} {$url} still 404s with the flag ON, so the 404 above proved nothing"
        );
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function routeProvider(): array
    {
        return [
            'index' => ['GET', '/risk/scenarios'],
            'create page' => ['GET', '/risk/scenarios/create'],
            'store' => ['POST', '/risk/scenarios'],
            'risk search' => ['GET', '/risk/scenarios/risk-search?q=talent'],
            'show' => ['GET', '/risk/scenarios/{scenario}'],
            'edit' => ['GET', '/risk/scenarios/{scenario}/edit'],
            'update' => ['PUT', '/risk/scenarios/{scenario}'],
            'causes' => ['PUT', '/risk/scenarios/{scenario}/causes'],
            'allocations' => ['PUT', '/risk/scenarios/{scenario}/allocations'],
            'status' => ['POST', '/risk/scenarios/{scenario}/status'],
            'signoffs' => ['POST', '/risk/scenarios/{scenario}/signoffs'],
            'notes' => ['POST', '/risk/scenarios/{scenario}/notes'],
        ];
    }

    #[Test]
    public function the_flag_is_checked_after_auth_so_an_anonymous_caller_never_reaches_the_module(): void
    {
        // Recorded rather than left to be discovered: `auth` sits on the ENCLOSING group and
        // `feature:` on the inner one, so an anonymous request is answered by `auth` first. That is
        // the right order — the flag governs whether a surface exists for somebody who could
        // otherwise reach it — and it means "404 not 403" is a promise to AUTHENTICATED users.
        config(['features.scenario_modelling' => false]);

        $this->json('GET', '/risk/scenarios')->assertUnauthorized();
        $this->json('GET', '/risk/scenarios/'.$this->scenario->id)->assertUnauthorized();
    }

    #[Test]
    public function the_nav_section_is_declared_with_the_flag_on_every_item(): void
    {
        // Spec 05 §2: with the flag off there is "No section at all. Not greyed, not 'coming
        // soon'." The section is DECLARED in NavPresenter — it has to be, or
        // `every_entry_names_a_route_that_exists` could not check it — and `resolve()` withholds it
        // per item via the `feature` key. NavigationPermissionGateTest then asserts each item's
        // `feature` equals its route's `feature:` middleware EXACTLY, so dropping one here fails
        // there rather than shipping a menu entry that 404s.
        $sections = \App\Presenters\NavPresenter::sections();
        $keys = array_column($sections, 'key');

        $this->assertContains('scenario_modelling', $keys);
        // §A1.11 places it after `analysis` and immediately before `quantification`, because Sm is
        // upstream of Quantification rather than a replacement for it.
        $this->assertLessThan(array_search('quantification', $keys, true), array_search('scenario_modelling', $keys, true));
        $this->assertGreaterThan(array_search('analysis', $keys, true), array_search('scenario_modelling', $keys, true));

        $section = $sections[array_search('scenario_modelling', $keys, true)];

        $this->assertSame('Scenario Modelling', $section['label']);
        $this->assertSame('timeline', $section['icon']);
        $this->assertSame(['/risk/scenarios'], $section['prefixes']);

        $this->assertSame(
            [
                ['label' => 'Scenarios', 'route' => 'scenario.index', 'permission' => 'scenario.view', 'feature' => 'scenario_modelling'],
                ['label' => 'New Scenario', 'route' => 'scenario.create', 'permission' => 'scenario.create', 'feature' => 'scenario_modelling'],
            ],
            $section['items']
        );
    }

    #[Test]
    public function a_user_without_scenario_view_sees_no_scenario_modelling_section_even_with_the_flag_on(): void
    {
        // Spec 05 §2's fourth row: "Flag on, neither permission → No section (both items drop)".
        config(['features.scenario_modelling' => true]);

        Permission::findOrCreate('my.view');
        $this->user->givePermissionTo('my.view');

        foreach (SmTestCase::ALL_PERMISSIONS as $permission) {
            $this->user->revokePermissionTo($permission);
        }

        $sections = $this->actingAs($this->user->fresh())->get('/my')->assertOk()->inertiaProps('navigation.sections');

        $this->assertNull(
            collect($sections)->firstWhere('key', 'scenario_modelling'),
            'a section whose items all drop must be omitted entirely, not rendered empty'
        );
    }
}
