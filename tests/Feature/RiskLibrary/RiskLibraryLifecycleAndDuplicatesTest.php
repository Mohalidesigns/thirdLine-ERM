<?php

namespace Tests\Feature\RiskLibrary;

use App\Models\RiskAuditTrail;
use App\Models\RiskLibraryItem;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

class RiskLibraryLifecycleAndDuplicatesTest extends RiskLibraryTestCase
{
    public function test_retire_and_reactivate_lifecycle_preserves_live_instances_and_hides_from_picker(): void
    {
        $item = $this->libraryService->create($this->validLibraryPayload(), $this->actor);
        $instance = $this->libraryService->attachToTarget($item, [
            'entity_id' => $this->entityA->id,
            'business_unit_id' => $this->unit->id,
            'risk_owner_id' => $this->actor->id,
        ], $this->actor);

        // Before retirement, item appears in picker options
        $pickerBefore = $this->actingAs($this->actor)
            ->getJson(route('risk.library.picker-options'))
            ->assertOk();
        $this->assertContains($item->id, collect($pickerBefore->json('items'))->pluck('id')->all());

        // Retire via HTTP
        $retireResponse = $this->actingAs($this->actor)
            ->post(route('risk.library.retire', $item), [
                'retirement_reason' => 'Consolidated into Enterprise IT Resilience risk taxonomy item.',
            ]);

        $retireResponse->assertRedirect(route('risk.library.show', $item));

        $item->refresh();
        $this->assertSame(RiskLibraryItem::STATUS_RETIRED, $item->status);
        $this->assertNotNull($item->retired_at);
        $this->assertSame($this->actor->id, $item->retired_by);
        $this->assertSame('Consolidated into Enterprise IT Resilience risk taxonomy item.', $item->retirement_reason);

        // Live instance remains intact and assessable
        $instance->refresh();
        $this->assertSame('active', $instance->status);
        $this->assertSame($item->id, $instance->library_item_id);
        $instance->update([
            'inherent_likelihood' => 3,
            'inherent_impact' => 4,
            'inherent_score' => 12,
        ]);
        $this->assertSame(12, (int) $instance->fresh()->inherent_score);

        // Retired item hidden from default picker options
        $pickerAfterRetire = $this->actingAs($this->actor)
            ->getJson(route('risk.library.picker-options'))
            ->assertOk();
        $this->assertNotContains($item->id, collect($pickerAfterRetire->json('items'))->pluck('id')->all());

        // Audit trail written for retirement
        $this->assertTrue(
            RiskAuditTrail::query()
                ->where('entity_id', $item->id)
                ->where('action_type', 'library_item_retired')
                ->exists()
        );

        // Reactivate via HTTP
        $reactivateResponse = $this->actingAs($this->actor)
            ->post(route('risk.library.reactivate', $item));

        $reactivateResponse->assertRedirect(route('risk.library.show', $item));

        $item->refresh();
        $this->assertSame(RiskLibraryItem::STATUS_ACTIVE, $item->status);
        $this->assertNull($item->retired_at);
        $this->assertNull($item->retirement_reason);

        // Reappears in picker options
        $pickerAfterReactivate = $this->actingAs($this->actor)
            ->getJson(route('risk.library.picker-options'))
            ->assertOk();
        $this->assertContains($item->id, collect($pickerAfterReactivate->json('items'))->pluck('id')->all());

        // Audit trail written for reactivation
        $this->assertTrue(
            RiskAuditTrail::query()
                ->where('entity_id', $item->id)
                ->where('action_type', 'library_item_reactivated')
                ->exists()
        );
    }

    public function test_hard_delete_is_blocked_in_policy_and_no_delete_route_exists(): void
    {
        $item = $this->libraryService->create($this->validLibraryPayload(), $this->actor);

        $this->assertFalse(Gate::forUser($this->actor)->allows('delete', $item));

        $deleteRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(function ($route) {
                return in_array('DELETE', $route->methods(), true)
                    && str_contains($route->uri(), 'risk/library');
            });

        $this->assertCount(0, $deleteRoutes, 'No DELETE route may exist under risk/library (ADR 0028 Decision 6).');
    }

    public function test_duplicate_detection_returns_same_tenant_matches_and_excludes_other_tenants(): void
    {
        $localMatch = $this->libraryService->create($this->validLibraryPayload([
            'title' => 'Unauthorized Wire Transfer Fraud in Corporate Banking',
        ]), $this->actor);

        $unrelated = $this->libraryService->create($this->validLibraryPayload([
            'title' => 'Physical Vault Flood Damage',
        ]), $this->actor);

        // $this->foreignLibraryItem has title 'Unauthorized Wire Transfer Fraud' in $this->otherOrg
        $response = $this->actingAs($this->actor)
            ->getJson(route('risk.library.duplicates', [
                'title' => 'Unauthorized Wire Transfer Fraud',
                'category_id' => $this->category->id,
            ]));

        $response->assertOk();
        $matchIds = collect($response->json('matches'))->pluck('id')->all();

        $this->assertContains($localMatch->id, $matchIds);
        $this->assertNotContains($this->foreignLibraryItem->id, $matchIds);
        $this->assertNotContains($unrelated->id, $matchIds);
    }
}
