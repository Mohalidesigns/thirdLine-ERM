<?php

namespace Tests\Feature\Sm;

use App\Models\BusinessUnit;
use App\Models\Organization;
use App\Models\RiskTaxonomy;
use App\Models\Sm\BusinessLineAllocation;
use App\Models\Sm\Scenario;
use App\Models\Sm\ScenarioCause;
use App\Models\Sm\Signoff;
use App\Models\User;
use App\Services\Sm\OpEventTypeCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Shared fixtures for the Scenario Modelling Phase 1 tests.
 *
 * ONE TENANT with the feature flag ON, three business units, the OP1–OP17 tree seeded
 * through the real service (so the tests exercise the seeder rather than a hand-rolled
 * copy of it), and a SECOND TENANT with a scenario of its own — because a cross-tenant id
 * has to be available to every tenancy assertion, and standard §4's "no existence oracle"
 * rule is only testable against a row that genuinely exists somewhere.
 *
 * The actor holds all six `scenario.*` permissions plus `risk.view`. Tests that care about
 * a narrower grant build their own user with `userWith()`, because a permission test that
 * runs as a super-admin proves nothing: `Gate::before` short-circuits every ability.
 */
abstract class SmTestCase extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    /**
     * Every permission Phase 1 declares (§A1.10).
     *
     * PUBLIC so `SmFeatureFlagTest` — which cannot extend this class, because its whole subject is
     * the default the shared setUp overrides — can build a fully privileged user without keeping a
     * second copy of the list. Two copies of a permission list is how one drifts.
     */
    public const ALL_PERMISSIONS = [
        'scenario.view', 'scenario.create', 'scenario.update', 'scenario.design',
        'scenario.validate', 'scenario.sign_off', 'scenario.manage_actions', 'scenario.note',
    ];

    protected Organization $otherOrg;

    protected User $otherActor;

    protected Scenario $foreignScenario;

    /** @var array<int, BusinessUnit> */
    protected array $units = [];

    /** @var array<int, RiskTaxonomy> */
    protected array $opNodes = [];

    private int $scenarioSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // The module ships dark: `config/features.php` defaults it false and
        // EnsureFeatureEnabled aborts 404 rather than 403. Every test here opts in
        // explicitly, and FeatureFlagTest asserts the default and the 404.
        config(['features.scenario_modelling' => true]);

        // The three Inertia pages (`Sm/Scenarios/{Index,Workspace,Create}.jsx`) are built by
        // frontend-engineer against these same specs, so the component files do not exist
        // yet and `AssertableInertia::component()` would fail on a missing file rather than
        // on a wrong payload. These tests assert the PAYLOAD CONTRACT, which is the half the
        // backend owns; turning the file check off is a harness setting, not a weakened
        // product guard. It must come back on once the JSX lands. [verify at integration]
        config(['inertia.testing.ensure_pages_exist' => false]);

        $this->bootDomainFixtures();

        foreach ([...self::ALL_PERMISSIONS, 'risk.view'] as $permission) {
            Permission::findOrCreate($permission);
            $this->actor->givePermissionTo($permission);
        }

        Role::findOrCreate('risk-manager');
        $this->actor = $this->actor->fresh();

        foreach ([['BU-RT', 'Retail Banking'], ['BU-TR', 'Treasury'], ['BU-OP', 'Operations']] as $index => [$code, $name]) {
            $this->units[$index] = $this->makeUnit($code, $name);
        }

        // Through the real service, so the seeder's own idempotency and its
        // TenantContext::actingAs() wrapper are exercised by every test in this suite.
        app(OpEventTypeCatalogue::class)->ensureFor($this->organization);

        $this->opNodes = RiskTaxonomy::query()
            ->where('organization_id', $this->organization->id)
            ->where('framework', OpEventTypeCatalogue::FRAMEWORK)
            ->whereNotNull('parent_id')
            ->orderBy('sort_order')
            ->get()
            ->all();

        $this->bootOtherTenant();
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  Fixtures */
    /* ------------------------------------------------------------------ */

    private function bootOtherTenant(): void
    {
        TenantContext::bypass(function (): void {
            $this->otherOrg = Organization::create([
                'name' => 'Other Bank PLC',
                'short_name' => 'OTHB',
                'institution_type' => 'commercial_bank',
                'sector' => 'banking',
                'is_active' => true,
            ]);

            $this->otherActor = User::create([
                'name' => 'Other Officer',
                'email' => 'other-officer@example.test',
                'password' => Hash::make('password'),
                'organization_id' => $this->otherOrg->id,
                'is_active' => true,
            ]);

            $this->foreignScenario = Scenario::create([
                'organization_id' => $this->otherOrg->id,
                'reference' => 'SM-2026-901',
                'name' => 'Another bank\'s scenario',
                'nature' => 'local',
                'status' => 'draft',
                'currency' => 'NGN',
                'eligible_to_transfer' => false,
                'created_by' => $this->otherActor->id,
            ]);
        }, 'Sm test fixture: the second tenant a cross-tenant assertion needs');
    }

    protected function makeUnit(string $code, string $name): BusinessUnit
    {
        return BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'code' => $code,
            'name' => $name,
            'is_active' => true,
        ]);
    }

    /**
     * A scenario in the tenant, with its reference left to the caller so a test can pin
     * the sequence deliberately.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeScenario(array $attributes = []): Scenario
    {
        $n = ++$this->scenarioSequence;

        return Scenario::create(array_merge([
            'organization_id' => $this->organization->id,
            'reference' => sprintf('SM-2026-%03d', $n),
            'name' => "Scenario {$n}",
            'nature' => Scenario::DEFAULT_NATURE,
            'status' => 'draft',
            'currency' => 'NGN',
            'eligible_to_transfer' => false,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ], $attributes));
    }

    /**
     * A scenario that satisfies §A1.8's `in_design → designed` gate: an OP mapping,
     * background, rationale, at least one cause and an allocation summing to exactly
     * 100.00.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeDesignedReadyScenario(array $attributes = []): Scenario
    {
        $scenario = $this->makeScenario(array_merge([
            'status' => 'in_design',
            'risk_taxonomy_node_id' => $this->opNodes[4]->id,
            'background' => 'Two analysts leave each year and the replacement pipeline is eighteen months.',
            'rationale' => 'A loss event in the treasury back office traced to a single unmatched handover.',
        ], $attributes));

        $this->selectCauses($scenario, ['people_inadequately_skilled' => null]);
        $this->allocate($scenario, [[0, '60.00'], [1, '40.00']]);

        // Phase 2 quantitative completeness: λ > 0 and ≥1 impact type with computed totals
        $scenario->frequencyDrivers()->create([
            'organization_id' => $scenario->organization_id,
            'driver_type' => 'historical_events',
            'criterion' => 'Historical operational losses',
            'computation_role' => 'count',
            'value' => 1.0,
            'sort_order' => 1,
        ]);

        $scenario->impactTypes()->create([
            'organization_id' => $scenario->organization_id,
            'name' => 'Operational loss',
            'aggregation_template' => 'staff_turnover_interval',
            'eligible_to_transfer' => false,
            'typical_total' => '1000.00',
            'serious_total' => '2000.00',
            'extreme_total' => '5000.00',
            'sort_order' => 1,
        ]);

        return $scenario->fresh(['causes', 'allocations', 'frequencyDrivers', 'impactTypes']);
    }

    /**
     * @param  array<string, string|null>  $causes  code => note
     */
    protected function selectCauses(Scenario $scenario, array $causes): void
    {
        app(\App\Services\Sm\CauseService::class)->sync($scenario, $causes);
    }

    /**
     * @param  list<array{0: int, 1: string}>  $lines  [index into $this->units, percent]
     */
    protected function allocate(Scenario $scenario, array $lines): void
    {
        $rows = [];

        foreach ($lines as [$unitIndex, $percent]) {
            $rows[] = ['business_unit_id' => $this->units[$unitIndex]->id, 'percent' => $percent];
        }

        app(\App\Services\Sm\AllocationService::class)->sync($scenario, $rows, $this->actor->id);
    }

    /**
     * Append a sign-off row directly, for tests that need a specific pre-existing state
     * rather than the HTTP path.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeSignoff(Scenario $scenario, array $attributes = []): Signoff
    {
        return Signoff::create(array_merge([
            'organization_id' => $scenario->organization_id,
            'sm_scenario_id' => $scenario->id,
            'source' => 'source_1',
            'signatory_name' => 'A. Okonkwo',
            'role' => 'Head of Operational Risk',
            'decision' => 'approved',
            'is_independent_challenge' => false,
            'warnings_acknowledged' => [],
            'signed_at' => now(),
        ], $attributes));
    }

    /**
     * A sign-off body the modal would post (spec 01 §5.6).
     *
     * @return array<string, mixed>
     */
    protected function signoffPayload(array $overrides = []): array
    {
        return array_merge([
            'signoffs' => [
                [
                    'source' => 'source_1',
                    'signatory_name' => 'A. Okonkwo',
                    'role' => 'Head of Operational Risk',
                    'user_id' => $this->actor->id,
                    'decision' => 'approved',
                    'is_independent_challenge' => false,
                ],
                [
                    'source' => 'source_2',
                    'signatory_name' => 'B. Adeyemi',
                    'role' => 'Second Line — Independent Challenge',
                    'decision' => 'approved',
                    'is_independent_challenge' => true,
                ],
            ],
            'review_due_on' => '2027-11-10',
        ], $overrides);
    }

    /**
     * A user holding exactly these permissions, and NO others.
     *
     * Deliberately not a super-admin and not the shared `$this->actor`: `Gate::before`
     * short-circuits every ability for super-admin, so a permission test that ran as one
     * would pass whether or not the grant existed.
     *
     * @param  list<string>  $permissions
     */
    protected function userWith(array $permissions, string $email = 'scoped@example.test', ?int $organizationId = null): User
    {
        Role::findOrCreate('scenario-tester');

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }

        $user = User::create([
            'name' => 'Scoped User',
            'email' => $email,
            'password' => Hash::make('password'),
            'organization_id' => $organizationId ?? $this->organization->id,
            'is_active' => true,
        ]);

        $user->assignRole('scenario-tester');
        $user->givePermissionTo($permissions);

        return $user->fresh();
    }

    /**
     * A cause row for assertions that need one without going through the service.
     */
    protected function makeCause(Scenario $scenario, string $code, array $attributes = []): ScenarioCause
    {
        $definition = ScenarioCause::CHECKLIST[$code];

        return ScenarioCause::create(array_merge([
            'organization_id' => $scenario->organization_id,
            'sm_scenario_id' => $scenario->id,
            'side' => $definition[0],
            'cause_group' => $definition[1],
            'code' => $code,
            'is_selected' => true,
            'sort_order' => (int) array_search($code, array_keys(ScenarioCause::CHECKLIST), true),
        ], $attributes));
    }

    protected function makeAllocation(Scenario $scenario, int $unitIndex, string $percent): BusinessLineAllocation
    {
        return BusinessLineAllocation::create([
            'organization_id' => $scenario->organization_id,
            'sm_scenario_id' => $scenario->id,
            'business_unit_id' => $this->units[$unitIndex]->id,
            'percent' => $percent,
        ]);
    }
}
