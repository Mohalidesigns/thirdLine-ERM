<?php

namespace Tests\Feature\RiskLibrary;

use App\Models\Risk;
use App\Models\RiskAuditTrail;

class RiskLibraryMultiEntityAndBulkApplyTest extends RiskLibraryTestCase
{
    public function test_attaching_same_library_item_to_two_entities_creates_independent_instances(): void
    {
        $item = $this->libraryService->create($this->validLibraryPayload(), $this->actor);

        $instanceA = $this->libraryService->attachToTarget($item, [
            'entity_id' => $this->entityA->id,
            'risk_owner_id' => $this->actor->id,
        ], $this->actor);

        $instanceB = $this->libraryService->attachToTarget($item, [
            'entity_id' => $this->entityB->id,
            'risk_owner_id' => $this->actor->id,
        ], $this->actor);

        $this->assertNotSame($instanceA->id, $instanceB->id);
        $this->assertSame($item->id, $instanceA->library_item_id);
        $this->assertSame($item->id, $instanceB->library_item_id);
        $this->assertSame(1, $instanceA->library_version);
        $this->assertSame(1, $instanceB->library_version);
        $this->assertSame($this->entityA->id, $instanceA->entity_id);
        $this->assertSame($this->entityB->id, $instanceB->entity_id);

        // Freshly instantiated risks have NULL raw score columns until assessed
        $this->assertNull($instanceA->getRawOriginal('inherent_score'));
        $this->assertNull($instanceB->getRawOriginal('inherent_score'));
        $this->assertNull($instanceA->getRawOriginal('inherent_likelihood'));
        $this->assertNull($instanceB->getRawOriginal('inherent_likelihood'));

        // Score instance A differently from instance B
        $instanceA->update([
            'inherent_likelihood' => 5,
            'inherent_impact' => 5,
            'inherent_score' => 25,
            'inherent_rating' => 'Critical',
            'residual_score' => 15,
        ]);

        $instanceB->update([
            'inherent_likelihood' => 2,
            'inherent_impact' => 2,
            'inherent_score' => 4,
            'inherent_rating' => 'Low',
            'residual_score' => 2,
        ]);

        $instanceA->refresh();
        $instanceB->refresh();
        $item->refresh();

        $this->assertSame(25, (int) $instanceA->inherent_score);
        $this->assertSame(4, (int) $instanceB->inherent_score);
        $this->assertSame(15, (int) $instanceA->residual_score);
        $this->assertSame(2, (int) $instanceB->residual_score);
        $this->assertSame(1, $item->current_version);
    }

    public function test_bulk_apply_preview_and_commit_create_only_new_instances_and_audit(): void
    {
        $item = $this->libraryService->create($this->validLibraryPayload(), $this->actor);

        // Pre-attach to Entity A
        $existing = $this->libraryService->attachToTarget($item, [
            'entity_id' => $this->entityA->id,
            'risk_owner_id' => $this->actor->id,
        ], $this->actor);

        // Preview across Entity A (already attached) and Entity B (new)
        $previewResponse = $this->actingAs($this->actor)
            ->postJson(route('risk.library.bulk-apply.preview'), [
                'library_item_ids' => [$item->id],
                'target_mode' => 'entities',
                'entity_ids' => [$this->entityA->id, $this->entityB->id],
            ]);

        $previewResponse->assertOk()
            ->assertJsonPath('summary.new_count', 1)
            ->assertJsonPath('summary.skip_count', 1)
            ->assertJsonPath('summary.total_pairs', 2)
            ->assertJsonCount(1, 'will_create')
            ->assertJsonCount(1, 'will_skip')
            ->assertJsonPath('will_create.0.target_id', $this->entityB->id)
            ->assertJsonPath('will_skip.0.target_id', $this->entityA->id)
            ->assertJsonPath('will_skip.0.existing_risk_code', $existing->risk_code);

        // Commit bulk apply
        $commitResponse = $this->actingAs($this->actor)
            ->postJson(route('risk.library.bulk-apply'), [
                'library_item_ids' => [$item->id],
                'target_mode' => 'entities',
                'entity_ids' => [$this->entityA->id, $this->entityB->id],
                'default_owner_id' => $this->actor->id,
            ]);

        $commitResponse->assertStatus(201)
            ->assertJsonPath('created_count', 1)
            ->assertJsonPath('skipped_count', 1);

        $createdIds = $commitResponse->json('created_ids');
        $this->assertCount(1, $createdIds);

        $createdRisk = Risk::query()->find($createdIds[0]);
        $this->assertNotNull($createdRisk);
        $this->assertSame($item->id, $createdRisk->library_item_id);
        $this->assertSame($this->entityB->id, $createdRisk->entity_id);
        $this->assertSame(1, $createdRisk->library_version);

        // Total risks linked to this item should be 2 (no duplicate created for Entity A)
        $this->assertSame(2, Risk::query()->where('library_item_id', $item->id)->count());

        // Audit trail written for newly created risk
        $audit = RiskAuditTrail::query()
            ->where('organization_id', $this->organization->id)
            ->where('entity_id', $createdRisk->id)
            ->where('action_type', 'risk_instantiated_from_library')
            ->first();

        $this->assertNotNull($audit);
    }

    public function test_bulk_apply_rejects_retired_item_and_cross_tenant_targets(): void
    {
        $item = $this->libraryService->create($this->validLibraryPayload(), $this->actor);
        $this->libraryService->retire($item, 'Superseded by consolidated cyber operational risk definition.', $this->actor);

        // Retired item rejected
        $this->actingAs($this->actor)
            ->postJson(route('risk.library.bulk-apply'), [
                'library_item_ids' => [$item->id],
                'target_mode' => 'entities',
                'entity_ids' => [$this->entityA->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('library_item_ids.0');

        // Foreign entity rejected
        $activeItem = $this->libraryService->create($this->validLibraryPayload(['title' => 'Active Liquidity Risk']), $this->actor);

        $this->actingAs($this->actor)
            ->postJson(route('risk.library.bulk-apply'), [
                'library_item_ids' => [$activeItem->id],
                'target_mode' => 'entities',
                'entity_ids' => [$this->foreignEntity->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('entity_ids.0');
    }
}
