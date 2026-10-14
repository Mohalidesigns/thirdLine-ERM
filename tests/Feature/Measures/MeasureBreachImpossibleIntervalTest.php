<?php

namespace Tests\Feature\Measures;

use App\Models\MeasureBreach;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\Support\CreatesMeasureFixtures;
use Tests\Support\TenantFixture;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * ADR 0027 §3: an interval that ends before it starts is unmeasurable. The
 * readers return null for it and the mean leaves it out of numerator and
 * denominator. They do not clamp it to zero, which would invent "resolved the
 * moment it breached" (DEVELOPMENT_STANDARD: computed or absent).
 */
class MeasureBreachImpossibleIntervalTest extends TestCase
{
    use CreatesDomainFixtures, CreatesMeasureFixtures, RefreshDatabase;

    private TenantFixture $fixture;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDomainFixtures();
        $this->bootMeasureEngine();
        $this->fixture = new TenantFixture;
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    private function insertBreach(array $overrides): MeasureBreach
    {
        $organizationId = $this->organization->id;
        $n = ++$this->sequence;

        $id = DB::table('measure_breaches')->insertGetId(array_merge([
            'organization_id' => $organizationId,
            'measure_id' => $this->fixture->make('measures', $organizationId, ['code' => 'IMP-'.$n]),
            'object_id' => $this->fixture->make('objects', $organizationId),
            'period_id' => $this->month('2026-08-15')->id,
            'breached_at' => '2026-08-31 15:00:00',
            'band_to' => 'red',
            'value' => 12,
            'severity' => 'high',
            'status' => 'resolved',
            'created_at' => '2026-10-04 09:00:00',
            'updated_at' => '2026-10-04 09:00:00',
        ], $overrides));

        return MeasureBreach::findOrFail($id);
    }

    #[Test]
    public function a_resolution_before_the_breach_is_null_and_nothing_else_is_made_up(): void
    {
        $impossible = $this->insertBreach(['resolved_at' => '2026-08-31 00:00:00']);

        $this->assertNull($impossible->hoursToResolve());
        $this->assertNull(MeasureBreach::meanTimeToResolveHours(), 'No measurable row is left, so there is no mean.');
    }

    #[Test]
    public function the_mean_excludes_an_impossible_row_from_numerator_and_denominator(): void
    {
        $this->insertBreach(['resolved_at' => '2026-08-31 00:00:00']);
        $this->insertBreach(['breached_at' => '2026-08-01 08:00:00', 'resolved_at' => '2026-08-03 08:00:00']);

        $this->assertSame(48.0, MeasureBreach::meanTimeToResolveHours());
    }

    #[Test]
    public function an_acknowledgement_before_the_breach_is_null(): void
    {
        $reopened = $this->insertBreach([
            'status' => 'acknowledged',
            'acknowledged_at' => '2026-08-30 09:00:00',
        ]);

        $this->assertNull($reopened->hoursToAcknowledge());
    }

    #[Test]
    public function a_valid_interval_and_a_zero_length_one_are_still_reported(): void
    {
        $day = $this->insertBreach(['breached_at' => '2026-08-01 08:00:00', 'resolved_at' => '2026-08-02 08:00:00', 'acknowledged_at' => '2026-08-01 20:00:00']);
        $instant = $this->insertBreach(['resolved_at' => '2026-08-31 15:00:00']);

        $this->assertSame(24.0, $day->hoursToResolve());
        $this->assertSame(12.0, $day->hoursToAcknowledge());
        $this->assertSame(0.0, $instant->hoursToResolve(), 'Equal instants are a real, zero-length interval.');
    }
}
