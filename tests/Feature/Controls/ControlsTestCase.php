<?php

namespace Tests\Feature\Controls;

use App\Models\BusinessUnit;
use App\Models\Control;
use App\Models\ControlTest;
use App\Models\Organization;
use App\Models\Risk;
use App\Models\RiskCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Shared fixtures for the Phase 3.4 control tests: one tenant with a control,
 * a risk and a business unit, and a second tenant with one of each, so a
 * cross-tenant id is always available to a Form Request assertion.
 */
abstract class ControlsTestCase extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected BusinessUnit $unit;

    protected Control $control;

    protected Risk $risk;

    protected Organization $otherOrg;

    protected User $otherActor;

    protected Control $foreignControl;

    protected Risk $foreignRisk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDomainFixtures();

        foreach ([
            'control.view', 'control.create', 'control.edit', 'control.delete',
            'control_test.view', 'control_test.create', 'control_test.edit',
            'control_test.execute', 'control_test.review',
            'risk.view',
        ] as $permission) {
            Permission::findOrCreate($permission);
            $this->actor->givePermissionTo($permission);
        }

        Role::findOrCreate('branch-manager');
        Role::findOrCreate('compliance-officer');

        $this->unit = BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'code' => 'RETAIL',
            'name' => 'Retail Banking',
        ]);

        $this->control = $this->makeControl([
            'name' => 'Dual authorisation on wire transfers',
            'control_type' => 'preventive',
            'owner_id' => $this->actor->id,
            'business_unit_id' => $this->unit->id,
        ]);

        $this->risk = $this->makeRisk(['risk_owner_id' => $this->actor->id]);

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

            $category = RiskCategory::create([
                'organization_id' => $this->otherOrg->id,
                'code' => 'OPS',
                'name' => 'Operational Risk',
            ]);

            $this->foreignControl = Control::create([
                'organization_id' => $this->otherOrg->id,
                'control_code' => 'CTL-FOREIGN',
                'name' => 'Theirs',
                'status' => 'active',
                'created_by' => $this->otherActor->id,
            ]);

            $this->foreignRisk = Risk::create([
                'organization_id' => $this->otherOrg->id,
                'category_id' => $category->id,
                'risk_code' => 'RK-FOREIGN',
                'title' => 'Theirs',
                'description' => 'Another bank.',
                'status' => 'active',
                'created_by' => $this->otherActor->id,
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

    protected function makeTest(array $attributes = []): ControlTest
    {
        return ControlTest::create(array_merge([
            'organization_id' => $this->organization->id,
            'control_id' => $this->control->id,
            'test_code' => 'CT-0001',
            'title' => 'Quarterly walkthrough',
            'test_type' => 'operating_effectiveness',
            'tester_id' => $this->actor->id,
            'scheduled_date' => '2026-06-30',
            'status' => 'scheduled',
            'created_by' => $this->actor->id,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function validControl(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Daily suspense account reconciliation',
            'description' => 'Operations reconciles all suspense accounts daily against the general ledger.',
            'control_type' => 'detective',
            'control_nature' => 'manual',
            'frequency' => 'daily',
            'owner_id' => $this->actor->id,
            'business_unit_id' => $this->unit->id,
            'status' => 'active',
        ], $overrides);
    }
}
