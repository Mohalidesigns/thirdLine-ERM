<?php

namespace Tests\Feature\RiskLibrary;

use App\Models\RiskAuditTrail;
use App\Models\RiskLibraryItemVersion;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;

class RiskLibraryVersioningAndUpdateReviewTest extends RiskLibraryTestCase
{
    public function test_version_snapshot_is_immutable_on_update_and_delete(): void
    {
        $item = $this->libraryService->create($this->validLibraryPayload(), $this->actor);
        $v1 = RiskLibraryItemVersion::query()
            ->where('library_item_id', $item->id)
            ->where('version_number', 1)
            ->firstOrFail();

        try {
            $v1->update(['title' => 'Tampered Title']);
            $this->fail('Expected LogicException when updating RiskLibraryItemVersion');
        } catch (LogicException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        try {
            $v1->delete();
            $this->fail('Expected LogicException when deleting RiskLibraryItemVersion');
        } catch (LogicException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }
    }

    public function test_editing_library_item_creates_v2_without_silently_mutating_instances_and_shows_diff(): void
    {
        $item = $this->libraryService->create($this->validLibraryPayload([
            'title' => 'Payment Switch Outage v1',
            'description' => 'Original v1 description of payment switch outage.',
        ]), $this->actor);

        $instance = $this->libraryService->attachToTarget($item, [
            'entity_id' => $this->entityA->id,
            'business_unit_id' => $this->unit->id,
            'risk_owner_id' => $this->actor->id,
        ], $this->actor);

        // Edit library item via HTTP -> creates v2
        $response = $this->actingAs($this->actor)
            ->put(route('risk.library.update', $item), $this->validLibraryPayload([
                'title' => 'Payment Switch Outage v2 (Clarified)',
                'description' => 'Expanded v2 description covering NIP and card switch downtime.',
                'change_summary' => 'Expanded scope to explicitly include NIP and card switches.',
            ]));

        $response->assertRedirect(route('risk.library.show', $item));

        $item->refresh();
        $instance->refresh();

        $this->assertSame(2, $item->current_version);
        $this->assertSame(2, RiskLibraryItemVersion::query()->where('library_item_id', $item->id)->count());

        // Non-silent propagation: instance remains at v1 with original title/description
        $this->assertSame(1, $instance->library_version);
        $this->assertSame('Payment Switch Outage v1', $instance->title);
        $this->assertSame('Original v1 description of payment switch outage.', $instance->description);

        // Audit trail entries recorded for update and versioning
        $this->assertTrue(
            RiskAuditTrail::query()
                ->where('entity_id', $item->id)
                ->where('action_type', 'library_item_updated')
                ->exists()
        );
        $this->assertTrue(
            RiskAuditTrail::query()
                ->where('entity_id', $item->id)
                ->where('action_type', 'library_item_versioned')
                ->exists()
        );

        // RiskRegisterController::show returns libraryContext with has_pending_update === true and diff
        $this->actingAs($this->actor)
            ->get(route('risk.register.show', $instance))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Register/Show')
                ->where('libraryContext.library_item_id', $item->id)
                ->where('libraryContext.instance_version', 1)
                ->where('libraryContext.current_version', 2)
                ->where('libraryContext.has_pending_update', true)
                ->where('libraryContext.diff.from_version', 1)
                ->where('libraryContext.diff.to_version', 2)
                ->where('libraryContext.diff.change_summary', 'Expanded scope to explicitly include NIP and card switches.')
                ->has('libraryContext.diff.fields', fn (Assert $fields) => $fields->etc())
            );
    }

    public function test_accepting_library_update_updates_statement_and_preserves_scores_owner_and_review_dates(): void
    {
        $item = $this->libraryService->create($this->validLibraryPayload([
            'title' => 'Vendor SLA Failure v1',
            'description' => 'Initial vendor SLA failure statement.',
        ]), $this->actor);

        $owner = $this->userWithPermissions(['risk.view'], 'owner-preserved@example.test');
        $reviewDate = now()->addMonths(3)->toDateString();

        $instance = $this->libraryService->attachToTarget($item, [
            'entity_id' => $this->entityA->id,
            'business_unit_id' => $this->unit->id,
            'risk_owner_id' => $owner->id,
        ], $this->actor);

        // Populate assessment scores and review date on the instance
        $instance->update([
            'inherent_likelihood' => 4,
            'inherent_impact' => 5,
            'inherent_score' => 20,
            'inherent_rating' => 'Critical',
            'residual_likelihood' => 3,
            'residual_impact' => 4,
            'residual_score' => 12,
            'residual_rating' => 'High',
            'next_review_date' => $reviewDate,
        ]);

        // Publish v2
        $this->libraryService->update($item, $this->validLibraryPayload([
            'title' => 'Critical Third-Party Vendor SLA Failure v2',
            'description' => 'Updated v2 definition covering fourth-party concentration.',
            'change_summary' => 'Clarified fourth-party concentration scope.',
        ]), $this->actor);

        // Accept update
        $response = $this->actingAs($this->actor)
            ->post(route('risk.register.library-update', $instance), [
                'decision' => 'accept',
            ]);

        $response->assertRedirect(route('risk.register.show', $instance));

        $instance->refresh();

        // Statement fields and version updated
        $this->assertSame('Critical Third-Party Vendor SLA Failure v2', $instance->title);
        $this->assertSame('Updated v2 definition covering fourth-party concentration.', $instance->description);
        $this->assertSame(2, $instance->library_version);
        $this->assertFalse($instance->hasPendingLibraryUpdate());

        // Assessment scores, owner, and review date NEVER overwritten
        $this->assertSame(4, (int) $instance->inherent_likelihood);
        $this->assertSame(5, (int) $instance->inherent_impact);
        $this->assertSame(20, (int) $instance->inherent_score);
        $this->assertSame('Critical', $instance->inherent_rating);
        $this->assertSame(3, (int) $instance->residual_likelihood);
        $this->assertSame(4, (int) $instance->residual_impact);
        $this->assertSame(12, (int) $instance->residual_score);
        $this->assertSame('High', $instance->residual_rating);
        $this->assertSame($owner->id, $instance->risk_owner_id);
        $this->assertSame($reviewDate, $instance->next_review_date?->toDateString());

        // Audit trail written
        $this->assertTrue(
            RiskAuditTrail::query()
                ->where('organization_id', $this->organization->id)
                ->where('entity_id', $instance->id)
                ->where('action_type', 'library_update_accepted')
                ->exists()
        );
    }

    public function test_dismissing_library_update_hides_banner_without_altering_statement_and_reappears_on_v3(): void
    {
        $item = $this->libraryService->create($this->validLibraryPayload([
            'title' => 'AML Screening Gap v1',
            'description' => 'Original AML screening statement.',
        ]), $this->actor);

        $instance = $this->libraryService->attachToTarget($item, [
            'entity_id' => $this->entityA->id,
            'business_unit_id' => $this->unit->id,
            'risk_owner_id' => $this->actor->id,
        ], $this->actor);

        // Publish v2
        $this->libraryService->update($item, $this->validLibraryPayload([
            'title' => 'AML Screening Gap v2',
            'description' => 'Updated v2 AML screening statement.',
            'change_summary' => 'Added sanctions list screening reference.',
        ]), $this->actor);

        // Dismiss v2
        $response = $this->actingAs($this->actor)
            ->post(route('risk.register.library-update', $instance), [
                'decision' => 'dismiss',
            ]);

        $response->assertRedirect(route('risk.register.show', $instance));

        $instance->refresh();
        $this->assertSame(1, $instance->library_version);
        $this->assertSame(2, $instance->library_update_dismissed_version);
        $this->assertSame('AML Screening Gap v1', $instance->title);
        $this->assertSame('Original AML screening statement.', $instance->description);
        $this->assertFalse($instance->hasPendingLibraryUpdate());

        // Register/Show confirms banner is cleared
        $this->actingAs($this->actor)
            ->get(route('risk.register.show', $instance))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('libraryContext.has_pending_update', false)
                ->where('libraryContext.diff', null)
            );

        // Audit trail written
        $this->assertTrue(
            RiskAuditTrail::query()
                ->where('organization_id', $this->organization->id)
                ->where('entity_id', $instance->id)
                ->where('action_type', 'library_update_dismissed')
                ->exists()
        );

        // When library advances to v3, banner automatically reappears
        $this->libraryService->update($item->refresh(), $this->validLibraryPayload([
            'title' => 'AML Screening Gap v3',
            'description' => 'Updated v3 AML screening statement.',
            'change_summary' => 'Aligned with updated CBN AML/CFT circular.',
        ]), $this->actor);

        $instance->refresh();
        $this->assertTrue($instance->hasPendingLibraryUpdate());
    }
}
