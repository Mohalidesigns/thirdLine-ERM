<?php

namespace Tests\Feature\RiskLibrary;

use App\Http\Requests\RiskLibrary\BulkApplyLibraryItemRequest;
use App\Models\Risk;

/**
 * Gate-2 should-fix (2026-11-27): bulk-apply checked tenancy but not org-hierarchy
 * reach, and had no size cap on a synchronous, single-transaction write.
 */
class RiskLibraryBulkApplyGuardsTest extends RiskLibraryTestCase
{
    public function test_a_branch_pinned_user_cannot_bulk_apply_to_an_entity_outside_their_subtree(): void
    {
        $item = $this->libraryService->create($this->validLibraryPayload(), $this->actor);
        $pinned = $this->userWithPermissions(['risk_library.view', 'risk.view', 'risk.create'], 'pinned-bulk@example.test');
        $pinned->forceFill(['scope_entity_id' => $this->entityA->id])->save();

        $before = Risk::query()->count();

        $this->actingAs($pinned)
            ->postJson(route('risk.library.bulk-apply'), [
                'library_item_ids' => [$item->id],
                'target_mode' => 'entities',
                'entity_ids' => [$this->entityA->id, $this->entityB->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('entity_ids');

        $this->assertSame($before, Risk::query()->count());

        // Inside their own subtree the same request goes through.
        $this->actingAs($pinned)
            ->postJson(route('risk.library.bulk-apply'), [
                'library_item_ids' => [$item->id],
                'target_mode' => 'entities',
                'entity_ids' => [$this->entityA->id],
                'default_owner_id' => $pinned->id,
            ])
            ->assertStatus(201);
    }

    public function test_the_picker_offers_only_entities_inside_the_callers_subtree(): void
    {
        $pinned = $this->userWithPermissions(['risk_library.view', 'risk.view', 'risk.create'], 'pinned-picker@example.test');
        $pinned->forceFill(['scope_entity_id' => $this->entityA->id])->save();

        $ids = collect(
            $this->actingAs($pinned)->getJson(route('risk.library.picker-options'))->assertOk()->json('entities')
        )->pluck('id')->all();

        $this->assertContains($this->entityA->id, $ids);
        $this->assertNotContains($this->entityB->id, $ids);
    }

    public function test_bulk_apply_refuses_more_than_the_target_cap(): void
    {
        $item = $this->libraryService->create($this->validLibraryPayload(), $this->actor);

        $this->actingAs($this->actor)
            ->postJson(route('risk.library.bulk-apply.preview'), [
                'library_item_ids' => [$item->id],
                'target_mode' => 'entities',
                'entity_ids' => array_fill(0, BulkApplyLibraryItemRequest::MAX_TARGETS + 1, $this->entityA->id),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('entity_ids');
    }
}
