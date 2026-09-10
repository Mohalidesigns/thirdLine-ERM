<?php

namespace Tests\Feature\Quantification;

use App\Models\IcaapAssessment;
use App\Models\Organization;
use App\Models\QuantificationScenario;
use App\Models\SimulationRun;
use App\Models\User;
use App\Policies\IcaapAssessmentPolicy;
use App\Policies\QuantificationScenarioPolicy;
use App\Policies\SimulationRunPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The three quantification policies (migration Phase 5.2).
 *
 * Each is named for its MODEL, which is what makes Laravel discover it — a
 * scenario ability parked on a differently-named class would never be reached
 * and would deny everyone silently, which is the trap 4.1 fell into.
 */
class QuantificationPoliciesTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private Organization $otherOrg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        foreach (['quantification.view', 'quantification.create', 'quantification.run_simulation', 'quantification.approve_icaap'] as $permission) {
            Permission::findOrCreate($permission);
        }

        $this->otherOrg = Organization::create([
            'name' => 'Other Bank PLC',
            'short_name' => 'OTHB',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);
    }

    #[Test]
    public function the_policies_are_discovered_for_their_models(): void
    {
        $this->assertInstanceOf(QuantificationScenarioPolicy::class, Gate::getPolicyFor(QuantificationScenario::class));
        $this->assertInstanceOf(SimulationRunPolicy::class, Gate::getPolicyFor(SimulationRun::class));
        $this->assertInstanceOf(IcaapAssessmentPolicy::class, Gate::getPolicyFor(IcaapAssessment::class));
    }

    /**
     * Running is its own permission. Somebody trusted to calibrate a scenario
     * is not automatically trusted to publish a capital figure from it.
     */
    #[Test]
    public function running_a_simulation_is_separate_from_calibrating_a_scenario(): void
    {
        $calibrator = $this->userWith(['quantification.view', 'quantification.create'], 'calibrator');
        $runner = $this->userWith(['quantification.view', 'quantification.run_simulation'], 'runner');

        $scenario = $this->scenario();
        $run = $this->simulationRun();

        $this->assertTrue($calibrator->can('update', $scenario));
        $this->assertTrue($calibrator->can('import', QuantificationScenario::class));
        $this->assertFalse($calibrator->can('create', SimulationRun::class));
        $this->assertFalse($calibrator->can('cancel', $run));

        $this->assertTrue($runner->can('create', SimulationRun::class));
        $this->assertTrue($runner->can('cancel', $run), 'Whoever may start the work may stop it.');
        $this->assertFalse($runner->can('update', $scenario));
    }

    /**
     * Board approval turns a working paper into a document filed with the
     * regulator, so it is not `quantification.create`.
     *
     * `quantification.approve_icaap` has been seeded since the permission set
     * was written and, until this policy, was read by nothing at all.
     */
    #[Test]
    public function signing_off_an_icaap_is_separate_from_preparing_one(): void
    {
        $preparer = $this->userWith(['quantification.view', 'quantification.create'], 'preparer');
        $approver = $this->userWith(['quantification.view', 'quantification.approve_icaap'], 'approver');

        $assessment = $this->assessment();

        $this->assertTrue($preparer->can('update', $assessment));
        $this->assertFalse($preparer->can('approve', $assessment));

        $this->assertTrue($approver->can('approve', $assessment));
        $this->assertFalse($approver->can('update', $assessment));
    }

    /** Every ability stops at the tenant boundary, whatever the permission. */
    #[Test]
    public function every_ability_stops_at_the_tenant_boundary(): void
    {
        $user = $this->userWith(
            ['quantification.view', 'quantification.create', 'quantification.run_simulation', 'quantification.approve_icaap'],
            'holds-everything',
        );

        [$scenario, $run, $assessment] = TenantContext::bypass(fn () => [
            $this->scenario(['organization_id' => $this->otherOrg->id, 'scenario_reference' => 'SCN-FOREIGN']),
            $this->simulationRun(['organization_id' => $this->otherOrg->id, 'simulation_reference' => 'SIM-FOREIGN']),
            $this->assessment(['organization_id' => $this->otherOrg->id]),
        ]);

        $this->assertFalse($user->can('view', $scenario));
        $this->assertFalse($user->can('update', $scenario));
        $this->assertFalse($user->can('view', $run));
        $this->assertFalse($user->can('cancel', $run));
        $this->assertFalse($user->can('view', $assessment));
        $this->assertFalse($user->can('update', $assessment));
        $this->assertFalse($user->can('approve', $assessment));
    }

    /** The screens ask the policy, so a missing permission is a 403. */
    #[Test]
    public function the_screens_refuse_a_reader_who_may_not_write(): void
    {
        $reader = $this->userWith(['quantification.view'], 'reader');
        $scenario = $this->scenario();

        $this->actingAs($reader)
            ->put(route('risk.quantification.update-scenario', $scenario), [
                'name' => 'Renamed', 'description' => 'x', 'risk_category' => 'Operational Risk',
                'distribution_type' => 'lognormal', 'frequency_per_year' => 1, 'mean' => 1_000_000,
            ])
            ->assertForbidden();

        $this->assertSame('Scenario', $scenario->fresh()->name);
    }

    /* ------------------------------------------------------------------ */

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions, string $handle): User
    {
        $user = User::create([
            'name' => $handle,
            'email' => $handle.'@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }

    private function scenario(array $attributes = []): QuantificationScenario
    {
        return QuantificationScenario::create(array_merge([
            'organization_id' => $this->organization->id,
            'scenario_reference' => 'SCN-'.now()->year.'-001',
            'scenario_type' => QuantificationScenario::DEFAULT_TYPE,
            'name' => 'Scenario',
            'status' => 'active',
            'created_by' => $this->actor->id,
        ], $attributes));
    }

    private function simulationRun(array $attributes = []): SimulationRun
    {
        return SimulationRun::create(array_merge([
            'organization_id' => $this->organization->id,
            'simulation_reference' => 'SIM-'.now()->year.'-001',
            'status' => 'queued',
            'iterations' => 10_000,
            'initiated_by' => $this->actor->id,
        ], $attributes));
    }

    private function assessment(array $attributes = []): IcaapAssessment
    {
        return IcaapAssessment::create(array_merge([
            'organization_id' => $this->organization->id,
            'period' => '2026-Q2',
            'status' => 'draft',
            'prepared_by' => $this->actor->id,
        ], $attributes));
    }
}
