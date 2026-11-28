<?php

namespace Tests\Feature\Scoping;

use App\Models\BusinessProcess;
use App\Models\BusinessUnit;
use App\Models\Entity;
use App\Models\ProcessGroup;
use App\Models\Risk;
use App\Models\RiskAuditTrail;
use App\Models\RiskCategory;
use App\Models\RiskLibraryItem;
use App\Services\ProcessService;
use App\Services\RiskLibraryService;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use ThirdLine\Platform\Tenancy\TenantContext;

class EntityProcessesAndLibraryTest extends ScopingTestCase
{
    private ProcessGroup $groupA;

    private BusinessProcess $processA;

    private BusinessProcess $processB;

    private RiskLibraryItem $libraryItemA;

    private RiskLibraryItem $libraryItemB;

    private RiskLibraryItem $retiredLibraryItem;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'risk.create',
            'risk.edit',
            'risk_library.view',
            'process.view',
            'process.create',
            'process.update',
        ] as $perm) {
            Permission::findOrCreate($perm);
        }

        $this->actor->givePermissionTo([
            'entity.view',
            'entity.create',
            'entity.edit',
            'entity.delete',
            'risk.view',
            'risk.create',
            'risk_library.view',
            'process.view',
            'process.create',
            'process.update',
        ]);
        $this->actingAs($this->actor);

        $this->groupA = ProcessGroup::create([
            'organization_id' => $this->organization->id,
            'code' => 'PG-001',
            'name' => 'Payment Clearing & Settlement',
            'status' => ProcessGroup::STATUS_ACTIVE,
            'sort_order' => 1,
        ]);

        $businessUnit = BusinessUnit::query()->where('organization_id', $this->organization->id)->first();

        $this->processA = BusinessProcess::create([
            'organization_id' => $this->organization->id,
            'process_group_id' => $this->groupA->id,
            'business_unit_id' => $businessUnit?->id,
            'code' => 'PRC-0001',
            'name' => 'Payment Clearing',
            'owner_id' => $this->actor->id,
            'criticality' => 'critical',
            'is_active' => true,
        ]);

        $this->processB = BusinessProcess::create([
            'organization_id' => $this->organization->id,
            'process_group_id' => $this->groupA->id,
            'business_unit_id' => $businessUnit?->id,
            'code' => 'PRC-0002',
            'name' => 'Account Opening',
            'owner_id' => $this->actor->id,
            'criticality' => 'high',
            'is_active' => true,
        ]);

        /** @var RiskLibraryService $libraryService */
        $libraryService = app(RiskLibraryService::class);

        $this->libraryItemA = $libraryService->create([
            'title' => 'Payment Settlement Failure',
            'description' => 'Failure or delay in interbank payment settlement.',
            'category_id' => $this->category->id,
            'risk_source' => 'process',
        ], $this->actor);

        $this->libraryItemB = $libraryService->create([
            'title' => 'Unauthorised System Access',
            'description' => 'Unauthorised access to core banking systems.',
            'category_id' => $this->category->id,
            'risk_source' => 'systems',
        ], $this->actor);

        $this->retiredLibraryItem = $libraryService->create([
            'title' => 'Legacy Paper Cheque Fraud',
            'description' => 'Deprecated cheque clearing fraud risk.',
            'category_id' => $this->category->id,
        ], $this->actor);
        $libraryService->retire($this->retiredLibraryItem, 'Superseded by digital clearing risk.', $this->actor);
    }

    #[Test]
    public function show_payload_includes_processes_process_options_can_flags_and_risk_library_provenance(): void
    {
        /** @var ProcessService $processService */
        $processService = app(ProcessService::class);
        $processService->attachToEntity($this->retail, $this->processA, $this->actor, isPrimary: true);

        BusinessProcess::create([
            'organization_id' => $this->organization->id,
            'parent_id' => $this->processA->id,
            'process_group_id' => $this->groupA->id,
            'code' => 'SUB-0001',
            'name' => 'NIBSS Settlement Check',
            'is_active' => true,
        ]);

        /** @var RiskLibraryService $libraryService */
        $libraryService = app(RiskLibraryService::class);
        $risk = $libraryService->attachToTarget($this->libraryItemA, [
            'entity_id' => $this->retail->id,
            'process_id' => $this->processA->id,
            'risk_owner_id' => $this->actor->id,
        ], $this->actor);

        // Advance library item to v2 so has_pending_library_update becomes true.
        $libraryService->update($this->libraryItemA, [
            'title' => 'Payment Settlement Failure (Updated)',
            'description' => 'Updated master description.',
            'category_id' => $this->category->id,
            'change_summary' => 'Clarified CBN settlement SLA.',
        ], $this->actor);

        $this->get(route('risk.scoping.show', $this->retail))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Scoping/Show')
                ->has('processes', 1)
                ->where('processes.0.id', $this->processA->id)
                ->where('processes.0.code', 'PRC-0001')
                ->where('processes.0.process_group_name', 'Payment Clearing & Settlement')
                ->where('processes.0.owner_name', 'Risk Officer')
                ->where('processes.0.sub_processes_count', 1)
                ->where('processes.0.risks_count', 1)
                ->where('processes.0.linked_risks_count', 1)
                ->where('processes.0.is_primary', true)
                ->has('processOptions.processGroups')
                ->has('processOptions.parentProcesses')
                ->has('processOptions.owners')
                ->has('risks', 1)
                ->where('risks.0.id', $risk->id)
                ->where('risks.0.library_item_id', $this->libraryItemA->id)
                ->where('risks.0.library_version', 1)
                ->where('risks.0.library_code', $this->libraryItemA->code)
                ->where('risks.0.has_pending_library_update', true)
                ->where('risks.0.library_has_update', true)
                ->where('risks.0.library_is_retired', false)
                ->where('risks.0.process_name', 'Payment Clearing')
                ->where('can.attachRiskFromLibrary', true)
                ->where('can.viewProcesses', true)
                ->where('can.createProcess', true)
                ->where('can.updateProcess', true));

        // User without process.view or process.create gets null processOptions.
        $viewer = $this->userWith(['entity.view'], email: 'entity-only@example.test');
        $this->actingAs($viewer)
            ->get(route('risk.scoping.show', $this->retail))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('processOptions', null)
                ->where('can.attachRiskFromLibrary', false)
                ->where('can.viewProcesses', false)
                ->where('can.createProcess', false)
                ->where('can.updateProcess', false));
    }

    #[Test]
    public function attach_library_risks_creates_instances_skips_duplicates_and_writes_audit_trail(): void
    {
        $this->post(route('risk.scoping.attach-library-risks', $this->retail), [
            'library_item_ids' => [$this->libraryItemA->id, $this->libraryItemB->id],
            'process_id' => $this->processA->id,
            'risk_owner_id' => $this->actor->id,
        ])
            ->assertRedirect(route('risk.scoping.show', ['scoping' => $this->retail, 'tab' => 'risks']))
            ->assertSessionHas('success');

        $this->assertSame(2, Risk::query()->where('entity_id', $this->retail->id)->count());

        $attached = Risk::query()
            ->where('entity_id', $this->retail->id)
            ->where('library_item_id', $this->libraryItemA->id)
            ->firstOrFail();

        $this->assertSame($this->processA->id, $attached->process_id);
        $this->assertSame(1, $attached->library_version);
        $this->assertNull($attached->inherent_likelihood);
        $this->assertNull($attached->inherent_impact);
        $this->assertDatabaseHas('risk_audit_trail', [
            'entity_type' => $attached->getMorphClass(),
            'entity_id' => $attached->id,
            'action_type' => 'risk_instantiated_from_library',
        ]);

        // Re-attaching the same library item skips gracefully without duplicating.
        $this->postJson(route('risk.scoping.attach-library-risks', $this->retail), [
            'library_item_ids' => [$this->libraryItemA->id],
            'process_id' => $this->processA->id,
        ])
            ->assertCreated()
            ->assertJsonPath('created_count', 0)
            ->assertJsonPath('skipped_count', 1);

        $this->assertSame(2, Risk::query()->where('entity_id', $this->retail->id)->count());
    }

    #[Test]
    public function attach_library_risks_rejects_retired_items_and_cross_tenant_fks(): void
    {
        $this->post(route('risk.scoping.attach-library-risks', $this->retail), [
            'library_item_ids' => [$this->retiredLibraryItem->id],
        ])->assertSessionHasErrors('library_item_ids.0');

        $otherItemId = null;
        $otherProcessId = null;
        TenantContext::bypass(function () use (&$otherItemId, &$otherProcessId): void {
            $otherCategory = RiskCategory::create([
                'organization_id' => $this->otherOrg->id,
                'name' => 'Other Category',
                'code' => 'OTH-CAT',
            ]);
            $otherItem = RiskLibraryItem::create([
                'organization_id' => $this->otherOrg->id,
                'code' => 'RL-0001',
                'title' => 'Foreign Risk',
                'description' => 'Foreign',
                'category_id' => $otherCategory->id,
                'current_version' => 1,
                'status' => RiskLibraryItem::STATUS_ACTIVE,
            ]);
            $otherProcess = BusinessProcess::create([
                'organization_id' => $this->otherOrg->id,
                'code' => 'PRC-9999',
                'name' => 'Foreign Process',
                'is_active' => true,
            ]);
            $otherItemId = $otherItem->id;
            $otherProcessId = $otherProcess->id;
        }, 'cross-tenant fixture');

        $this->post(route('risk.scoping.attach-library-risks', $this->retail), [
            'library_item_ids' => [$otherItemId],
            'process_id' => $otherProcessId,
            'risk_owner_id' => $this->otherActor->id,
        ])->assertSessionHasErrors(['library_item_ids.0', 'process_id', 'risk_owner_id']);
    }

    #[Test]
    public function entity_processes_can_be_attached_created_listed_as_attachable_and_detached_without_deleting_process(): void
    {
        // Attach existing process
        $this->post(route('risk.scoping.attach-process', $this->retail), [
            'business_process_id' => $this->processA->id,
        ])
            ->assertRedirect(route('risk.scoping.show', ['scoping' => $this->retail, 'tab' => 'processes']))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('entity_business_process', [
            'organization_id' => $this->organization->id,
            'entity_id' => $this->retail->id,
            'business_process_id' => $this->processA->id,
        ]);

        $this->assertTrue(
            RiskAuditTrail::query()
                ->where('entity_id', $this->processA->id)
                ->where('action_type', 'process_attached_to_entity')
                ->exists()
        );

        // Attachable endpoint excludes processA and includes processB
        $this->getJson(route('risk.scoping.attachable-processes', $this->retail))
            ->assertOk()
            ->assertJsonCount(1, 'processes')
            ->assertJsonPath('processes.0.id', $this->processB->id);

        // Create a brand new sub-process directly from the Entity Processes tab
        $this->post(route('risk.scoping.store-process', $this->retail), [
            'level' => 'sub_process',
            'parent_id' => $this->processA->id,
            'name' => 'RTGS Outward Settlement',
            'criticality' => 'high',
        ])
            ->assertRedirect(route('risk.scoping.show', ['scoping' => $this->retail, 'tab' => 'processes']))
            ->assertSessionHas('success');

        $createdSub = BusinessProcess::query()->where('name', 'RTGS Outward Settlement')->firstOrFail();
        $this->assertSame($this->processA->id, $createdSub->parent_id);
        $this->assertDatabaseHas('entity_business_process', [
            'entity_id' => $this->retail->id,
            'business_process_id' => $createdSub->id,
            'is_primary' => true,
        ]);

        // Detach processA: pivot removed, BusinessProcess record remains intact!
        $this->delete(route('risk.scoping.detach-process', ['scoping' => $this->retail, 'process' => $this->processA]))
            ->assertRedirect(route('risk.scoping.show', ['scoping' => $this->retail, 'tab' => 'processes']))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('entity_business_process', [
            'entity_id' => $this->retail->id,
            'business_process_id' => $this->processA->id,
        ]);
        $this->assertDatabaseHas('business_processes', [
            'id' => $this->processA->id,
            'deleted_at' => null,
        ]);
        $this->assertTrue(
            RiskAuditTrail::query()
                ->where('entity_id', $this->processA->id)
                ->where('action_type', 'process_detached_from_entity')
                ->exists()
        );
    }

    #[Test]
    public function process_entity_type_is_hidden_on_create_rejected_on_store_and_existing_process_entities_remain_readable_and_editable(): void
    {
        $processType = $this->type('PROCESS', 'Process', 3);

        // Hidden from Create form options even when is_active = true
        $this->get(route('risk.scoping.create'))
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $codes = collect($page->toArray()['props']['entityTypes'])->pluck('code')->all();
                $this->assertNotContains('PROCESS', $codes);
            });

        // Rejected on StoreEntityRequest with clear message
        $this->post(route('risk.scoping.store'), $this->validPayload([
            'entity_type_id' => $processType->id,
            'name' => 'Legacy Process Attempt',
        ]))->assertSessionHasErrors('entity_type_id');

        // Existing legacy PROCESS entity remains readable and editable even after PROCESS type is deactivated
        $legacyEntity = $this->entity('Wire Transfer Processing', $processType, $this->retail);
        $processType->update(['is_active' => false]);

        $this->get(route('risk.scoping.show', $legacyEntity))->assertOk();
        $this->get(route('risk.scoping.edit', $legacyEntity))->assertOk();
        $this->put(route('risk.scoping.update', $legacyEntity), $this->validPayload([
            'entity_type_id' => $processType->id,
            'name' => 'Wire Transfer Processing (Updated)',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('Wire Transfer Processing (Updated)', $legacyEntity->fresh()->name);
    }

    #[Test]
    public function migrate_process_entities_command_supports_dry_run_non_destructive_apply_and_is_idempotent(): void
    {
        $processType = $this->type('PROCESS', 'Process', 3);
        $legacyEntity = $this->entity('FX Settlement Legacy', $processType, $this->retail);
        $risk = $this->makeRisk(['entity_id' => $legacyEntity->id]);

        // Dry run does not mutate anything
        $this->artisan('erm:migrate-process-entities', ['--org' => $this->organization->id, '--dry-run' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('business_processes', ['name' => 'FX Settlement Legacy']);
        $this->assertSame('active', $legacyEntity->fresh()->status);

        // Apply migrates non-destructively
        $this->artisan('erm:migrate-process-entities', ['--org' => $this->organization->id, '--apply' => true])
            ->assertSuccessful();

        $migratedProcess = BusinessProcess::query()
            ->where('organization_id', $this->organization->id)
            ->where('name', 'FX Settlement Legacy')
            ->firstOrFail();

        $this->assertDatabaseHas('entity_business_process', [
            'organization_id' => $this->organization->id,
            'entity_id' => $this->retail->id,
            'business_process_id' => $migratedProcess->id,
            'is_primary' => true,
        ]);

        // Risk re-linked to new BusinessProcess and parent entity
        $risk->refresh();
        $this->assertSame($migratedProcess->id, $risk->process_id);
        $this->assertSame($this->retail->id, $risk->entity_id);

        // Legacy entity is NOT deleted, only marked inactive with metadata pointer
        $legacyEntity->refresh();
        $this->assertNull($legacyEntity->deleted_at);
        $this->assertSame('inactive', $legacyEntity->status);
        $this->assertSame($migratedProcess->id, $legacyEntity->metadata['migrated_to_business_process_id'] ?? null);

        // Idempotent on second run
        $countBefore = BusinessProcess::query()->where('organization_id', $this->organization->id)->count();
        $this->artisan('erm:migrate-process-entities', ['--org' => $this->organization->id, '--apply' => true])
            ->assertSuccessful();
        $this->assertSame($countBefore, BusinessProcess::query()->where('organization_id', $this->organization->id)->count());
    }

    #[Test]
    public function migrate_process_entities_audits_every_write_and_leaves_soft_deleted_risks_alone(): void
    {
        $processType = $this->type('PROCESS', 'Process', 3);
        $legacyEntity = $this->entity('Cheque Clearing Legacy', $processType, $this->retail);
        $riskOne = $this->makeRisk(['entity_id' => $legacyEntity->id]);
        $riskTwo = $this->makeRisk(['entity_id' => $legacyEntity->id]);
        $deletedRisk = $this->makeRisk(['entity_id' => $legacyEntity->id]);
        $deletedRisk->delete();

        $this->artisan('erm:migrate-process-entities', ['--org' => $this->organization->id, '--apply' => true])
            ->assertSuccessful();

        $process = BusinessProcess::query()->where('name', 'Cheque Clearing Legacy')->firstOrFail();

        // One audit row per re-linked risk, carrying where it was and where it went.
        foreach ([$riskOne, $riskTwo] as $risk) {
            $row = RiskAuditTrail::query()
                ->where('entity_type', $risk->getMorphClass())
                ->where('entity_id', $risk->id)
                ->where('action_type', 'risk_relinked_to_process')
                ->sole();

            $this->assertSame(
                ['entity_id' => $legacyEntity->id, 'process_id' => null],
                $this->decode($row->old_value)
            );
            $this->assertSame(
                ['entity_id' => $this->retail->id, 'process_id' => $process->id],
                $this->decode($row->new_value)
            );
        }

        // The process creation and the attach are audited as the interactive path audits them.
        foreach (['process_created', 'process_attached_to_entity'] as $action) {
            $this->assertTrue(
                RiskAuditTrail::query()
                    ->where('entity_type', $process->getMorphClass())
                    ->where('entity_id', $process->id)
                    ->where('action_type', $action)
                    ->exists(),
                "Expected a {$action} audit row for the migrated process."
            );
        }

        // The legacy entity's own audit row records the status change it underwent.
        $entityRow = RiskAuditTrail::query()
            ->where('entity_type', $legacyEntity->getMorphClass())
            ->where('entity_id', $legacyEntity->id)
            ->where('action_type', 'process_entity_migrated')
            ->sole();
        $this->assertSame('active', json_decode($entityRow->old_value, true)['status'] ?? null);
        $this->assertSame('inactive', json_decode($entityRow->new_value, true)['status'] ?? null);

        // A soft-deleted risk is not re-linked and gets no audit row.
        $deletedRisk = Risk::withTrashed()->findOrFail($deletedRisk->id);
        $this->assertSame($legacyEntity->id, $deletedRisk->entity_id);
        $this->assertNull($deletedRisk->process_id);
        $this->assertFalse(
            RiskAuditTrail::query()
                ->where('entity_id', $deletedRisk->id)
                ->where('action_type', 'risk_relinked_to_process')
                ->exists()
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        $this->assertIsArray($decoded);
        ksort($decoded);

        return array_map(static fn ($v) => $v === null ? null : (int) $v, $decoded);
    }
}
