<?php

namespace Tests\Feature\Characterisation;

use App\Models\Entity;
use App\Models\EntityType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * CHARACTERISATION — Phase 3.1, written against the Blade controller BEFORE
 * its figures moved into App\Services\Scoping\EntityService, then re-pointed
 * at the Inertia props with the same expected numbers.
 *
 * Two DIFFERENT "risk score" formulas live in the scoping screens and both
 * are pinned here exactly as they were:
 *
 *   Entity dashboard heatmap   (5·critical + 4·high + 3·medium + 2·low) / total
 *   Entity detail sub-entities (5·critical + 4·high) / total
 *
 * The second drops medium and low risks from the numerator while keeping
 * them in the denominator. That is almost certainly an oversight, but it is
 * the number the product has shown, so the port carries it unchanged and
 * this test is what would flag a "fix" as a behaviour change.
 */
class EntityRiskScoreTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private EntityType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDomainFixtures();

        Permission::findOrCreate('entity.view');
        $this->actor->givePermissionTo('entity.view');

        $this->type = EntityType::create([
            'organization_id' => $this->organization->id,
            'code' => 'DIVISION',
            'name' => 'Division',
            'level' => 1,
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    private function entity(string $name, ?int $parentId = null): Entity
    {
        return Entity::create([
            'organization_id' => $this->organization->id,
            'entity_type_id' => $this->type->id,
            'parent_id' => $parentId,
            'entity_code' => 'ENT-'.strtoupper(substr(md5($name), 0, 4)),
            'name' => $name,
            'status' => 'active',
            'level' => 1,
            'created_by' => $this->actor->id,
        ]);
    }

    /** Two critical, one high, one medium, one low. */
    private function fiveRisksOn(Entity $entity): void
    {
        foreach (['Critical', 'Critical', 'High', 'Medium', 'Low'] as $rating) {
            $this->makeRisk(['entity_id' => $entity->id, 'inherent_rating' => $rating]);
        }
    }

    #[Test]
    public function the_dashboard_heatmap_score_is_the_weighted_mean_over_all_four_bands(): void
    {
        $entity = $this->entity('Retail');
        $this->fiveRisksOn($entity);
        $this->entity('Empty');

        $props = $this->actingAs($this->actor)->get(route('risk.scoping.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Scoping/Dashboard'))
            ->inertiaProps();

        $rows = collect($props['heatmap']);
        $retail = $rows->firstWhere('name', 'Retail');
        $empty = $rows->firstWhere('name', 'Empty');

        // (2·5 + 1·4 + 1·3 + 1·2) / 5 = 19 / 5
        $this->assertSame(3.8, (float) $retail['risk_score']);
        $this->assertSame(5, $retail['risk_total']);
        $this->assertSame(2, $retail['critical_count']);
        $this->assertSame(1, $retail['high_count']);
        $this->assertSame(1, $retail['medium_count']);
        $this->assertSame(1, $retail['low_count']);

        $this->assertSame(0.0, (float) $empty['risk_score']);
        $this->assertSame(0, $empty['risk_total']);

        // Highest score first.
        $this->assertSame('Retail', $rows->first()['name']);

        $this->assertSame(2, $props['kpis']['totalEntities']);
        $this->assertSame(5, $props['kpis']['pendingAssessments']);
    }

    #[Test]
    public function the_sub_entity_score_counts_only_critical_and_high_in_the_numerator(): void
    {
        $parent = $this->entity('Group');
        $child = $this->entity('Retail', $parent->id);
        $this->fiveRisksOn($child);

        $props = $this->actingAs($this->actor)->get(route('risk.scoping.show', $parent))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Scoping/Show'))
            ->inertiaProps();

        $sub = collect($props['subEntityHeatmap'])->firstWhere('name', 'Retail');

        // (2·5 + 1·4) / 5 = 14 / 5 — medium and low are in the denominator only.
        $this->assertSame(2.8, (float) $sub['risk_score']);
        $this->assertSame(5, $sub['risk_total']);
        $this->assertSame(2, $sub['critical_count']);
        $this->assertSame(1, $sub['high_count']);
    }

    #[Test]
    public function the_detail_posture_counts_each_band(): void
    {
        $entity = $this->entity('Retail');
        $this->fiveRisksOn($entity);

        $props = $this->actingAs($this->actor)->get(route('risk.scoping.show', $entity))
            ->assertOk()
            ->inertiaProps();

        $this->assertSame(
            ['total' => 5, 'critical' => 2, 'high' => 1, 'medium' => 1, 'low' => 1],
            $props['riskStats'],
        );

        $this->assertSame([['name' => 'Operational Risk', 'value' => 5]], $props['categoryDistribution']);
    }
}
