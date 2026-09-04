<?php

namespace Tests\Feature\Characterisation;

use App\Models\KeyRiskIndicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * CHARACTERISATION — the KRI dashboard's figures, pinned BEFORE Phase 4.1
 * moved them out of KriController into App\Services\Kri\KriService and the page
 * moved to Inertia.
 *
 * Read off the running Blade screen first (the controller's compact() keys via
 * viewData) and then re-pointed at the Inertia props with the numbers
 * unchanged. Five KRIs, one in each status the product uses, so every count is
 * a different number from every other and a dropped status alias shows up as a
 * wrong figure rather than a coincidentally right one.
 *
 * NOTE ON `yellow`. The status column carries four values, and the KPI tiles
 * only name three: yellow is counted with green into the health score and into
 * the "Green" slice of the distribution, but has no tile of its own. That is
 * carried across exactly — it is why avgHealthScore is 60 and not 40 for these
 * five rows.
 */
class KriDashboardFiguresTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        Permission::findOrCreate('kri.view');
        $this->actor->givePermissionTo('kri.view');
        $this->actingAs($this->actor);
    }

    private function kri(array $attributes): KeyRiskIndicator
    {
        $n = ++$this->sequence;

        return KeyRiskIndicator::create(array_merge([
            'organization_id' => $this->organization->id,
            'kri_code' => sprintf('KRI-%03d', $n),
            'name' => "KRI {$n}",
            'kri_name' => "KRI {$n}",
            'metric_formula' => '',
            'data_source' => '',
            'measurement_frequency' => 'monthly',
            'unit_of_measure' => '%',
            'threshold_direction' => 'higher_worse',
            'owner_id' => $this->actor->id,
            'current_status' => 'green',
            'is_active' => true,
            'created_by' => $this->actor->id,
        ], $attributes));
    }

    private function seedKris(): void
    {
        $this->kri(['current_status' => 'red', 'current_value' => 90, 'red_threshold_min' => 80]);
        $this->kri(['current_status' => 'amber', 'current_value' => 70]);
        $this->kri(['current_status' => 'yellow', 'current_value' => 40]);
        $this->kri(['current_status' => 'green', 'current_value' => 10]);
        $this->kri(['current_status' => 'green', 'current_value' => 20]);
    }

    #[Test]
    public function the_status_counts_and_health_score(): void
    {
        $this->seedKris();

        $this->get(route('risk.kri.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Kri/Dashboard')
                ->where('kpis.total', 5)
                ->where('kpis.red', 1)
                ->where('kpis.amber', 1)
                ->where('kpis.yellow', 1)
                ->where('kpis.green', 2)
                ->where('kpis.activeBreaches', 2)      // red + amber
                ->where('kpis.avgHealthScore', 60));   // (green 2 + yellow 1) / 5
    }

    /**
     * The distribution folds yellow into Green — three slices for four stored
     * statuses.
     */
    #[Test]
    public function the_status_distribution(): void
    {
        $this->seedKris();

        $this->get(route('risk.kri.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('statusDistribution.0', ['label' => 'Green', 'value' => 3])
                ->where('statusDistribution.1', ['label' => 'Amber', 'value' => 1])
                ->where('statusDistribution.2', ['label' => 'Red', 'value' => 1]));
    }

    /** Worst first: red, amber, yellow, green. */
    #[Test]
    public function the_traffic_lights_are_ordered_worst_first(): void
    {
        $this->seedKris();

        $this->get(route('risk.kri.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('trafficLights', 5)
                ->where('trafficLights.0.status', 'red')
                ->where('trafficLights.1.status', 'amber')
                ->where('trafficLights.2.status', 'yellow')
                ->where('trafficLights.3.status', 'green'));
    }

    /** Only red and amber are breaching, worst first, capped at ten. */
    #[Test]
    public function the_breaching_list_is_red_and_amber_only(): void
    {
        $this->seedKris();

        $this->get(route('risk.kri.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('breaching', 2)
                ->where('breaching.0.name', 'KRI 1')
                ->where('breaching.0.status', 'red')
                ->where('breaching.0.currentValue', '90.00%')
                ->where('breaching.0.thresholdValue', '80.00%')
                ->where('breaching.1.name', 'KRI 2')
                ->where('breaching.1.status', 'amber')
                // No red_threshold_min on this one, so there is no limit to
                // print. The Blade screen printed "-" and so does this.
                ->where('breaching.1.thresholdValue', null));
    }

    #[Test]
    public function an_empty_organisation_reports_zeros_not_nulls(): void
    {
        $this->get(route('risk.kri.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('kpis.total', 0)
                ->where('kpis.activeBreaches', 0)
                ->where('kpis.avgHealthScore', 0)
                ->has('trafficLights', 0)
                ->has('breaching', 0));
    }
}
