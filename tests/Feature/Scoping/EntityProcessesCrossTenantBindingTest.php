<?php

namespace Tests\Feature\Scoping;

use App\Models\BusinessProcess;
use App\Models\RiskLibraryItem;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * ADR 0028 P3 — the {scoping} route parameter on the new entity-process and
 * attach-from-library routes must fail closed for another tenant's entity.
 */
class EntityProcessesCrossTenantBindingTest extends ScopingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $perms = ['entity.view', 'entity.create', 'entity.edit', 'risk.view', 'risk.create',
            'risk_library.view', 'process.view', 'process.create', 'process.update'];
        foreach ($perms as $perm) {
            Permission::findOrCreate($perm);
        }
        $this->actor->givePermissionTo($perms);
        $this->actingAs($this->actor);
    }

    #[Test]
    public function foreign_entity_cannot_be_bound_on_any_new_scoping_process_or_library_route(): void
    {
        $process = BusinessProcess::create([
            'organization_id' => $this->organization->id,
            'code' => 'PRC-X1',
            'name' => 'Own Process',
            'is_active' => true,
        ]);

        $this->getJson(route('risk.scoping.attachable-processes', $this->foreign))->assertNotFound();
        $this->post(route('risk.scoping.attach-process', $this->foreign), ['business_process_id' => $process->id])->assertNotFound();
        $this->post(route('risk.scoping.store-process', $this->foreign), ['name' => 'Smuggled', 'criticality' => 'high'])->assertNotFound();
        $this->delete(route('risk.scoping.detach-process', ['scoping' => $this->foreign, 'process' => $process->id]))->assertNotFound();
        $this->post(route('risk.scoping.attach-library-risks', $this->foreign), ['library_item_ids' => [1]])->assertNotFound();

        $this->assertDatabaseMissing('entity_business_process', ['entity_id' => $this->foreign->id]);
        $this->assertDatabaseMissing('business_processes', ['name' => 'Smuggled']);
        $this->assertSame(0, TenantContext::bypass(
            fn () => \App\Models\Risk::query()->where('entity_id', $this->foreign->id)->count(), 'assert'
        ));
    }

    #[Test]
    public function foreign_process_cannot_be_detached_through_own_entity(): void
    {
        $foreignProcess = TenantContext::bypass(fn () => BusinessProcess::create([
            'organization_id' => $this->otherOrg->id,
            'code' => 'PRC-F1',
            'name' => 'Foreign Process',
            'is_active' => true,
        ]), 'fixture');

        $this->delete(route('risk.scoping.detach-process', ['scoping' => $this->retail, 'process' => $foreignProcess->id]))
            ->assertStatus(404);
        $this->assertTrue(TenantContext::bypass(fn () => BusinessProcess::query()->whereKey($foreignProcess->id)->exists(), 'assert'));
        $this->assertNull(RiskLibraryItem::query()->first());
    }
}
