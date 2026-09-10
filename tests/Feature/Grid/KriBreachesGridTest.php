<?php

namespace Tests\Feature\Grid;

use App\Models\MeasureBreach;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\Support\TenantFixture;
use Tests\TestCase;

/**
 * WP-09 — the KRI breach register on the shared data grid
 * (App\Grids\Definitions\KriBreachesGrid).
 *
 * The breach grid is the only one whose base query joins another table to
 * make a relation's column searchable and sortable, so the join carrying
 * the tenant scope — rather than leaking rows through it — is the property
 * worth pinning here alongside the usual page/search assertions.
 */
class KriBreachesGridTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private TenantFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        $this->fixture = new TenantFixture;

        foreach (['kri.view', 'kri.acknowledge_breach'] as $permission) {
            Permission::findOrCreate($permission);
        }
        $this->actor->givePermissionTo(['kri.view', 'kri.acknowledge_breach']);

        $this->actingAs($this->actor);
    }

    /** Creates a breach whose measure carries $measureName, in $organizationId. */
    private function makeBreach(string $measureName, int $organizationId, array $attributes = []): MeasureBreach
    {
        static $sequence = 0;
        $sequence++;

        $measureId = $this->fixture->make('measures', $organizationId, [
            'name' => $measureName,
            'code' => sprintf('MSR-%03d', $sequence),
        ]);

        return MeasureBreach::withoutGlobalScopes()->create(array_merge([
            'organization_id' => $organizationId,
            'measure_id' => $measureId,
            'object_id' => $this->fixture->make('objects', $organizationId),
            'period_id' => $this->fixture->make('periods', $organizationId),
            'breached_at' => now()->subDays(4),
            'band_to' => 'red',
            'value' => 42,
            'threshold_value' => 10,
            'severity' => 'high',
            'status' => 'open',
        ], $attributes));
    }

    #[Test]
    public function the_breach_register_renders_a_seeded_breach(): void
    {
        $this->makeBreach('Failed transaction rate', $this->organization->id);

        $this->get(route('risk.kri.breaches'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Kri/Breaches')
                ->where('activeBreaches', 1)
                ->where('redBreaches', 1)
                ->where('grid.name', 'kri_breaches')
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.kri_name.text', 'Failed transaction rate'));
    }

    #[Test]
    public function grid_search_narrows_on_the_joined_measure_name(): void
    {
        $this->makeBreach('Failed transaction rate', $this->organization->id);
        $this->makeBreach('Staff attrition ratio', $this->organization->id);

        $this->get(route('risk.kri.breaches'))
            ->assertInertia(fn (Assert $page) => $page->has('grid.rows.data', 2));

        $this->get(route('risk.kri.breaches', ['search' => 'attrition']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.kri_name.text', 'Staff attrition ratio'));
    }

    #[Test]
    public function another_organizations_breaches_never_render(): void
    {
        $this->makeBreach('Our breach metric', $this->organization->id);

        $otherOrg = Organization::create(['name' => 'Other Bank', 'slug' => 'other-bank']);
        $this->makeBreach('Their breach metric', $otherOrg->id);

        $this->get(route('risk.kri.breaches'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.kri_name.text', 'Our breach metric'));
    }

    #[Test]
    public function the_page_opens_on_the_work_list_and_can_widen_to_closed_breaches(): void
    {
        $this->makeBreach('Open exposure metric', $this->organization->id, ['status' => 'open']);
        $this->makeBreach('Settled exposure metric', $this->organization->id, [
            'status' => 'resolved',
            'resolved_at' => now(),
        ]);

        // The register opens with status=active: resolved breaches are archive.
        $this->get(route('risk.kri.breaches'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('grid.state.filters.status', 'active')
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.kri_name.text', 'Open exposure metric'));

        $this->get(route('risk.kri.breaches', ['filters' => ['status' => 'closed']]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('grid.state.filters.status', 'closed')
                ->has('grid.rows.data', 1)
                ->where('grid.rows.data.0.cells.kri_name.text', 'Settled exposure metric'));
    }

    #[Test]
    public function acknowledging_in_bulk_requires_the_breach_permission(): void
    {
        $breach = $this->makeBreach('Escalating metric', $this->organization->id);

        $viewer = \App\Models\User::create([
            'name' => 'Read Only',
            'email' => 'breach-viewer@example.test',
            'password' => bcrypt('secret-password'),
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);
        $viewer->givePermissionTo('kri.view');

        $this->actingAs($viewer)
            ->post(route('risk.grids.bulk', ['kri_breaches', 'acknowledge']), ['ids' => [(string) $breach->id]])
            ->assertForbidden();

        $this->assertSame('open', $breach->fresh()->status);
    }
}
