<?php

namespace Tests\Feature\Process;

use App\Models\BusinessProcess;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ProcessHierarchyAndCrudTest extends ProcessTestCase
{
    public function test_create_top_level_process_with_and_without_process_group(): void
    {
        $group = $this->groupService->create([
            'code' => 'PG-PAY',
            'name' => 'Payments',
            'business_unit_id' => $this->unit->id,
        ], $this->actor);

        // 1. With a process group
        $withGroupRes = $this->actingAs($this->actor)
            ->post(route('risk.processes.store'), [
                'level' => 'process',
                'process_group_id' => $group->id,
                'business_unit_id' => $this->unit->id,
                'code' => 'PRC-001',
                'apqc_code' => '9.2.1',
                'name' => 'Domestic Wire Settlement',
                'description' => 'RTGS and NIBSS domestic wire settlement.',
                'owner_id' => $this->actor->id,
                'criticality' => 'critical',
            ]);

        $procWithGroup = BusinessProcess::query()
            ->where('organization_id', $this->organization->id)
            ->where('code', 'PRC-001')
            ->first();

        $this->assertNotNull($procWithGroup);
        $withGroupRes->assertRedirect(route('risk.processes.show', $procWithGroup));
        $this->assertNull($procWithGroup->parent_id);
        $this->assertSame($group->id, $procWithGroup->process_group_id);
        $this->assertSame($this->unit->id, $procWithGroup->business_unit_id);
        $this->assertSame('critical', $procWithGroup->criticality);
        $this->assertTrue($procWithGroup->is_active);

        $this->assertDatabaseHas('risk_audit_trail', [
            'organization_id' => $this->organization->id,
            'entity_type' => 'business_process',
            'entity_id' => $procWithGroup->id,
            'action_type' => 'process_created',
        ]);

        // 2. Without a process group (and without department)
        $withoutGroupRes = $this->actingAs($this->actor)
            ->post(route('risk.processes.store'), [
                'level' => 'process',
                'process_group_id' => null,
                'business_unit_id' => null,
                'name' => 'Standalone Governance Review',
                'criticality' => 'medium',
            ]);

        $procWithoutGroup = BusinessProcess::query()
            ->where('organization_id', $this->organization->id)
            ->where('name', 'Standalone Governance Review')
            ->first();

        $this->assertNotNull($procWithoutGroup);
        $withoutGroupRes->assertRedirect(route('risk.processes.show', $procWithoutGroup));
        $this->assertNull($procWithoutGroup->parent_id);
        $this->assertNull($procWithoutGroup->process_group_id);
        $this->assertNull($procWithoutGroup->business_unit_id);
        $this->assertStringStartsWith('PRC-', $procWithoutGroup->code);
    }

    public function test_create_sub_process_under_valid_top_level_parent_succeeds_and_preserves_rcsa_invariant(): void
    {
        $group = $this->groupService->create([
            'code' => 'PG-KYC',
            'name' => 'Customer Onboarding',
            'business_unit_id' => $this->unit->id,
        ], $this->actor);

        $parent = $this->processService->create([
            'code' => 'PRC-KYC',
            'name' => 'Account Opening',
            'process_group_id' => $group->id,
            'business_unit_id' => $this->unit->id,
            'criticality' => 'high',
        ], $this->actor);

        $response = $this->actingAs($this->actor)
            ->post(route('risk.processes.store'), [
                'level' => 'sub_process',
                'parent_id' => $parent->id,
                'name' => 'Sanctions & PEP Screening',
                'criticality' => 'critical',
            ]);

        $subProcess = BusinessProcess::query()
            ->where('organization_id', $this->organization->id)
            ->where('name', 'Sanctions & PEP Screening')
            ->first();

        $this->assertNotNull($subProcess);
        $response->assertRedirect(route('risk.processes.show', $subProcess));
        $this->assertSame($parent->id, $subProcess->parent_id);
        $this->assertSame($group->id, $subProcess->process_group_id);
        $this->assertSame($this->unit->id, $subProcess->business_unit_id);
        $this->assertStringStartsWith('SUB-', $subProcess->code);

        // RCSA v2 invariant: scopeTopLevel() returns ONLY parent_id IS NULL
        $topLevelIds = BusinessProcess::query()
            ->where('organization_id', $this->organization->id)
            ->topLevel()
            ->pluck('id')
            ->all();

        $this->assertContains($parent->id, $topLevelIds);
        $this->assertNotContains($subProcess->id, $topLevelIds);
    }

    public function test_sub_process_without_parent_or_nested_under_sub_process_is_rejected_with_422(): void
    {
        $parent = $this->processService->create([
            'code' => 'PRC-TOP',
            'name' => 'Top Process',
        ], $this->actor);

        $subProcess = $this->processService->create([
            'level' => 'sub_process',
            'parent_id' => $parent->id,
            'code' => 'SUB-LVL2',
            'name' => 'Level 2 Sub-Process',
        ], $this->actor);

        // 1. Sub-process without a parent -> 422
        $this->actingAs($this->actor)
            ->postJson(route('risk.processes.store'), [
                'level' => 'sub_process',
                'parent_id' => null,
                'name' => 'Orphan Sub-Process',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parent_id']);

        // 2. 3rd-level nesting (parent_id points to a sub-process) -> 422
        $this->actingAs($this->actor)
            ->postJson(route('risk.processes.store'), [
                'level' => 'sub_process',
                'parent_id' => $subProcess->id,
                'name' => 'Illegal Third-Level Sub-Process',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parent_id']);

        $this->assertDatabaseMissing('business_processes', [
            'organization_id' => $this->organization->id,
            'name' => 'Illegal Third-Level Sub-Process',
        ]);
    }

    public function test_reparenting_process_with_children_or_self_parenting_is_rejected_with_422(): void
    {
        $parentA = $this->processService->create([
            'code' => 'PRC-A',
            'name' => 'Process A',
        ], $this->actor);

        $parentB = $this->processService->create([
            'code' => 'PRC-B',
            'name' => 'Process B',
        ], $this->actor);

        $this->processService->create([
            'level' => 'sub_process',
            'parent_id' => $parentA->id,
            'code' => 'SUB-A1',
            'name' => 'Sub-Process A1',
        ], $this->actor);

        // 1. Cannot reparent Process A under Process B because Process A already has child sub-processes
        $this->actingAs($this->actor)
            ->putJson(route('risk.processes.update', $parentA), [
                'level' => 'sub_process',
                'parent_id' => $parentB->id,
                'code' => 'PRC-A',
                'name' => 'Process A',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parent_id']);

        $this->assertNull($parentA->refresh()->parent_id);

        // 2. Cannot make Process B its own parent
        $this->actingAs($this->actor)
            ->putJson(route('risk.processes.update', $parentB), [
                'level' => 'sub_process',
                'parent_id' => $parentB->id,
                'code' => 'PRC-B',
                'name' => 'Process B',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parent_id']);

        $this->assertNull($parentB->refresh()->parent_id);
    }

    public function test_update_and_toggle_active_write_audit_trail_entries(): void
    {
        $process = $this->processService->create([
            'code' => 'PRC-UPD',
            'name' => 'Card Issuance',
            'criticality' => 'medium',
        ], $this->actor);

        // Update
        $this->actingAs($this->actor)
            ->put(route('risk.processes.update', $process), [
                'level' => 'process',
                'code' => 'PRC-UPD',
                'name' => 'Card Issuance & Personalisation',
                'business_unit_id' => $this->secondUnit->id,
                'owner_id' => $this->actor->id,
                'criticality' => 'high',
            ])
            ->assertRedirect(route('risk.processes.show', $process));

        $process->refresh();
        $this->assertSame('Card Issuance & Personalisation', $process->name);
        $this->assertSame('high', $process->criticality);
        $this->assertSame($this->secondUnit->id, $process->business_unit_id);

        $this->assertDatabaseHas('risk_audit_trail', [
            'organization_id' => $this->organization->id,
            'entity_type' => 'business_process',
            'entity_id' => $process->id,
            'action_type' => 'process_updated',
        ]);

        // Deactivate via toggleActive
        $this->actingAs($this->actor)
            ->post(route('risk.processes.toggle-active', $process))
            ->assertRedirect();

        $this->assertFalse($process->refresh()->is_active);
        $this->assertDatabaseHas('risk_audit_trail', [
            'organization_id' => $this->organization->id,
            'entity_type' => 'business_process',
            'entity_id' => $process->id,
            'action_type' => 'process_deactivated',
        ]);

        // Reactivate via toggleActive
        $this->actingAs($this->actor)
            ->post(route('risk.processes.toggle-active', $process))
            ->assertRedirect();

        $this->assertTrue($process->refresh()->is_active);
        $this->assertDatabaseHas('risk_audit_trail', [
            'organization_id' => $this->organization->id,
            'entity_type' => 'business_process',
            'entity_id' => $process->id,
            'action_type' => 'process_reactivated',
        ]);
    }

    public function test_entity_attachment_and_detachment_record_pivot_and_audit_trail_and_block_cross_tenant(): void
    {
        $process = $this->processService->create([
            'code' => 'PRC-ENT',
            'name' => 'Branch Cash Handling',
            'entity_id' => $this->entityA->id,
        ], $this->actor);

        $this->assertDatabaseHas('entity_business_process', [
            'organization_id' => $this->organization->id,
            'entity_id' => $this->entityA->id,
            'business_process_id' => $process->id,
            'is_primary' => true,
        ]);

        $this->assertDatabaseHas('risk_audit_trail', [
            'organization_id' => $this->organization->id,
            'entity_type' => 'business_process',
            'entity_id' => $process->id,
            'action_type' => 'process_attached_to_entity',
        ]);

        $this->processService->detachFromEntity($this->entityA, $process, $this->actor);

        $this->assertSame(0, DB::table('entity_business_process')
            ->where('business_process_id', $process->id)
            ->count());

        $this->assertDatabaseHas('risk_audit_trail', [
            'organization_id' => $this->organization->id,
            'entity_type' => 'business_process',
            'entity_id' => $process->id,
            'action_type' => 'process_detached_from_entity',
        ]);

        // Cross-tenant attach must throw
        $this->expectException(InvalidArgumentException::class);
        $this->processService->attachToEntity($this->foreignEntity, $process, $this->actor);
    }

    public function test_a_partial_update_of_a_sub_process_keeps_its_parent_and_a_hierarchy_violation_is_a_form_error_not_a_500(): void
    {
        $parent = $this->processService->create(['code' => 'PRC-PAR', 'name' => 'Payments'], $this->actor);
        $sub = $this->processService->create([
            'code' => 'PRC-SUB',
            'name' => 'Card Settlement',
            'level' => 'sub_process',
            'parent_id' => $parent->id,
        ], $this->actor);

        // Gate-2 #7: a partial update that omits level and parent_id used to throw
        // InvalidArgumentException (HTTP 500). It now keeps the existing parent.
        $updated = $this->processService->update($sub, ['name' => 'Card Settlement (renamed)'], $this->actor);
        $this->assertSame($parent->id, $updated->parent_id);
        $this->assertSame('Card Settlement (renamed)', $updated->name);

        // A rule only the service can see (converting a parent that HAS children into
        // a sub-process) surfaces as a validation error on the form, not a 500.
        $other = $this->processService->create(['code' => 'PRC-OTH', 'name' => 'Treasury'], $this->actor);

        $this->actingAs($this->actor)
            ->put(route('risk.processes.update', $parent), [
                'name' => $parent->name,
                'level' => 'sub_process',
                'parent_id' => $other->id,
            ])
            ->assertSessionHasErrors('parent_id');

        $this->assertNull($parent->fresh()->parent_id);
    }
}
