<?php

namespace Tests\Feature\Appetite;

use App\Models\Organization;
use App\Models\RiskAppetite;
use App\Models\RiskCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * Migration Phase 3.6 — the appetite framework page, its two forms, the
 * policy and the cross-tenant guards.
 */
class AppetitePageTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        foreach (['appetite.view', 'appetite.manage', 'appetite.approve', 'dashboard.view'] as $permission) {
            Permission::findOrCreate($permission);
        }
        $this->actor->givePermissionTo(['appetite.view', 'appetite.manage', 'dashboard.view']);
    }

    #[Test]
    public function the_policy_is_discovered_for_the_model(): void
    {
        $this->assertInstanceOf(\App\Policies\RiskAppetitePolicy::class, Gate::getPolicyFor(RiskAppetite::class));
    }

    #[Test]
    public function the_page_carries_metrics_summary_chart_and_the_categories_still_open(): void
    {
        $this->statement(['current_position' => 12, 'max_tolerance' => 10, 'target_max' => 5]);
        RiskCategory::create(['organization_id' => $this->organization->id, 'code' => 'CR', 'name' => 'Credit Risk']);

        $this->actingAs($this->actor)->get(route('risk.appetite.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Appetite/Index')
                ->has('metrics', 1)
                ->where('metrics.0.status', 'breach')
                ->where('metrics.0.risk_category', 'Operational Risk')
                ->where('summary.breaches', 1)
                ->where('summary.overall', 'Breach')
                ->where('chart.type', 'appetite_position')
                ->has('chart.data.bars', 1)
                ->has('categoriesWithoutStatement', 1)
                ->where('categoriesWithoutStatement.0.name', 'Credit Risk')
                ->where('canManage', true)
                ->has('exportUrl'));
    }

    #[Test]
    public function a_viewer_sees_the_page_but_cannot_manage(): void
    {
        $this->actor->revokePermissionTo('appetite.manage');

        $this->actingAs($this->actor)->get(route('risk.appetite.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canManage', false));

        $this->actingAs($this->actor)->post(route('risk.appetite.store'), $this->payload())->assertForbidden();
    }

    #[Test]
    public function the_page_requires_appetite_view(): void
    {
        $this->actor->revokePermissionTo('appetite.view');

        $this->actingAs($this->actor)->get(route('risk.appetite.index'))->assertForbidden();
    }

    #[Test]
    public function a_statement_can_be_created_once_per_category(): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.appetite.store'), $this->payload())
            ->assertRedirect(route('risk.appetite.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('risk_appetite', ['organization_id' => $this->organization->id, 'risk_category_id' => $this->category->id, 'appetite_level' => 'cautious']);

        $this->actingAs($this->actor)
            ->from(route('risk.appetite.index'))
            ->post(route('risk.appetite.store'), $this->payload())
            ->assertRedirect(route('risk.appetite.index'))
            ->assertSessionHasErrors('risk_category_id');
    }

    #[Test]
    public function a_statement_can_be_updated(): void
    {
        $statement = $this->statement();

        $this->actingAs($this->actor)
            ->put(route('risk.appetite.update', $statement), array_merge($this->payload(), ['appetite_level' => 'open', 'max_tolerance' => 20]))
            ->assertRedirect(route('risk.appetite.index'));

        $this->assertSame('open', $statement->fresh()->appetite_level);
        $this->assertSame(20.0, (float) $statement->fresh()->max_tolerance);
    }

    #[Test]
    public function the_store_request_rejects_a_category_from_another_tenant(): void
    {
        $other = $this->otherOrganization();
        $foreign = RiskCategory::withoutGlobalScopes()->create(['organization_id' => $other->id, 'code' => 'X', 'name' => 'Foreign']);

        $this->actingAs($this->actor)
            ->from(route('risk.appetite.index'))
            ->post(route('risk.appetite.store'), array_merge($this->payload(), ['risk_category_id' => $foreign->id]))
            ->assertSessionHasErrors('risk_category_id');
    }

    #[Test]
    public function the_validation_rules_hold_the_band_order(): void
    {
        $this->actingAs($this->actor)
            ->from(route('risk.appetite.index'))
            ->post(route('risk.appetite.store'), array_merge($this->payload(), ['target_max' => 3, 'max_tolerance' => 2]))
            ->assertSessionHasErrors(['max_tolerance']);

        $this->actingAs($this->actor)
            ->from(route('risk.appetite.index'))
            ->post(route('risk.appetite.store'), array_merge($this->payload(), ['appetite_level' => 'reckless']))
            ->assertSessionHasErrors(['appetite_level']);
    }

    #[Test]
    public function another_tenants_statement_cannot_be_updated(): void
    {
        $other = $this->otherOrganization();
        $foreignCategory = RiskCategory::withoutGlobalScopes()->create(['organization_id' => $other->id, 'code' => 'X', 'name' => 'Foreign']);
        $foreign = RiskAppetite::withoutGlobalScopes()->create([
            'organization_id' => $other->id,
            'risk_category_id' => $foreignCategory->id,
            'appetite_level' => 'low',
            'appetite_statement' => 'Theirs',
            'tolerance_metric' => 'x',
            'unit_of_measure' => 'percentage',
            'target_min' => 0, 'target_max' => 1, 'max_tolerance' => 2,
            'effective_date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($this->actor)->put(route('risk.appetite.update', $foreign->id), $this->payload());

        $this->assertContains($response->status(), [403, 404]);
        $this->assertSame('Theirs', RiskAppetite::withoutGlobalScopes()->find($foreign->id)->appetite_statement);
    }

    #[Test]
    public function the_old_blade_view_is_gone(): void
    {
        $this->assertFileDoesNotExist(resource_path('views/risk/appetite/index.blade.php'));
    }

    private function payload(): array
    {
        return [
            'risk_category_id' => $this->category->id,
            'appetite_level' => 'cautious',
            'appetite_statement' => 'No single operational loss above NGN 50m.',
            'tolerance_metric' => 'Operational loss',
            'unit_of_measure' => 'NGN m',
            'target_min' => 0,
            'target_max' => 5,
            'max_tolerance' => 10,
            'current_position' => 4,
            'effective_date' => now()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
        ];
    }

    private function statement(array $attributes = []): RiskAppetite
    {
        return RiskAppetite::create(array_merge([
            'organization_id' => $this->organization->id,
            'risk_category_id' => $this->category->id,
            'appetite_level' => 'cautious',
            'appetite_statement' => 'Statement',
            'tolerance_metric' => 'NPL ratio',
            'unit_of_measure' => 'percentage',
            'target_min' => 0,
            'target_max' => 5,
            'max_tolerance' => 10,
            'effective_date' => now()->toDateString(),
        ], $attributes));
    }

    private function otherOrganization(): Organization
    {
        return Organization::create([
            'name' => 'Other Bank',
            'short_name' => 'OTHR'.random_int(100, 999),
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);
    }
}
