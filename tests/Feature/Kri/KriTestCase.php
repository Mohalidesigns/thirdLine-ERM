<?php

namespace Tests\Feature\Kri;

use App\Models\KeyRiskIndicator;
use App\Models\Organization;
use App\Models\Risk;
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
 * Shared fixtures for the Phase 4.1 KRI tests: one tenant with a risk and a
 * KRI, and a second tenant with one of each, so a cross-tenant id is always
 * available to a Form Request assertion.
 */
abstract class KriTestCase extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected Risk $risk;

    protected KeyRiskIndicator $kri;

    protected Organization $otherOrg;

    protected User $otherActor;

    protected Risk $foreignRisk;

    private int $kriSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDomainFixtures();

        foreach ([
            'kri.view', 'kri.create', 'kri.edit', 'kri.delete',
            'kri.record_measurement', 'kri.acknowledge_breach', 'risk.view',
        ] as $permission) {
            Permission::findOrCreate($permission);
            $this->actor->givePermissionTo($permission);
        }

        foreach (['branch-manager', 'risk-manager'] as $role) {
            Role::findOrCreate($role);
        }

        $this->risk = $this->makeRisk(['risk_owner_id' => $this->actor->id]);
        $this->kri = $this->makeKri();

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

    /** @param  array<string, mixed>  $attributes */
    protected function makeKri(array $attributes = []): KeyRiskIndicator
    {
        $n = ++$this->kriSequence;

        return KeyRiskIndicator::create(array_merge([
            'organization_id' => $this->organization->id,
            'risk_id' => $this->risk->id,
            'kri_code' => 'KRI-TEST-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT),
            'name' => "Indicator {$n}",
            'kri_name' => "Indicator {$n}",
            'metric_formula' => '',
            'data_source' => '',
            'measurement_frequency' => 'monthly',
            'unit_of_measure' => '%',
            'measurement_unit' => '%',
            'threshold_direction' => 'higher_worse',
            'direction' => 'higher_is_worse',
            'green_threshold_max' => 10,
            'amber_threshold_min' => 10,
            'amber_threshold_max' => 50,
            'red_threshold_min' => 50,
            'owner_id' => $this->actor->id,
            'kri_owner_id' => $this->actor->id,
            'current_status' => 'green',
            'is_active' => true,
            'created_by' => $this->actor->id,
        ], $attributes));
    }

    /**
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
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function validKri(array $overrides = []): array
    {
        return array_merge([
            'risk_id' => $this->risk->id,
            'kri_name' => 'Failed settlement rate',
            'description' => 'Share of settlement instructions that fail on value date.',
            'measurement_unit' => '%',
            'measurement_frequency' => 'monthly',
            'data_source' => 'Core banking',
            'kri_owner_id' => $this->actor->id,
            'direction' => 'higher_is_worse',
            'green_threshold' => 2,
            'red_threshold' => 5,
            'target_value' => 1,
            'formula' => 'failed / total * 100',
        ], $overrides);
    }
}
