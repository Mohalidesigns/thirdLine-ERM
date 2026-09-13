<?php

namespace Tests\Feature\Characterisation;

use App\Models\KeyRiskIndicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * The Executive report's figures (migration Phase 5.4).
 *
 * Written against the running Blade screen before `ExecutiveReportService`
 * existed. TWO FIGURES ARE CHANGED, both documented on the service.
 *
 * 1. THE AMBER KRI TILE COUNTED A VALUE NOTHING WRITES. It queried
 *    `current_status = 'yellow'`. The band vocabulary the measure engine
 *    writes is red / amber / green — `KriMeasureMigrator` emits `'amber'` —
 *    and every other consumer counts both spellings (`BoardPackAssembler`:
 *    `whereIn(['amber', 'yellow'])`). This tile counted the legacy spelling
 *    ALONE, so every KRI the current engine bands as amber was missing from
 *    the executive pack's amber count and from its KRI status chart.
 *
 * 2. THE 12-MONTH TREND COULD ONLY SLOPE UPWARD. It ran twelve separate
 *    COUNT queries, each filtering `status = 'active'` — the risk's status
 *    TODAY — against `created_at <= end of month`. A risk opened in January
 *    and archived in June was therefore absent from January's bucket too, so
 *    the series described the current register projected backwards rather
 *    than the register as it stood. This is 5.1's defect exactly: *"a chart
 *    labelled trend may be reading today's number for every historical
 *    bucket"*.
 */
class ExecutiveReportCharacterisationTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        Permission::findOrCreate('report.view');
        $this->actor->givePermissionTo('report.view');
    }

    /* ------------------------------------------------------------------ */

    /** THE FIRST CHANGED FIGURE. Amber is `amber`, and `yellow` still counts. */
    #[Test]
    public function the_amber_tile_counts_the_band_the_engine_actually_writes(): void
    {
        $this->kri(['current_status' => 'amber']);
        $this->kri(['current_status' => 'amber']);
        $this->kri(['current_status' => 'yellow']);
        $this->kri(['current_status' => 'green']);
        $this->kri(['current_status' => 'red']);

        $data = $this->executive();

        $this->assertSame(3, $data['kriAmber'], 'Two amber plus the legacy yellow.');
        $this->assertSame(1, $data['kriGreen']);
        $this->assertSame(1, $data['kriRed']);
        $this->assertSame(1, $data['kriBreaches']);

        $this->assertSame(['Green', 'Amber', 'Red'], $data['kriStatusChartData']['labels']);
        $this->assertSame([1, 3, 1], $data['kriStatusChartData']['values']);
    }

    /**
     * THE SECOND. A risk that is on the register in January is counted in
     * January, whatever became of it since.
     */
    #[Test]
    public function the_trend_counts_the_register_as_it_stood_not_as_it_stands(): void
    {
        // Opened ten months ago and archived since. It was on the register.
        $this->makeRisk([
            'status' => 'archived',
            'date_identified' => now()->subMonths(10)->startOfMonth()->toDateString(),
        ]);

        // Opened last month and still active.
        $this->makeRisk([
            'status' => 'active',
            'date_identified' => now()->subMonth()->startOfMonth()->toDateString(),
        ]);

        $trend = $this->executive()['trendChartData'];

        $this->assertCount(12, $trend['labels']);
        $this->assertCount(12, $trend['values']);

        // Ten months back: the archived one existed, the other did not.
        $this->assertSame(1, $trend['values'][1], 'The archived risk was on the register that month.');

        // The most recent bucket carries both.
        $this->assertSame(2, $trend['values'][11]);

        // And the series never goes backwards in this fixture, but that is a
        // property of the data rather than of the query.
        $this->assertSame(0, $trend['values'][0], 'Neither risk existed eleven months ago.');
    }

    /** Absent inputs stay absent on the headline tiles. */
    #[Test]
    public function an_empty_organisation_reports_absence_not_zero(): void
    {
        $data = $this->executive();

        $this->assertSame(0, $data['totalRisks'], 'A count of nothing is genuinely zero.');
        $this->assertNull($data['treatmentCompletion'], 'But a RATE over nothing is not.');
        $this->assertNull($data['appetiteStatus'], 'And neither is an appetite position with no statements.');
    }

    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private function executive(): array
    {
        return $this->actingAs($this->actor)
            ->get(route('risk.reports.executive'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Reports/Executive'))
            ->inertiaProps();
    }

    private function kri(array $attributes = []): KeyRiskIndicator
    {
        $n = KeyRiskIndicator::count() + 1;

        return KeyRiskIndicator::create(array_merge([
            'organization_id' => $this->organization->id,
            'kri_code' => 'KRI-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'name' => 'Indicator '.$n,
            'data_source' => 'Core banking',
            'measurement_frequency' => 'monthly',
            'unit_of_measure' => 'count',
            'created_by' => $this->actor->id,
        ], $attributes));
    }
}
