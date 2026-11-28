<?php

namespace Tests\Feature\Process;

use App\Authorization\RiskPermissionCatalog;

class ProcessTenancyAndAuthorizationTest extends ProcessTestCase
{
    public function test_tenant_a_cannot_view_edit_or_toggle_tenant_b_process_or_process_group(): void
    {
        // A foreign row must be INVISIBLE, not forbidden: OrganizationScope resolves the
        // binding to nothing, so every route answers 404. A 403 would confirm the row
        // exists in another tenant, which is itself a leak.
        $processBefore = $this->foreignProcess->fresh()->getAttributes();
        $groupBefore = $this->foreignGroup->fresh()->getAttributes();

        $this->actingAs($this->actor)->get(route('risk.processes.show', $this->foreignProcess))->assertNotFound();
        $this->actingAs($this->actor)->get(route('risk.processes.edit', $this->foreignProcess))->assertNotFound();
        $this->actingAs($this->actor)->put(route('risk.processes.update', $this->foreignProcess), [
            'name' => 'Cross-Tenant Tamper',
        ])->assertNotFound();
        $this->actingAs($this->actor)->post(route('risk.processes.toggle-active', $this->foreignProcess))->assertNotFound();

        $this->actingAs($this->actor)->put(route('risk.process-groups.update', $this->foreignGroup), [
            'name' => 'Cross-Tenant Group Tamper',
        ])->assertNotFound();
        $this->actingAs($this->actor)->post(route('risk.process-groups.deactivate', $this->foreignGroup))->assertNotFound();
        $this->actingAs($this->actor)->post(route('risk.process-groups.toggle-active', $this->foreignGroup))->assertNotFound();

        // And nothing moved: the foreign rows are byte-for-byte what they were.
        $this->assertSame($processBefore, $this->foreignProcess->fresh()->getAttributes());
        $this->assertSame($groupBefore, $this->foreignGroup->fresh()->getAttributes());
    }

    public function test_tenant_a_cannot_attach_tenant_b_group_unit_parent_owner_or_entity_when_creating_process(): void
    {
        $this->actingAs($this->actor)
            ->postJson(route('risk.processes.store'), [
                'level' => 'sub_process',
                'parent_id' => $this->foreignProcess->id,
                'process_group_id' => $this->foreignGroup->id,
                'business_unit_id' => $this->foreignUnit->id,
                'owner_id' => $this->otherActor->id,
                'entity_id' => $this->foreignEntity->id,
                'name' => 'Cross-Tenant Reference Attempt',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'parent_id',
                'process_group_id',
                'business_unit_id',
                'owner_id',
                'entity_id',
            ]);
    }

    public function test_missing_permissions_return_403_on_all_process_and_process_group_routes(): void
    {
        $group = $this->groupService->create([
            'code' => 'PG-AUTH',
            'name' => 'Auth Test Group',
        ], $this->actor);

        $process = $this->processService->create([
            'code' => 'PRC-AUTH',
            'name' => 'Auth Test Process',
            'process_group_id' => $group->id,
        ], $this->actor);

        // User with NO permissions
        $noPerms = $this->userWithPermissions([], 'noperms@example.test');
        $this->actingAs($noPerms)->get(route('risk.processes.dashboard'))->assertForbidden();
        $this->actingAs($noPerms)->get(route('risk.processes.index'))->assertForbidden();
        $this->actingAs($noPerms)->get(route('risk.processes.show', $process))->assertForbidden();
        $this->actingAs($noPerms)->get(route('risk.processes.export'))->assertForbidden();
        $this->actingAs($noPerms)->get(route('risk.processes.export.csv'))->assertForbidden();
        $this->actingAs($noPerms)->get(route('risk.processes.export.pdf'))->assertForbidden();
        $this->actingAs($noPerms)->get(route('risk.process-groups.index'))->assertForbidden();

        // User with ONLY process.view (missing create, update, process_group.manage)
        $viewerOnly = $this->userWithPermissions(['process.view'], 'viewer@example.test');
        $this->actingAs($viewerOnly)->get(route('risk.processes.dashboard'))->assertOk();
        $this->actingAs($viewerOnly)->get(route('risk.processes.index'))->assertOk();
        $this->actingAs($viewerOnly)->get(route('risk.processes.show', $process))->assertOk();
        $this->actingAs($viewerOnly)->get(route('risk.process-groups.index'))->assertOk();

        $this->actingAs($viewerOnly)->get(route('risk.processes.create'))->assertForbidden();
        $this->actingAs($viewerOnly)->post(route('risk.processes.store'), ['name' => 'Blocked'])->assertForbidden();
        $this->actingAs($viewerOnly)->get(route('risk.processes.edit', $process))->assertForbidden();
        $this->actingAs($viewerOnly)->put(route('risk.processes.update', $process), ['name' => 'Blocked'])->assertForbidden();
        $this->actingAs($viewerOnly)->post(route('risk.processes.toggle-active', $process))->assertForbidden();

        $this->actingAs($viewerOnly)->post(route('risk.process-groups.store'), ['name' => 'Blocked'])->assertForbidden();
        $this->actingAs($viewerOnly)->put(route('risk.process-groups.update', $group), ['name' => 'Blocked'])->assertForbidden();
        $this->actingAs($viewerOnly)->post(route('risk.process-groups.deactivate', $group))->assertForbidden();
        $this->actingAs($viewerOnly)->post(route('risk.process-groups.toggle-active', $group))->assertForbidden();
    }

    public function test_permission_catalog_withholds_process_permissions_from_risk_champions_and_respects_role_matrix(): void
    {
        $catalog = new RiskPermissionCatalog;

        // Owner decision 2026-11-27: champions get NO process permission. Their menu
        // is KRI / RCSA / Loss Events / Workflows only, and the process Show page
        // carries register data they cannot otherwise read.
        foreach (['risk-champion', 'risk-champions'] as $role) {
            $perms = $catalog->permissionsFor($role);
            $this->assertNotContains('process.view', $perms, "{$role} must NOT have process.view");
            $this->assertNotContains('process.create', $perms);
            $this->assertNotContains('process.update', $perms);
            $this->assertNotContains('process_group.manage', $perms);
        }

        // risk-analyst has process.view/create/update but NOT process_group.manage
        $analystPerms = $catalog->permissionsFor('risk-analyst');
        $this->assertContains('process.view', $analystPerms);
        $this->assertContains('process.create', $analystPerms);
        $this->assertContains('process.update', $analystPerms);
        $this->assertNotContains('process_group.manage', $analystPerms);

        // chief-risk-officer & risk-manager have all 4
        foreach (['chief-risk-officer', 'risk-manager'] as $role) {
            $perms = $catalog->permissionsFor($role);
            $this->assertContains('process.view', $perms);
            $this->assertContains('process.create', $perms);
            $this->assertContains('process.update', $perms);
            $this->assertContains('process_group.manage', $perms);
        }
    }
}
