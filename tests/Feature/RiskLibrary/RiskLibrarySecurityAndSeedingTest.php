<?php

namespace Tests\Feature\RiskLibrary;

use App\Models\Risk;
use App\Models\RiskLibraryItem;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;

class RiskLibrarySecurityAndSeedingTest extends RiskLibraryTestCase
{
    public function test_tenant_a_cannot_view_edit_update_retire_reactivate_or_export_tenant_b_library_items(): void
    {
        // Route model binding + BelongsToOrganization global scope returns 404 (or 403)
        $this->actingAs($this->actor)
            ->get(route('risk.library.show', $this->foreignLibraryItem))
            ->assertStatus(404);

        $this->actingAs($this->actor)
            ->get(route('risk.library.edit', $this->foreignLibraryItem))
            ->assertStatus(404);

        $this->actingAs($this->actor)
            ->put(route('risk.library.update', $this->foreignLibraryItem), $this->validLibraryPayload([
                'change_summary' => 'Cross-tenant tamper attempt.',
            ]))
            ->assertStatus(404);

        $this->actingAs($this->actor)
            ->post(route('risk.library.retire', $this->foreignLibraryItem), [
                'retirement_reason' => 'Cross-tenant retire attempt.',
            ])
            ->assertStatus(404);

        $this->actingAs($this->actor)
            ->post(route('risk.library.reactivate', $this->foreignLibraryItem))
            ->assertStatus(404);

        $this->actingAs($this->actor)
            ->get(route('risk.library.export-item', $this->foreignLibraryItem))
            ->assertStatus(404);

        // Bulk apply rejects cross-tenant library item with 422
        $this->actingAs($this->actor)
            ->postJson(route('risk.library.bulk-apply'), [
                'library_item_ids' => [$this->foreignLibraryItem->id],
                'target_mode' => 'entities',
                'entity_ids' => [$this->entityA->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('library_item_ids.0');

        // Duplicate check rejects cross-tenant category_id and exclude_id with 422
        $this->actingAs($this->actor)
            ->getJson(route('risk.library.duplicates', [
                'title' => 'Unauthorized Wire Transfer Fraud',
                'category_id' => $this->foreignCategory->id,
                'exclude_id' => $this->foreignLibraryItem->id,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category_id', 'exclude_id']);
    }

    public function test_missing_permissions_return_403_on_each_library_endpoint(): void
    {
        $item = $this->libraryService->create($this->validLibraryPayload(), $this->actor);

        $noPerms = $this->userWithPermissions([], 'no-perms@example.test');
        $viewOnly = $this->userWithPermissions(['risk_library.view'], 'view-only@example.test');

        // Missing risk_library.view -> 403
        $this->actingAs($noPerms)->get(route('risk.library.index'))->assertForbidden();
        $this->actingAs($noPerms)->get(route('risk.library.show', $item))->assertForbidden();
        $this->actingAs($noPerms)->getJson(route('risk.library.duplicates', ['title' => 'Core']))->assertForbidden();
        $this->actingAs($noPerms)->getJson(route('risk.library.picker-options'))->assertForbidden();
        $this->actingAs($noPerms)->get(route('risk.library.export.csv'))->assertForbidden();
        $this->actingAs($noPerms)->get(route('risk.library.export.pdf'))->assertForbidden();

        // Missing risk_library.create -> 403
        $this->actingAs($viewOnly)->get(route('risk.library.create'))->assertForbidden();
        $this->actingAs($viewOnly)->post(route('risk.library.store'), $this->validLibraryPayload())->assertForbidden();

        // Missing risk_library.update -> 403
        $this->actingAs($viewOnly)->get(route('risk.library.edit', $item))->assertForbidden();
        $this->actingAs($viewOnly)->put(route('risk.library.update', $item), $this->validLibraryPayload([
            'change_summary' => 'Unauthorized update attempt.',
        ]))->assertForbidden();

        // Missing risk_library.retire -> 403
        $this->actingAs($viewOnly)->post(route('risk.library.retire', $item), [
            'retirement_reason' => 'Unauthorized retirement attempt.',
        ])->assertForbidden();
        $this->actingAs($viewOnly)->post(route('risk.library.reactivate', $item))->assertForbidden();

        // Missing risk.create -> 403 on bulk apply
        $this->actingAs($viewOnly)->postJson(route('risk.library.bulk-apply.preview'), [
            'library_item_ids' => [$item->id],
            'target_mode' => 'entities',
            'entity_ids' => [$this->entityA->id],
        ])->assertForbidden();
        $this->actingAs($viewOnly)->postJson(route('risk.library.bulk-apply'), [
            'library_item_ids' => [$item->id],
            'target_mode' => 'entities',
            'entity_ids' => [$this->entityA->id],
        ])->assertForbidden();
    }

    public function test_risk_champion_roles_are_not_granted_any_risk_library_permission(): void
    {
        // Owner decision 2026-11-27: champions get NO library permission — the library
        // Show page carries register data a champion cannot otherwise read, and their
        // menu does not include the library. Re-run the grant migration to prove it
        // does not add one.
        $migration = require database_path('migrations/2026_11_27_100003_grant_risk_library_permissions_to_roles.php');

        foreach (['risk-champion', 'risk-champions'] as $name) {
            $role = Role::findOrCreate($name);
            $migration->up();
            $role->refresh();

            foreach (['risk_library.view', 'risk_library.create', 'risk_library.update', 'risk_library.retire'] as $permission) {
                $this->assertFalse($role->hasPermissionTo($permission), "{$name} must NOT have {$permission}.");
            }

            $this->assertNotContains('risk_library.view', (new \App\Authorization\RiskPermissionCatalog)->permissionsFor($name));
        }
    }

    public function test_existing_risk_register_routes_still_resolve(): void
    {
        $this->assertTrue(Route::has('risk.register.index'));
        $this->assertTrue(Route::has('risk.register.create'));
        $this->assertTrue(Route::has('risk.register.show'));
        $this->assertTrue(Route::has('risk.register.store'));
        $this->assertTrue(Route::has('risk.register.library-update'));

        $risk = $this->makeRisk([
            'business_unit_id' => $this->unit->id,
            'risk_owner_id' => $this->actor->id,
        ]);

        $this->actingAs($this->actor)->get(route('risk.register.index'))->assertOk();
        $this->actingAs($this->actor)->get(route('risk.register.create'))->assertOk();
        $this->actingAs($this->actor)->get(route('risk.register.show', $risk))->assertOk();
    }

    public function test_seed_risk_library_dry_run_exits_zero_and_does_not_mutate_risks(): void
    {
        $riskA = $this->makeRisk([
            'title' => 'Legacy Branch Cash Shortage',
            'description' => 'Unlinked branch cash shortage risk.',
            'business_unit_id' => $this->unit->id,
            'entity_id' => $this->entityA->id,
            'risk_owner_id' => $this->actor->id,
        ]);

        $riskB = $this->makeRisk([
            'title' => 'Legacy Branch Cash Shortage',
            'description' => 'Unlinked branch cash shortage risk in another entity.',
            'business_unit_id' => $this->unit->id,
            'entity_id' => $this->entityB->id,
            'risk_owner_id' => $this->actor->id,
        ]);

        $libraryCountBefore = RiskLibraryItem::query()->where('organization_id', $this->organization->id)->count();

        $this->artisan('erm:seed-risk-library', [
            '--org' => $this->organization->id,
            '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertSame(
            $libraryCountBefore,
            RiskLibraryItem::query()->where('organization_id', $this->organization->id)->count()
        );
        $this->assertNull($riskA->fresh()->library_item_id);
        $this->assertNull($riskB->fresh()->library_item_id);
    }

    public function test_seed_risk_library_confirm_creates_library_item_and_links_without_merging_risks(): void
    {
        $riskA = $this->makeRisk([
            'title' => 'Duplicate Teller Cash Variance',
            'description' => 'Cash variance at teller counters.',
            'business_unit_id' => $this->unit->id,
            'entity_id' => $this->entityA->id,
            'risk_owner_id' => $this->actor->id,
        ]);

        $riskB = $this->makeRisk([
            'title' => 'Duplicate Teller Cash Variance',
            'description' => 'Cash variance at teller counters in Ghana.',
            'business_unit_id' => $this->unit->id,
            'entity_id' => $this->entityB->id,
            'risk_owner_id' => $this->actor->id,
        ]);

        $this->artisan('erm:seed-risk-library', [
            '--org' => $this->organization->id,
            '--confirm' => true,
        ])->assertExitCode(0);

        $riskA->refresh();
        $riskB->refresh();

        // Both risks remain intact (never merged or deleted) and point to the same new library item
        $this->assertNotNull($riskA->library_item_id);
        $this->assertSame($riskA->library_item_id, $riskB->library_item_id);
        $this->assertSame(1, $riskA->library_version);
        $this->assertSame(1, $riskB->library_version);
        $this->assertSame(2, Risk::query()->whereIn('id', [$riskA->id, $riskB->id])->count());
    }
}
