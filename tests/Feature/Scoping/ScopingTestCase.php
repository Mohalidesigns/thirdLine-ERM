<?php

namespace Tests\Feature\Scoping;

use App\Models\Entity;
use App\Models\EntityType;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Shared fixtures for the Phase 3.1 scoping tests: one tenant with a
 * three-level hierarchy, a second tenant with one entity, and users at
 * the permission levels the policy distinguishes.
 *
 *   group (L0)
 *    ├── retail (L1)
 *    │    └── branch (L2)
 *    └── treasury (L1)
 */
abstract class ScopingTestCase extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected EntityType $groupType;

    protected EntityType $unitType;

    protected EntityType $branchType;

    protected Entity $group;

    protected Entity $retail;

    protected Entity $branch;

    protected Entity $treasury;

    protected Organization $otherOrg;

    protected EntityType $otherType;

    protected Entity $foreign;

    protected User $otherActor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDomainFixtures();

        foreach (['entity.view', 'entity.create', 'entity.edit', 'entity.delete', 'risk.view'] as $permission) {
            Permission::findOrCreate($permission);
        }

        // A role outside authorization.full_org_roles, so a pinned user is
        // actually confined to their subtree.
        Role::findOrCreate('branch-manager');

        $this->groupType = $this->type('GROUP', 'Group', 0);
        $this->unitType = $this->type('UNIT', 'Business Unit', 1);
        $this->branchType = $this->type('BRANCH', 'Branch', 2);

        $this->group = $this->entity('Group HQ', $this->groupType);
        $this->retail = $this->entity('Retail Banking', $this->unitType, $this->group);
        $this->branch = $this->entity('Lagos Island Branch', $this->branchType, $this->retail);
        $this->treasury = $this->entity('Treasury', $this->unitType, $this->group);

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

        TenantContext::bypass(function () {
            $this->otherType = EntityType::create([
                'organization_id' => $this->otherOrg->id,
                'code' => 'UNIT',
                'name' => 'Business Unit',
                'level' => 1,
                'is_active' => true,
            ]);
            $this->foreign = Entity::create([
                'organization_id' => $this->otherOrg->id,
                'entity_type_id' => $this->otherType->id,
                'entity_code' => 'ENT-FOREIGN',
                'name' => 'Theirs',
                'status' => 'active',
                'level' => 1,
                'created_by' => $this->otherActor->id,
            ]);
        }, 'test fixture');
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    protected function type(string $code, string $name, int $level): EntityType
    {
        return EntityType::create([
            'organization_id' => $this->organization->id,
            'code' => $code,
            'name' => $name,
            'level' => $level,
            'is_active' => true,
            'sort_order' => $level,
        ]);
    }

    protected function entity(string $name, EntityType $type, ?Entity $parent = null, array $attributes = []): Entity
    {
        static $sequence = 0;
        $sequence++;

        return Entity::create(array_merge([
            'organization_id' => $this->organization->id,
            'entity_type_id' => $type->id,
            'parent_id' => $parent?->id,
            'entity_code' => sprintf('ENT-T%03d', $sequence),
            'name' => $name,
            'status' => 'active',
            'level' => $type->level,
            'created_by' => $this->actor->id,
        ], $attributes));
    }

    /**
     * @param  list<string>  $permissions
     */
    protected function userWith(array $permissions, ?Entity $pinnedTo = null, string $email = 'scoped@example.test'): User
    {
        $user = User::create([
            'name' => 'Scoped User',
            'email' => $email,
            'password' => Hash::make('password'),
            'organization_id' => $this->organization->id,
            'scope_entity_id' => $pinnedTo?->id,
            'is_active' => true,
        ]);

        $user->assignRole('branch-manager');
        $user->givePermissionTo($permissions);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    protected function validPayload(array $overrides = []): array
    {
        return array_merge([
            'entity_type_id' => $this->branchType->id,
            'name' => 'Abuja Central Branch',
            'parent_id' => $this->retail->id,
            'description' => 'A branch.',
            'owner_id' => $this->actor->id,
            'delegate_owner_id' => null,
            'status' => 'active',
            'regulatory_frameworks' => ['CBN ORMS'],
            'risk_appetite_level' => 'cautious',
            'category_appetites' => ['credit' => 'averse', 'operational' => null],
        ], $overrides);
    }
}
