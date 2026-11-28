<?php

namespace Tests\Feature\RiskLibrary;

use App\Models\BusinessUnit;
use App\Models\Entity;
use App\Models\EntityType;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\RiskLibraryItem;
use App\Models\User;
use App\Services\RiskLibraryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

abstract class RiskLibraryTestCase extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected BusinessUnit $unit;

    protected EntityType $entityType;

    protected Entity $entityA;

    protected Entity $entityB;

    protected Organization $otherOrg;

    protected User $otherActor;

    protected RiskCategory $foreignCategory;

    protected Entity $foreignEntity;

    protected RiskLibraryItem $foreignLibraryItem;

    protected RiskLibraryService $libraryService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDomainFixtures();
        $this->libraryService = app(RiskLibraryService::class);

        $permissions = [
            'risk_library.view',
            'risk_library.create',
            'risk_library.update',
            'risk_library.retire',
            'risk.view',
            'risk.create',
            'risk.edit',
            'risk.delete',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
            $this->actor->givePermissionTo($permission);
        }

        Role::findOrCreate('chief-risk-officer');
        $this->actor->assignRole('chief-risk-officer');

        $this->unit = BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'code' => 'RETAIL',
            'name' => 'Retail Banking',
        ]);

        $this->entityType = EntityType::create([
            'organization_id' => $this->organization->id,
            'code' => 'SUBSIDIARY',
            'name' => 'Subsidiary',
            'level' => 1,
            'is_active' => true,
        ]);

        $this->entityA = Entity::create([
            'organization_id' => $this->organization->id,
            'entity_type_id' => $this->entityType->id,
            'entity_code' => 'ENT-NG',
            'name' => 'Nigeria Banking Subsidiary',
            'status' => 'active',
            'level' => 1,
            'created_by' => $this->actor->id,
        ]);

        $this->entityB = Entity::create([
            'organization_id' => $this->organization->id,
            'entity_type_id' => $this->entityType->id,
            'entity_code' => 'ENT-GH',
            'name' => 'Ghana Banking Subsidiary',
            'status' => 'active',
            'level' => 1,
            'created_by' => $this->actor->id,
        ]);

        $this->otherOrg = Organization::create([
            'name' => 'Foreign Bank PLC',
            'short_name' => 'FGNB',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        TenantContext::actingAs($this->otherOrg->id, function () use ($permissions): void {
            $this->otherActor = User::create([
                'name' => 'Foreign Risk Officer',
                'email' => 'foreign-risk-officer@example.test',
                'password' => Hash::make('password'),
                'organization_id' => $this->otherOrg->id,
                'is_active' => true,
            ]);

            foreach ($permissions as $permission) {
                $this->otherActor->givePermissionTo($permission);
            }
            $this->otherActor->assignRole('chief-risk-officer');

            $this->foreignCategory = RiskCategory::create([
                'organization_id' => $this->otherOrg->id,
                'code' => 'OPS-FGN',
                'name' => 'Operational Risk (Foreign)',
            ]);

            $foreignType = EntityType::create([
                'organization_id' => $this->otherOrg->id,
                'code' => 'SUBSIDIARY',
                'name' => 'Subsidiary',
                'level' => 1,
                'is_active' => true,
            ]);

            $this->foreignEntity = Entity::create([
                'organization_id' => $this->otherOrg->id,
                'entity_type_id' => $foreignType->id,
                'entity_code' => 'ENT-FGN',
                'name' => 'Foreign Subsidiary',
                'status' => 'active',
                'level' => 1,
                'created_by' => $this->otherActor->id,
            ]);

            $this->foreignLibraryItem = $this->libraryService->create([
                'title' => 'Unauthorized Wire Transfer Fraud',
                'description' => 'Foreign tenant master risk definition for wire transfer fraud.',
                'category_id' => $this->foreignCategory->id,
                'risk_source' => 'external',
                'basel_l1_event_type' => 'external_fraud',
                'basel_l2_event_type' => 'theft_and_fraud_external',
            ], $this->otherActor);
        });

        TenantContext::set($this->organization->id);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /**
     * @param  list<string>  $permissions
     */
    protected function userWithPermissions(array $permissions, string $email = 'limited@example.test'): User
    {
        $user = User::create([
            'name' => 'Limited User',
            'email' => $email,
            'password' => Hash::make('password'),
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);

        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function validLibraryPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Core Banking System Outage',
            'description' => 'Unplanned unavailability of the core banking platform disrupting customer transactions and settlement.',
            'category_id' => $this->category->id,
            'risk_source' => 'systems',
            'basel_l1_event_type' => 'business_disruption',
            'basel_l2_event_type' => 'systems_failure',
            'typical_causes' => [
                ['cause' => 'Hardware failure in primary data center'],
                ['cause' => 'Unpatched core software defect'],
            ],
            'typical_consequences' => [
                ['consequence' => 'Inability to process customer payments', 'impact_domain' => 'Operational'],
                ['consequence' => 'Regulatory sanction for SLA breach', 'impact_domain' => 'Regulatory'],
            ],
            'suggested_controls' => [
                ['title' => 'Active-active DR failover cluster', 'control_type' => 'preventive'],
            ],
            'suggested_kris' => [
                ['name' => 'Core platform uptime %', 'metric_unit' => '%'],
            ],
        ], $overrides);
    }
}
