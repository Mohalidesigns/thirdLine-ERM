<?php

namespace Tests\Feature\Process;

use App\Models\BusinessProcess;
use App\Models\BusinessUnit;
use App\Models\Entity;
use App\Models\EntityType;
use App\Models\Organization;
use App\Models\ProcessGroup;
use App\Models\RiskCategory;
use App\Models\User;
use App\Services\ProcessGroupService;
use App\Services\ProcessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

abstract class ProcessTestCase extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected BusinessUnit $unit;

    protected BusinessUnit $secondUnit;

    protected EntityType $entityType;

    protected Entity $entityA;

    protected Organization $otherOrg;

    protected User $otherActor;

    protected BusinessUnit $foreignUnit;

    protected RiskCategory $foreignCategory;

    protected Entity $foreignEntity;

    protected ProcessGroup $foreignGroup;

    protected BusinessProcess $foreignProcess;

    protected ProcessGroupService $groupService;

    protected ProcessService $processService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDomainFixtures();
        $this->groupService = app(ProcessGroupService::class);
        $this->processService = app(ProcessService::class);

        $permissions = [
            'process.view',
            'process.create',
            'process.update',
            'process_group.manage',
            'risk.view',
            'risk.create',
            'entity.view',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
            $this->actor->givePermissionTo($permission);
        }

        Role::findOrCreate('chief-risk-officer');
        $this->actor->assignRole('chief-risk-officer');

        $this->unit = BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'code' => 'OPS',
            'name' => 'Operations',
        ]);

        $this->secondUnit = BusinessUnit::create([
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

        $this->otherOrg = Organization::create([
            'name' => 'Foreign Bank PLC',
            'short_name' => 'FGNB',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        TenantContext::actingAs($this->otherOrg->id, function () use ($permissions): void {
            $this->otherActor = User::create([
                'name' => 'Foreign Process Officer',
                'email' => 'foreign-process-officer@example.test',
                'password' => Hash::make('password'),
                'organization_id' => $this->otherOrg->id,
                'is_active' => true,
            ]);

            foreach ($permissions as $permission) {
                $this->otherActor->givePermissionTo($permission);
            }
            $this->otherActor->assignRole('chief-risk-officer');

            $this->foreignUnit = BusinessUnit::create([
                'organization_id' => $this->otherOrg->id,
                'code' => 'FGN-OPS',
                'name' => 'Foreign Operations',
            ]);

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

            $this->foreignGroup = $this->groupService->create([
                'code' => 'PG-FGN',
                'name' => 'Foreign Payment Clearing',
                'business_unit_id' => $this->foreignUnit->id,
                'owner_id' => $this->otherActor->id,
            ], $this->otherActor);

            $this->foreignProcess = $this->processService->create([
                'code' => 'PRC-FGN',
                'name' => 'Foreign Settlement Process',
                'process_group_id' => $this->foreignGroup->id,
                'business_unit_id' => $this->foreignUnit->id,
                'owner_id' => $this->otherActor->id,
                'criticality' => 'critical',
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
    protected function userWithPermissions(array $permissions, string $email = 'limited-process@example.test'): User
    {
        $user = User::create([
            'name' => 'Limited Process User',
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
}
