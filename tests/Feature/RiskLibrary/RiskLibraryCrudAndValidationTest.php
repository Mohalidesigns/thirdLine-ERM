<?php

namespace Tests\Feature\RiskLibrary;

use App\Models\RiskAuditTrail;
use App\Models\RiskLibraryItem;
use App\Models\RiskLibraryItemVersion;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

class RiskLibraryCrudAndValidationTest extends RiskLibraryTestCase
{
    public function test_schema_has_no_score_entity_department_or_soft_delete_columns(): void
    {
        $columns = Schema::getColumnListing('risk_library_items');

        $forbidden = [
            'inherent_likelihood',
            'inherent_impact',
            'inherent_score',
            'inherent_rating',
            'residual_likelihood',
            'residual_impact',
            'residual_score',
            'residual_rating',
            'target_score',
            'entity_id',
            'business_unit_id',
            'department_id',
            'risk_owner_id',
            'deleted_at',
        ];

        foreach ($forbidden as $col) {
            $this->assertNotContains($col, $columns, "risk_library_items must not have column {$col}");
        }
    }

    public function test_create_library_item_without_scores_or_department_creates_v1_and_audit_trail(): void
    {
        $response = $this->actingAs($this->actor)
            ->post(route('risk.library.store'), $this->validLibraryPayload());

        $item = RiskLibraryItem::query()->where('organization_id', $this->organization->id)->first();
        $this->assertNotNull($item);
        $response->assertRedirect(route('risk.library.show', $item));

        $this->assertSame(1, $item->current_version);
        $this->assertSame(RiskLibraryItem::STATUS_ACTIVE, $item->status);
        $this->assertStringStartsWith('RL-', $item->code);
        $this->assertSame('business_disruption', $item->basel_l1_event_type);
        $this->assertSame('systems_failure', $item->basel_l2_event_type);

        // Version 1 immutable snapshot created
        $version = RiskLibraryItemVersion::query()
            ->where('library_item_id', $item->id)
            ->where('version_number', 1)
            ->first();

        $this->assertNotNull($version);
        $this->assertSame($item->title, $version->title);
        $this->assertSame($item->description, $version->description);
        $this->assertSame($this->actor->id, $version->created_by);

        // Audit trail written
        $audit = RiskAuditTrail::query()
            ->where('organization_id', $this->organization->id)
            ->where('entity_id', $item->id)
            ->where('action_type', 'library_item_created')
            ->first();

        $this->assertNotNull($audit);
    }

    public function test_validation_rejects_mismatched_basel_l2_for_given_basel_l1(): void
    {
        $response = $this->actingAs($this->actor)
            ->post(route('risk.library.store'), $this->validLibraryPayload([
                'basel_l1_event_type' => 'internal_fraud',
                'basel_l2_event_type' => 'systems_failure', // belongs to business_disruption
            ]));

        $response->assertSessionHasErrors('basel_l2_event_type');
        $this->assertSame(0, RiskLibraryItem::query()->where('organization_id', $this->organization->id)->count());
    }

    public function test_validation_rejects_basel_l2_without_basel_l1(): void
    {
        $response = $this->actingAs($this->actor)
            ->post(route('risk.library.store'), $this->validLibraryPayload([
                'basel_l1_event_type' => null,
                'basel_l2_event_type' => 'systems_failure',
            ]));

        $response->assertSessionHasErrors('basel_l2_event_type');
    }

    public function test_validation_rejects_cross_tenant_category_id(): void
    {
        $response = $this->actingAs($this->actor)
            ->post(route('risk.library.store'), $this->validLibraryPayload([
                'category_id' => $this->foreignCategory->id,
            ]));

        $response->assertSessionHasErrors('category_id');
        $this->assertSame(0, RiskLibraryItem::query()->where('organization_id', $this->organization->id)->count());
    }

    public function test_index_create_show_and_edit_pages_render(): void
    {
        $item = $this->libraryService->create($this->validLibraryPayload(), $this->actor);

        $this->actingAs($this->actor)
            ->get(route('risk.library.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('RiskLibrary/Index'));

        $this->actingAs($this->actor)
            ->get(route('risk.library.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('RiskLibrary/Create'));

        $this->actingAs($this->actor)
            ->get(route('risk.library.show', $item))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('RiskLibrary/Show')
                ->where('item.id', $item->id)
                ->where('item.current_version', 1)
            );

        $this->actingAs($this->actor)
            ->get(route('risk.library.edit', $item))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('RiskLibrary/Edit')
                ->where('item.id', $item->id)
            );
    }

    public function test_export_csv_and_export_pdf_return_200_with_expected_content_types(): void
    {
        $item = $this->libraryService->create($this->validLibraryPayload(), $this->actor);

        $csvResponse = $this->actingAs($this->actor)
            ->get(route('risk.library.export.csv'));

        $csvResponse->assertOk();
        $this->assertStringContainsString('text/csv', (string) $csvResponse->headers->get('Content-Type'));
        $csvBody = $csvResponse->streamedContent();
        $this->assertStringContainsString($item->code, $csvBody);
        $this->assertStringContainsString($item->title, $csvBody);

        $pdfResponse = $this->actingAs($this->actor)
            ->get(route('risk.library.export.pdf'));

        $pdfResponse->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdfResponse->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdfResponse->getContent());

        $itemPdfResponse = $this->actingAs($this->actor)
            ->get(route('risk.library.export-item', $item));

        $itemPdfResponse->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $itemPdfResponse->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $itemPdfResponse->getContent());
    }
}
