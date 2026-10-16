<?php

namespace Tests\Feature\Measures;

use App\Models\KeyRiskIndicator;
use App\Models\MeasureBreach;
use App\Support\Measures\KriMeasureMigrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\Support\CreatesMeasureFixtures;
use Tests\Support\TenantFixture;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * ADR 0027 acceptance: a database migrated by the OLD KriMeasureMigrator, then
 * repaired, holds what one migrated by the corrected migrator holds.
 *
 * RefreshDatabase runs migrations on an empty database, so the repair never
 * sees a row in the suite unless a test plants the rows the old migrator
 * wrote. These are inserted with the query builder, exactly as the old code's
 * fillable save produced them.
 */
class RepairBackfilledBreachResolutionTest extends TestCase
{
    use CreatesDomainFixtures, CreatesMeasureFixtures, RefreshDatabase;

    private const MIGRATION = 'migrations/2026_10_05_100001_repair_backfilled_kri_breaches_resolved_before_they_breached.php';

    /** The note as it stood when the migration ran in production on 2026-10-04. */
    private const HISTORIC_NOTE = 'Reconstructed from the notification log during the WP-04 measure-engine migration. '
        .'The pre-WP-04 platform kept no breach register, so acknowledgement and resolution times '
        .'are not recoverable for this row.';

    private TenantFixture $fixture;

    private int $periodId;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDomainFixtures();
        $this->bootMeasureEngine();

        $this->fixture = new TenantFixture;
        $this->periodId = $this->month('2026-08-15')->id;
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    private function migration(): object
    {
        return require database_path(self::MIGRATION);
    }

    /** A breach row as the old migrator wrote it, overridden per case. */
    private function insertBreach(array $overrides = []): int
    {
        $n = ++$this->sequence;
        $organizationId = $this->organization->id;

        $row = array_merge([
            'organization_id' => $organizationId,
            'measure_id' => $this->fixture->make('measures', $organizationId, ['code' => 'REPAIR-'.$n]),
            'object_id' => $this->fixture->make('objects', $organizationId),
            'period_id' => $this->periodId,
            'breached_at' => '2026-08-31 15:00:00',
            'band_to' => 'red',
            'value' => 12,
            'severity' => 'high',
            'status' => 'resolved',
            'resolved_at' => '2026-08-31 00:00:00',
            'note' => self::HISTORIC_NOTE,
            'created_at' => '2026-10-04 09:00:00',
            'updated_at' => '2026-10-04 09:00:00',
        ], $overrides);

        return DB::table('measure_breaches')->insertGetId($row);
    }

    private function resolvedAt(int $id): ?string
    {
        return DB::table('measure_breaches')->where('id', $id)->value('resolved_at');
    }

    private function snapshot(): array
    {
        return DB::table('measure_breaches')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    #[Test]
    public function it_repairs_exactly_the_rows_the_old_migrator_wrote_and_nothing_else(): void
    {
        $a = $this->insertBreach();
        $b = $this->insertBreach(['breached_at' => '2026-08-03 10:00:00']);
        $c = $this->insertBreach(['updated_at' => '2026-10-04 09:01:00']);
        $d = $this->insertBreach(['note' => null]);
        $e = $this->insertBreach(['status' => 'open', 'resolved_at' => null]);
        $f = $this->insertBreach(['resolved_at' => '2026-08-31 23:59:59']);

        $this->migration()->up();

        $this->assertSame('2026-08-31 23:59:59', $this->resolvedAt($a), 'The reported defect: resolved before it breached.');
        $this->assertSame('2026-08-31 23:59:59', $this->resolvedAt($b), 'Mid-month rows carry the same convention, so they are repaired too.');
        $this->assertSame('2026-08-31 00:00:00', $this->resolvedAt($c), 'Written since the backfill: left as its editor left it.');
        $this->assertSame('2026-08-31 00:00:00', $this->resolvedAt($d), 'A real breach has no migrator note.');
        $this->assertNull($this->resolvedAt($e), 'Reopened, so not a backfill any more.');
        $this->assertSame('2026-08-31 23:59:59', $this->resolvedAt($f), 'Already correct.');

        foreach ([$a, $b] as $id) {
            $row = DB::table('measure_breaches')->find($id);
            $this->assertSame($row->created_at, $row->updated_at, 'The repair must not touch updated_at.');
            $this->assertSame(self::HISTORIC_NOTE, $row->note);
        }
    }

    #[Test]
    public function it_leaves_an_acknowledged_row_and_a_row_off_period_midnight_alone(): void
    {
        $acknowledged = $this->insertBreach(['acknowledged_at' => '2026-08-31 12:00:00']);
        // The shape a changed server time zone leaves: not exactly period midnight.
        $shifted = $this->insertBreach(['resolved_at' => '2026-08-30 23:00:00']);

        $this->migration()->up();

        $this->assertSame('2026-08-31 00:00:00', $this->resolvedAt($acknowledged));
        $this->assertSame('2026-08-30 23:00:00', $this->resolvedAt($shifted), 'Skipped, not mis-repaired.');
    }

    #[Test]
    public function running_it_twice_changes_nothing(): void
    {
        $this->insertBreach();
        $this->insertBreach(['breached_at' => '2026-08-03 10:00:00']);
        $this->insertBreach(['updated_at' => '2026-10-04 09:01:00']);
        $this->insertBreach(['note' => null]);

        $this->migration()->up();
        $once = $this->snapshot();

        $this->migration()->up();

        $this->assertSame($once, $this->snapshot());
    }

    #[Test]
    public function a_repaired_row_equals_one_written_by_the_corrected_migrator(): void
    {
        $repaired = $this->insertBreach();
        $this->migration()->up();
        $repairedRow = DB::table('measure_breaches')->find($repaired);

        // A second organisation, the same notification, the corrected migrator.
        $this->bootDomainFixtures('Second Bank PLC');
        $this->bootMeasureEngine();

        $kri = KeyRiskIndicator::create([
            'organization_id' => $this->organization->id,
            'kri_code' => 'KRI-EQUIV-1',
            'name' => 'Equivalence indicator',
            'measurement_frequency' => 'monthly',
            'unit_of_measure' => '%',
            'threshold_direction' => 'higher_worse',
            'green_threshold_max' => 3,
            'amber_threshold_min' => 3,
            'amber_threshold_max' => 6,
            'red_threshold_min' => 6,
            'is_active' => true,
            'owner_id' => $this->actor->id,
            'created_by' => $this->actor->id,
        ]);

        DB::table('notifications_log')->insert([
            'organization_id' => $this->organization->id,
            'user_id' => $this->actor->id,
            'channel' => 'database',
            'type' => 'kri_breach',
            'subject' => 'KRI Breach Alert: KRI-EQUIV-1',
            'body' => "KRI 'Equivalence indicator' has breached red threshold.",
            'status' => 'sent',
            'action_url' => "/risk/kri/{$kri->id}",
            'created_at' => '2026-08-31 15:00:00',
            'updated_at' => '2026-08-31 15:00:00',
        ]);

        app(KriMeasureMigrator::class)->migrateOrganization($this->organization->id);

        $fresh = DB::table('measure_breaches')
            ->where('organization_id', $this->organization->id)
            ->first();

        $this->assertNotNull($fresh);
        $this->assertSame($repairedRow->breached_at, $fresh->breached_at);
        $this->assertSame($repairedRow->resolved_at, $fresh->resolved_at);
        $this->assertSame($repairedRow->note, $fresh->note);
        $this->assertSame($fresh->created_at, $fresh->updated_at, 'The fresh row is the shape the repair preserves.');
        $this->assertSame($repairedRow->created_at, $repairedRow->updated_at);
    }

    #[Test]
    public function no_row_is_left_measurably_negative(): void
    {
        $this->insertBreach();
        $this->insertBreach(['breached_at' => '2026-08-03 10:00:00']);
        $this->insertBreach(['updated_at' => '2026-10-04 09:01:00']);
        $this->insertBreach(['note' => null]);

        $this->migration()->up();

        foreach (MeasureBreach::withoutGlobalScopes()->get() as $breach) {
            $hours = $breach->hoursToResolve();
            $this->assertTrue($hours === null || $hours >= 0, "Breach {$breach->id} reads {$hours} hours.");
        }

        // The repaired 15:00 row is the one the defect reported at -15.0.
        $this->assertSame(9.0, MeasureBreach::withoutGlobalScopes()->where('band_to', 'red')->orderBy('id')->first()->hoursToResolve());
    }

    #[Test]
    public function the_note_is_pinned_in_the_migrator_and_the_migration(): void
    {
        $this->assertSame(self::HISTORIC_NOTE, KriMeasureMigrator::RECONSTRUCTED_NOTE);
        $this->assertSame(self::HISTORIC_NOTE, $this->migration()::NOTE);
        $this->assertSame(KriMeasureMigrator::RECONSTRUCTED_NOTE, $this->migration()::NOTE);
    }
}
