<?php

namespace Tests\Feature\Process;

use App\Models\ProcessGroup;
use Inertia\Testing\AssertableInertia as Assert;

class ProcessGroupsCrudTest extends ProcessTestCase
{
    public function test_create_department_scoped_and_enterprise_process_groups_with_audit_trail(): void
    {
        // 1. Department-scoped process group
        $deptResponse = $this->actingAs($this->actor)
            ->post(route('risk.process-groups.store'), [
                'code' => 'PG-OPS-01',
                'name' => 'Payment Clearing & Settlement',
                'description' => 'Domestic and cross-border clearing operations.',
                'apqc_code' => '9.2',
                'business_unit_id' => $this->unit->id,
                'owner_id' => $this->actor->id,
                'sort_order' => 1,
            ]);

        $deptResponse->assertRedirect(route('risk.process-groups.index'));

        $deptGroup = ProcessGroup::query()
            ->where('organization_id', $this->organization->id)
            ->where('code', 'PG-OPS-01')
            ->first();

        $this->assertNotNull($deptGroup);
        $this->assertSame($this->unit->id, $deptGroup->business_unit_id);
        $this->assertSame($this->actor->id, $deptGroup->owner_id);
        $this->assertSame('9.2', $deptGroup->apqc_code);
        $this->assertSame(ProcessGroup::STATUS_ACTIVE, $deptGroup->status);
        $this->assertSame($this->actor->id, $deptGroup->created_by);

        $this->assertDatabaseHas('risk_audit_trail', [
            'organization_id' => $this->organization->id,
            'entity_type' => 'process_group',
            'entity_id' => $deptGroup->id,
            'action_type' => 'process_group_created',
        ]);

        // 2. Enterprise / cross-departmental process group (business_unit_id = null) with auto-generated code
        $enterpriseResponse = $this->actingAs($this->actor)
            ->post(route('risk.process-groups.store'), [
                'name' => 'Customer Onboarding & KYC',
                'description' => 'Cross-departmental onboarding value chain.',
                'business_unit_id' => null,
                'sort_order' => 2,
            ]);

        $enterpriseResponse->assertRedirect(route('risk.process-groups.index'));

        $enterpriseGroup = ProcessGroup::query()
            ->where('organization_id', $this->organization->id)
            ->where('name', 'Customer Onboarding & KYC')
            ->first();

        $this->assertNotNull($enterpriseGroup);
        $this->assertNull($enterpriseGroup->business_unit_id);
        $this->assertStringStartsWith('PG-', $enterpriseGroup->code);
        $this->assertSame(ProcessGroup::STATUS_ACTIVE, $enterpriseGroup->status);

        $this->assertDatabaseHas('risk_audit_trail', [
            'organization_id' => $this->organization->id,
            'entity_type' => 'process_group',
            'entity_id' => $enterpriseGroup->id,
            'action_type' => 'process_group_created',
        ]);
    }

    public function test_update_deactivate_and_reactivate_process_group_writes_audit_trail(): void
    {
        $group = $this->groupService->create([
            'code' => 'PG-010',
            'name' => 'Treasury Operations',
            'business_unit_id' => $this->unit->id,
        ], $this->actor);

        // Update
        $updateResponse = $this->actingAs($this->actor)
            ->put(route('risk.process-groups.update', $group), [
                'code' => 'PG-010',
                'name' => 'Treasury & Liquidity Operations',
                'apqc_code' => '9.7',
                'business_unit_id' => null,
                'owner_id' => $this->actor->id,
                'sort_order' => 5,
            ]);

        $updateResponse->assertRedirect(route('risk.process-groups.index'));

        $group->refresh();
        $this->assertSame('Treasury & Liquidity Operations', $group->name);
        $this->assertSame('9.7', $group->apqc_code);
        $this->assertNull($group->business_unit_id);
        $this->assertSame(5, $group->sort_order);

        $this->assertDatabaseHas('risk_audit_trail', [
            'organization_id' => $this->organization->id,
            'entity_type' => 'process_group',
            'entity_id' => $group->id,
            'action_type' => 'process_group_updated',
        ]);

        // Deactivate via toggle-active
        $deactivateResponse = $this->actingAs($this->actor)
            ->post(route('risk.process-groups.toggle-active', $group));

        $deactivateResponse->assertRedirect(route('risk.process-groups.index'));
        $group->refresh();
        $this->assertSame(ProcessGroup::STATUS_INACTIVE, $group->status);
        $this->assertFalse($group->isActive());

        $this->assertDatabaseHas('risk_audit_trail', [
            'organization_id' => $this->organization->id,
            'entity_type' => 'process_group',
            'entity_id' => $group->id,
            'action_type' => 'process_group_deactivated',
        ]);

        // Reactivate via toggle-active
        $reactivateResponse = $this->actingAs($this->actor)
            ->post(route('risk.process-groups.toggle-active', $group));

        $reactivateResponse->assertRedirect(route('risk.process-groups.index'));
        $group->refresh();
        $this->assertSame(ProcessGroup::STATUS_ACTIVE, $group->status);
        $this->assertTrue($group->isActive());

        $this->assertDatabaseHas('risk_audit_trail', [
            'organization_id' => $this->organization->id,
            'entity_type' => 'process_group',
            'entity_id' => $group->id,
            'action_type' => 'process_group_reactivated',
        ]);

        // Direct deactivate endpoint also works
        $this->actingAs($this->actor)
            ->post(route('risk.process-groups.deactivate', $group))
            ->assertRedirect(route('risk.process-groups.index'));

        $this->assertSame(ProcessGroup::STATUS_INACTIVE, $group->refresh()->status);
    }

    public function test_unique_org_code_enforced_per_org_but_same_code_allowed_in_another_org(): void
    {
        // Foreign org already has PG-FGN; Tenant A CAN create PG-FGN
        $this->actingAs($this->actor)
            ->postJson(route('risk.process-groups.store'), [
                'code' => 'PG-FGN',
                'name' => 'Tenant A Group Using Same Code',
            ])
            ->assertRedirect(route('risk.process-groups.index'));

        $this->assertSame(
            1,
            ProcessGroup::query()
                ->where('organization_id', $this->organization->id)
                ->where('code', 'PG-FGN')
                ->count()
        );

        // Duplicate code within the same org MUST be rejected (422)
        $this->actingAs($this->actor)
            ->postJson(route('risk.process-groups.store'), [
                'code' => 'PG-FGN',
                'name' => 'Duplicate Code In Same Org',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    public function test_rejects_cross_tenant_business_unit_and_owner_on_store_and_update(): void
    {
        // Store with foreign business_unit_id -> 422
        $this->actingAs($this->actor)
            ->postJson(route('risk.process-groups.store'), [
                'code' => 'PG-XTEN-1',
                'name' => 'Cross-Tenant BU Attempt',
                'business_unit_id' => $this->foreignUnit->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['business_unit_id']);

        // Store with foreign owner_id -> 422
        $this->actingAs($this->actor)
            ->postJson(route('risk.process-groups.store'), [
                'code' => 'PG-XTEN-2',
                'name' => 'Cross-Tenant Owner Attempt',
                'owner_id' => $this->otherActor->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['owner_id']);

        // Update with foreign business_unit_id & owner_id -> 422
        $group = $this->groupService->create([
            'code' => 'PG-OWN-1',
            'name' => 'Valid Group',
        ], $this->actor);

        $this->actingAs($this->actor)
            ->putJson(route('risk.process-groups.update', $group), [
                'code' => 'PG-OWN-1',
                'name' => 'Valid Group Updated',
                'business_unit_id' => $this->foreignUnit->id,
                'owner_id' => $this->otherActor->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['business_unit_id', 'owner_id']);

        $this->assertNull($group->refresh()->business_unit_id);
        $this->assertNull($group->owner_id);
    }

    public function test_process_groups_index_renders_counts_and_does_not_leak_other_tenant(): void
    {
        $activeGroup = $this->groupService->create([
            'code' => 'PG-A',
            'name' => 'Active Group',
            'business_unit_id' => $this->unit->id,
        ], $this->actor);

        $inactiveGroup = $this->groupService->create([
            'code' => 'PG-B',
            'name' => 'Inactive Group',
        ], $this->actor);
        $this->groupService->deactivate($inactiveGroup, $this->actor);

        $this->processService->create([
            'code' => 'PRC-A1',
            'name' => 'Top-level Process',
            'process_group_id' => $activeGroup->id,
        ], $this->actor);

        $this->actingAs($this->actor)
            ->get(route('risk.process-groups.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ProcessGroups/Index')
                ->where('counts.active', 1)
                ->where('counts.inactive', 1)
                ->where('counts.total', 2)
                ->has('groups', 2)
                ->where('groups.0.code', 'PG-A')
                ->where('groups.0.processes_count', 1)
                ->where('can.manage', true)
            );
    }
}
