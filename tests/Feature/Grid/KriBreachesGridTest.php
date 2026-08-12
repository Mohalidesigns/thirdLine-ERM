<?php

namespace Tests\Feature\Grid;

use App\Models\MeasureBreach;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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

        $this->fixture = new TenantFixture();

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

        $this->get('/risk/kri/breaches')
            ->assertOk()
            ->assertSee('Active KRI Breaches')
            ->assertSee('Failed transaction rate');
    }

    #[Test]
    public function grid_search_narrows_on_the_joined_measure_name(): void
    {
        $this->makeBreach('Failed transaction rate', $this->organization->id);
        $this->makeBreach('Staff attrition ratio', $this->organization->id);

        Livewire::test('data-grid', ['grid' => 'kri_breaches'])
            ->assertSee('Failed transaction rate')
            ->assertSee('Staff attrition ratio')
            ->set('search', 'attrition')
            ->assertSee('Staff attrition ratio')
            ->assertDontSee('Failed transaction rate');
    }

    #[Test]
    public function another_organizations_breaches_never_render(): void
    {
        $this->makeBreach('Our breach metric', $this->organization->id);

        $otherOrg = Organization::create(['name' => 'Other Bank', 'slug' => 'other-bank']);
        $this->makeBreach('Their breach metric', $otherOrg->id);

        Livewire::test('data-grid', ['grid' => 'kri_breaches'])
            ->assertSee('Our breach metric')
            ->assertDontSee('Their breach metric');
    }

    #[Test]
    public function the_page_opens_on_the_work_list_and_can_widen_to_closed_breaches(): void
    {
        $this->makeBreach('Open exposure metric', $this->organization->id, ['status' => 'open']);
        $this->makeBreach('Settled exposure metric', $this->organization->id, [
            'status' => 'resolved',
            'resolved_at' => now(),
        ]);

        // The register mounts with status=active: resolved breaches are archive.
        $component = Livewire::test('data-grid', [
            'grid' => 'kri_breaches',
            'initialFilters' => ['status' => 'active'],
        ])
            ->assertSee('Open exposure metric')
            ->assertDontSee('Settled exposure metric');

        $component->set('filters.status', 'closed')
            ->assertSee('Settled exposure metric')
            ->assertDontSee('Open exposure metric');
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
        ]);
        $viewer->givePermissionTo('kri.view');

        Livewire::actingAs($viewer)
            ->test('data-grid', ['grid' => 'kri_breaches'])
            ->set('selected', [(string) $breach->id])
            ->call('runBulk', 'acknowledge')
            ->assertStatus(403);

        $this->assertSame('open', $breach->fresh()->status);
    }
}
