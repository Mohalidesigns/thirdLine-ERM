<?php

namespace Tests\Feature\Register;

use App\Models\BusinessUnit;
use App\Models\Control;
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
 * Shared fixtures for the Phase 3.2 risk register tests: one tenant with a
 * risk, a business unit and a control, and a second tenant with one of each,
 * so a cross-tenant id is always available to a Form Request assertion.
 */
abstract class RegisterTestCase extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected BusinessUnit $unit;

    protected Risk $risk;

    protected Control $control;

    protected Organization $otherOrg;

    protected User $otherActor;

    protected BusinessUnit $foreignUnit;

    protected RiskCategory $foreignCategory;

    protected Risk $foreignRisk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDomainFixtures();

        foreach (['risk.view', 'risk.create', 'risk.edit', 'risk.delete'] as $permission) {
            Permission::findOrCreate($permission);
            $this->actor->givePermissionTo($permission);
        }

        // A role outside authorization.full_org_roles, so a pinned user is
        // actually confined to their subtree.
        Role::findOrCreate('branch-manager');

        $this->unit = BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'code' => 'RETAIL',
            'name' => 'Retail Banking',
        ]);

        $this->risk = $this->makeRisk([
            'business_unit_id' => $this->unit->id,
            'risk_owner_id' => $this->actor->id,
            'inherent_likelihood' => 4,
            'inherent_impact' => 4,
            'inherent_score' => 16,
            'inherent_rating' => 'High',
        ]);

        $this->control = $this->makeControl(['effectiveness_rating' => 'effective']);

        $this->otherOrg = Organization::create([
            'name' => 'Other Bank PLC',
            'short_name' => 'OTHB',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        TenantContext::bypass(function () {
            $this->otherActor = User::create([
                'name' => 'Other Officer',
                'email' => 'other-officer@example.test',
                'password' => Hash::make('password'),
                'organization_id' => $this->otherOrg->id,
                'is_active' => true,
            ]);

            $this->foreignCategory = RiskCategory::create([
                'organization_id' => $this->otherOrg->id,
                'code' => 'OPS',
                'name' => 'Operational Risk',
            ]);

            $this->foreignUnit = BusinessUnit::create([
                'organization_id' => $this->otherOrg->id,
                'code' => 'THEIRS',
                'name' => 'Their Unit',
            ]);

            $this->foreignRisk = Risk::create([
                'organization_id' => $this->otherOrg->id,
                'risk_code' => 'RK-FOREIGN',
                'title' => 'Theirs',
                'description' => 'A risk belonging to another bank.',
                'category_id' => $this->foreignCategory->id,
                'business_unit_id' => $this->foreignUnit->id,
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
     * @param  list<string>  $permissions
     */
    protected function userWith(array $permissions, string $email = 'scoped@example.test'): User
    {
        $user = User::create([
            'name' => 'Scoped User',
            'email' => $email,
            'password' => Hash::make('password'),
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);

        $user->assignRole('branch-manager');
        $user->givePermissionTo($permissions);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function validPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Core banking outage',
            'description' => 'A prolonged outage of the core banking platform during settlement.',
            'category_id' => $this->category->id,
            'business_unit_id' => $this->unit->id,
            'risk_owner_id' => $this->actor->id,
            'inherent_likelihood' => 3,
            'impact_financial' => 4,
            'impact_operational' => 5,
            'impact_reputational' => 3,
            'impact_regulatory' => 2,
        ], $overrides);
    }
}
