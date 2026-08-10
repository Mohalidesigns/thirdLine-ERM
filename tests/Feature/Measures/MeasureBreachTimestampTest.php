<?php

namespace Tests\Feature\Measures;

use App\Models\MeasureBreach;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * measure_breaches.breached_at must survive an update to its own row.
 *
 * On MySQL/MariaDB with `explicit_defaults_for_timestamp = OFF`, the first NOT
 * NULL TIMESTAMP column in a table is implicitly given
 * `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`. When breached_at was
 * declared that way, every refresh of a breach's value moved the moment the
 * breach began — so days-in-breach read near zero and mean-time-to-resolve
 * measured the gap since the last edit rather than the length of the breach.
 * The register looked healthiest exactly when it was being worked on.
 *
 * SQLite stores TIMESTAMP and DATETIME identically as text and has no automatic
 * update behaviour, so it cannot express the failure. The structural assertion
 * therefore skips on SQLite rather than passing vacuously and pretending to
 * cover it; the behavioural assertion runs everywhere and is the one that would
 * fail loudly on a MySQL CI.
 */
class MeasureBreachTimestampTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_column_carries_no_automatic_update_behaviour(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped(
                'Only MySQL/MariaDB apply an implicit ON UPDATE CURRENT_TIMESTAMP, so only they can fail this.'
            );
        }

        $column = collect(DB::select('SHOW COLUMNS FROM measure_breaches'))
            ->firstWhere('Field', 'breached_at');

        $this->assertNotNull($column);
        $this->assertStringNotContainsStringIgnoringCase(
            'on update',
            (string) $column->Extra,
            'breached_at must not reset itself when the breach row is updated.'
        );
        $this->assertStringNotContainsStringIgnoringCase(
            'timestamp',
            (string) $column->Type,
            'breached_at must be DATETIME: TIMESTAMP re-acquires the implicit ON UPDATE clause.'
        );
    }

    #[Test]
    public function updating_a_breach_does_not_move_when_it_began(): void
    {
        // Driver-agnostic: this is the behaviour the column type protects, and
        // it is what actually matters to MTTR.
        $this->assertTrue(Schema::hasColumn('measure_breaches', 'breached_at'));

        $organizationId = DB::table('organizations')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Timestamp Test Bank',
            'short_name' => 'TSTS',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $fixture = new \Tests\Support\TenantFixture;
        $measureId = $fixture->make('measures', $organizationId);
        $objectId = $fixture->make('objects', $organizationId);
        $periodId = $fixture->make('periods', $organizationId);

        $began = now()->subDays(9)->startOfDay();

        $breach = MeasureBreach::withoutGlobalScopes()->create([
            'organization_id' => $organizationId,
            'measure_id' => $measureId,
            'object_id' => $objectId,
            'period_id' => $periodId,
            'breached_at' => $began,
            'band_to' => 'red',
            'value' => 42,
            'severity' => 'high',
            'status' => 'open',
        ]);

        // The nightly re-read refreshing the reading — the write that used to
        // silently reset the clock.
        $breach->update(['value' => 44]);

        $this->assertSame(
            $began->toDateTimeString(),
            $breach->fresh()->breached_at->toDateTimeString(),
            'Refreshing a breach must not move the moment it began.'
        );

        $breach->update(['status' => 'resolved', 'resolved_at' => $began->copy()->addDays(9)]);

        $this->assertSame(
            216.0,
            $breach->fresh()->hoursToResolve(),
            'Nine days in breach must still read as nine days after the row has been edited.'
        );
    }
}
