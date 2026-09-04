<?php

namespace Tests\Feature\Rcsa;

use App\Models\BusinessProcess;
use App\Models\BusinessUnit;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * Shared fixtures for the Phase 3.8 RCSA tests: one tenant with a business
 * unit, a process and a category, and a second tenant with one of each, so a
 * cross-tenant id is always available to a Form Request assertion.
 */
abstract class RcsaTestCase extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected BusinessUnit $unit;

    protected BusinessProcess $process;

    protected Organization $otherOrg;

    protected User $otherActor;

    protected BusinessUnit $foreignUnit;

    protected RiskCategory $foreignCategory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDomainFixtures();

        foreach (['rcsa.view', 'rcsa.submit', 'risk.view', 'control.view'] as $permission) {
            Permission::findOrCreate($permission);
            $this->actor->givePermissionTo($permission);
        }

        foreach (['branch-manager', 'risk-manager'] as $role) {
            Role::findOrCreate($role);
        }

        $this->unit = BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'code' => 'RETAIL',
            'name' => 'Retail Banking',
            'is_active' => true,
        ]);

        $this->process = BusinessProcess::create([
            'organization_id' => $this->organization->id,
            'business_unit_id' => $this->unit->id,
            'code' => 'ONBOARD',
            'name' => 'Customer Onboarding',
        ]);

        TenantContext::bypass(function () {
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

            $this->foreignUnit = BusinessUnit::create([
                'organization_id' => $this->otherOrg->id,
                'code' => 'THEIRS',
                'name' => 'Their Unit',
                'is_active' => true,
            ]);

            $this->foreignCategory = RiskCategory::create([
                'organization_id' => $this->otherOrg->id,
                'code' => 'OPS',
                'name' => 'Operational Risk',
            ]);
        }, 'test fixture');
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /**
     * Roles are assigned at creation: Spatie caches the loaded role relation,
     * so a role added afterwards is invisible until the model is refreshed.
     *
     * @param  list<string>  $permissions
     * @param  list<string>  $roles
     */
    protected function userWith(array $permissions, string $email = 'scoped@example.test', array $roles = ['branch-manager']): User
    {
        $user = User::create([
            'name' => 'Scoped User',
            'email' => $email,
            'password' => Hash::make('password'),
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);

        foreach ($roles as $role) {
            Role::findOrCreate($role);
        }

        $user->syncRoles($roles);
        $user->givePermissionTo($permissions);

        return $user->fresh();
    }

    /**
     * A valid worksheet payload.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function validWorksheet(array $overrides = []): array
    {
        return array_merge([
            'business_unit_id' => $this->unit->id,
            'process_id' => $this->process->id,
            'assessment_date' => now()->toDateString(),
            'action' => 'submit',
            'risks' => [[
                'description' => 'Manual journal entries are not independently reviewed.',
                'category' => 'Operational Risk',
                'inherent_likelihood' => 4,
                'inherent_impact' => 5,
                'residual_likelihood' => 2,
                'residual_impact' => 3,
                'control_effectiveness' => 'partially_effective',
                'existing_controls' => 'Monthly reconciliation.',
                'action_plan' => 'Introduce maker-checker.',
            ]],
        ], $overrides);
    }
}
