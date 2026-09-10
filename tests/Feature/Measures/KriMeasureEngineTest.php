<?php

namespace Tests\Feature\Measures;

use App\Models\KeyRiskIndicator;
use App\Models\Measure;
use App\Models\MeasureBreach;
use App\Models\MeasureThreshold;
use App\Models\MeasureValue;
use App\Services\KriMeasureBridge;
use App\Support\Measures\KriMeasureMigrator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\Support\CreatesMeasureFixtures;
use Tests\TestCase;

/**
 * WP-04 TASK 3 acceptance: KRIs read and write through measure_values,
 * kri_measurements is backfilled, measure_breaches has rows, and
 * acknowledgement and MTTR are computable.
 */
class KriMeasureEngineTest extends TestCase
{
    use CreatesDomainFixtures, CreatesMeasureFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Early in the fiscal year, so a KRI created here precedes the
        // readings the tests record against it — which is the real order of
        // events, and the order the band effective dates assume.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-05 09:00:00'));
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-01-05 09:00:00'));

        $this->bootDomainFixtures();
        $this->bootMeasureEngine();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Carbon\Carbon::setTestNow();

        parent::tearDown();
    }

    private function bridge(): KriMeasureBridge
    {
        return app(KriMeasureBridge::class);
    }

    /** A higher-is-worse KRI: green below 5, amber 5-10, red at 10 and above. */
    private function higherWorseKri(): KeyRiskIndicator
    {
        return $this->makeKri([
            'green_threshold_max' => 5,
            'amber_threshold_min' => 5,
            'amber_threshold_max' => 10,
            'red_threshold_min' => 10,
        ]);
    }

    #[Test]
    public function creating_a_kri_defines_a_measure_and_its_bands(): void
    {
        $kri = $this->higherWorseKri();

        $measure = Measure::where('code', $kri->kri_code)->firstOrFail();

        $this->assertSame('kri', $measure->measure_kind);
        $this->assertSame('lower_better', $measure->polarity, 'A higher-is-worse indicator is better when lower.');

        $threshold = MeasureThreshold::where('measure_id', $measure->id)->whereNull('effective_to')->firstOrFail();

        $this->assertSame('higher_worse', $threshold->direction);
        $this->assertSame(['green', 'amber', 'red'], collect($threshold->bands)->pluck('code')->all());
        $this->assertEquals(10, collect($threshold->bands)->firstWhere('code', 'red')['min']);
    }

    #[Test]
    public function a_reading_lands_in_the_measure_engine_and_is_mirrored_onto_the_facade(): void
    {
        $this->actingAs($this->actor);
        $kri = $this->higherWorseKri();

        $result = $this->bridge()->recordMeasurement($kri, '2026-03-14', 12.0, ['notes' => 'March reading']);

        $this->assertSame('red', $result['band']);
        $this->assertSame('FY2026-M03', $result['period']->code);

        $value = MeasureValue::firstOrFail();
        $this->assertEquals(12.0, $value->value);
        $this->assertSame('red', $value->rag_band);
        $this->assertSame('actual', $value->scenario);

        // The facade stays in step for one release.
        $this->assertEquals(12.0, $kri->fresh()->current_value);
        $this->assertSame('red', $kri->fresh()->current_status);
        $this->assertDatabaseHas('kri_measurements', [
            'kri_id' => $kri->id,
            'measurement_date' => '2026-03-14',
            'status' => 'red',
        ]);
    }

    #[Test]
    public function band_boundaries_match_the_ladder_they_replace(): void
    {
        $this->actingAs($this->actor);
        $higher = $this->higherWorseKri();

        // higher_worse: [min, max). The value ON a limit belongs to the worse band.
        $this->assertSame('green', $this->bridge()->recordMeasurement($higher, '2026-01-05', 4.9)['band']);
        $this->assertSame('amber', $this->bridge()->recordMeasurement($higher, '2026-02-05', 5.0)['band']);
        $this->assertSame('amber', $this->bridge()->recordMeasurement($higher, '2026-03-05', 9.9)['band']);
        $this->assertSame('red', $this->bridge()->recordMeasurement($higher, '2026-04-05', 10.0)['band']);

        // lower_worse: (min, max]. Same rule, read the other way round.
        $lower = $this->makeKri([
            'threshold_direction' => 'lower_worse',
            'direction' => 'lower_is_worse',
            'red_threshold_max' => 10,
            'amber_threshold_min' => 10,
            'amber_threshold_max' => 20,
            'green_threshold_min' => 20,
        ]);

        $this->assertSame('red', $this->bridge()->recordMeasurement($lower, '2026-01-05', 10.0)['band']);
        $this->assertSame('amber', $this->bridge()->recordMeasurement($lower, '2026-02-05', 10.1)['band']);
        $this->assertSame('amber', $this->bridge()->recordMeasurement($lower, '2026-03-05', 20.0)['band']);
        $this->assertSame('green', $this->bridge()->recordMeasurement($lower, '2026-04-05', 20.1)['band']);
    }

    #[Test]
    public function a_breach_becomes_a_register_row_with_a_lifecycle(): void
    {
        $this->actingAs($this->actor);
        $risk = $this->makeRisk();
        $kri = $this->higherWorseKri();
        $kri->forceFill(['risk_id' => $risk->id])->save();

        $this->bridge()->recordMeasurement($kri, '2026-03-14', 12.0);

        $breach = MeasureBreach::firstOrFail();

        $this->assertSame('red', $breach->band_to);
        $this->assertSame('open', $breach->status);
        $this->assertSame('high', $breach->severity);
        $this->assertEquals(12.0, $breach->value);
        $this->assertEquals(10.0, $breach->threshold_value);
        $this->assertSame($risk->id, $breach->linked_risk_id);
        $this->assertNull($breach->hoursToResolve());
    }

    #[Test]
    public function re_reading_the_same_breach_does_not_stack_rows_or_clear_an_acknowledgement(): void
    {
        $this->actingAs($this->actor);
        $kri = $this->higherWorseKri();

        $this->bridge()->recordMeasurement($kri, '2026-03-01', 12.0);

        $breach = MeasureBreach::firstOrFail();
        $breach->update([
            'status' => 'acknowledged',
            'acknowledged_by' => $this->actor->id,
            // breached_at is the measurement's own timestamp, midnight on the
            // 1st, so this is exactly a day later.
            'acknowledged_at' => CarbonImmutable::parse('2026-03-02 00:00:00'),
        ]);

        // A correction inside the same month, still over the limit.
        $this->bridge()->recordMeasurement($kri, '2026-03-20', 14.0);

        $this->assertSame(1, MeasureBreach::count());
        $breach->refresh();
        $this->assertSame('acknowledged', $breach->status);
        $this->assertEquals(14.0, $breach->value);
        $this->assertSame(24.0, $breach->hoursToAcknowledge());
    }

    #[Test]
    public function returning_within_limits_resolves_the_breach_and_makes_mttr_computable(): void
    {
        $this->actingAs($this->actor);
        $kri = $this->higherWorseKri();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-01 08:00:00'));
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-03-01 08:00:00'));
        $this->bridge()->recordMeasurement($kri, '2026-03-01', 12.0);

        $this->assertSame('open', MeasureBreach::firstOrFail()->status);
        $this->assertNull(MeasureBreach::meanTimeToResolveHours(), 'Nothing is resolved yet.');

        // Back inside appetite, two days later.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-03 08:00:00'));
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-03-03 08:00:00'));
        $this->bridge()->recordMeasurement($kri, '2026-03-03', 2.0);

        $breach = MeasureBreach::firstOrFail();
        $this->assertSame('resolved', $breach->status);
        $this->assertSame(48.0, $breach->hoursToResolve());
        $this->assertSame(48.0, MeasureBreach::meanTimeToResolveHours());
    }

    #[Test]
    public function going_red_supersedes_an_open_amber_for_the_same_period(): void
    {
        $this->actingAs($this->actor);
        $kri = $this->higherWorseKri();

        $this->bridge()->recordMeasurement($kri, '2026-03-01', 7.0);
        $this->assertSame('amber', MeasureBreach::firstOrFail()->band_to);

        $this->bridge()->recordMeasurement($kri, '2026-03-20', 12.0);

        $this->assertSame('resolved', MeasureBreach::where('band_to', 'amber')->firstOrFail()->status);
        $this->assertSame('open', MeasureBreach::where('band_to', 'red')->firstOrFail()->status);
    }

    #[Test]
    public function editing_a_threshold_writes_a_new_effective_dated_band_set(): void
    {
        $this->actingAs($this->actor);
        $kri = $this->higherWorseKri();

        $original = MeasureThreshold::whereNull('effective_to')->firstOrFail();

        $kri->forceFill(['red_threshold_min' => 20, 'amber_threshold_max' => 20])->save();
        $this->bridge()->syncDefinition($kri->fresh());

        $this->assertSame(2, MeasureThreshold::count());

        $replacement = MeasureThreshold::whereNull('effective_to')->firstOrFail();
        $this->assertSame($original->id, $replacement->supersedes_id);
        $this->assertEquals(20, collect($replacement->bands)->firstWhere('code', 'red')['min']);

        // The old band set is retained, unedited.
        $original->refresh();
        $this->assertNotNull($original->effective_to);
        $this->assertEquals(10, collect($original->bands)->firstWhere('code', 'red')['min']);
    }

    #[Test]
    public function an_unchanged_edit_does_not_churn_the_band_history(): void
    {
        $this->actingAs($this->actor);
        $kri = $this->higherWorseKri();

        $this->bridge()->syncDefinition($kri->fresh());
        $this->bridge()->syncDefinition($kri->fresh());

        $this->assertSame(1, MeasureThreshold::count());
    }

    #[Test]
    public function the_migrator_moves_legacy_kris_and_their_history_into_the_engine(): void
    {
        // A KRI written the way the pre-WP-04 platform wrote them: rows in
        // key_risk_indicators and kri_measurements, nothing in the engine.
        $kri = KeyRiskIndicator::create([
            'organization_id' => $this->organization->id,
            'kri_code' => 'KRI-LEGACY-1',
            'name' => 'Legacy indicator',
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

        foreach ([['2026-01-31', 2.0, 'green'], ['2026-02-28', 4.5, 'amber'], ['2026-03-31', 7.5, 'red']] as [$date, $value, $status]) {
            DB::table('kri_measurements')->insert([
                'kri_id' => $kri->id,
                'measurement_date' => $date,
                'value' => $value,
                'status' => $status,
                'entered_by' => $this->actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // A breach that survived only as a notification.
        DB::table('notifications_log')->insert([
            'organization_id' => $this->organization->id,
            'user_id' => $this->actor->id,
            'channel' => 'database',
            'type' => 'kri_breach',
            'subject' => 'KRI Breach Alert: KRI-LEGACY-1',
            'body' => "KRI 'Legacy indicator' has breached red threshold. Linked risks have been flagged for review.",
            'status' => 'sent',
            'action_url' => "/risk/kri/{$kri->id}",
            'created_at' => '2026-03-31 07:00:00',
            'updated_at' => '2026-03-31 07:00:00',
        ]);

        $counts = app(KriMeasureMigrator::class)->migrateOrganization($this->organization->id);

        $this->assertSame(1, $counts['measures']);
        $this->assertSame(1, $counts['thresholds']);
        $this->assertSame(3, $counts['values']);
        $this->assertSame(1, $counts['breaches']);

        $measure = Measure::where('code', 'KRI-LEGACY-1')->firstOrFail();
        $this->assertSame('pct', $measure->unit->code, '"%" maps onto the percent unit.');

        $values = MeasureValue::where('measure_id', $measure->id)->orderBy('id')->get();
        $this->assertCount(3, $values);
        $this->assertEquals([2.0, 4.5, 7.5], $values->pluck('value')->map(fn ($v) => (float) $v)->all());
        $this->assertSame(['green', 'amber', 'red'], $values->pluck('rag_band')->all());
        $this->assertSame(['migration', 'migration', 'migration'], $values->pluck('source')->all());

        $breach = MeasureBreach::firstOrFail();
        $this->assertSame('red', $breach->band_to);
        $this->assertStringContainsString('Reconstructed from the notification log', $breach->note);
    }

    #[Test]
    public function bands_cover_readings_that_predate_the_kri_record(): void
    {
        // The shape a migration or a data load leaves behind: the readings were
        // loaded first, the indicator record was created afterwards. Dating the
        // band set from created_at would leave all of that history unbanded.
        $kri = KeyRiskIndicator::create([
            'organization_id' => $this->organization->id,
            'kri_code' => 'KRI-BACKDATED',
            'name' => 'Imported indicator',
            'measurement_frequency' => 'monthly',
            'unit_of_measure' => '%',
            'threshold_direction' => 'higher_worse',
            'green_threshold_max' => 5,
            'amber_threshold_min' => 5,
            'amber_threshold_max' => 10,
            'red_threshold_min' => 10,
            'is_active' => true,
            'owner_id' => $this->actor->id,
            'created_by' => $this->actor->id,
        ]);

        DB::table('kri_measurements')->insert([
            'kri_id' => $kri->id,
            'measurement_date' => '2025-08-15',
            'value' => 12.0,
            'status' => 'red',
            'entered_by' => $this->actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(KriMeasureMigrator::class)->migrateOrganization($this->organization->id);

        $measure = Measure::where('code', 'KRI-BACKDATED')->firstOrFail();
        $threshold = MeasureThreshold::where('measure_id', $measure->id)->firstOrFail();

        $this->assertSame(
            '2025-08-15',
            $threshold->effective_from->toDateString(),
            'The first band set must reach back to the earliest reading it governs.'
        );

        // ...and that reading therefore resolves to a band.
        $value = MeasureValue::where('measure_id', $measure->id)->firstOrFail();
        $this->assertSame('red', $this->measures()->bandCodeFor(
            $measure, (int) $value->object_id, (float) $value->value, $value->period
        ));
    }

    #[Test]
    public function the_nightly_check_never_blanks_a_recorded_band(): void
    {
        // A reading whose date falls outside every band set's effective window
        // — the exact condition that made the first live run erase nine bands.
        $this->actingAs($this->actor);
        $kri = $this->higherWorseKri();
        $measure = Measure::where('code', $kri->kri_code)->firstOrFail();

        MeasureThreshold::where('measure_id', $measure->id)
            ->update(['effective_from' => '2026-06-01']);

        $period = $this->month('2026-03-14');
        $value = $this->measures()->record($measure, $kri, $period, 12.0, ['detect_breach' => false]);
        $value->forceFill(['rag_band' => 'amber'])->save();

        $this->artisan('kri:check-breaches', ['--organization' => $this->organization->id])
            ->expectsOutputToContain('had no threshold in force')
            ->assertSuccessful();

        $this->assertSame(
            'amber',
            $value->fresh()->rag_band,
            'A missing threshold means no rule was in force, not that the reading had no colour.'
        );
    }

    #[Test]
    public function the_migrator_is_idempotent(): void
    {
        $kri = $this->higherWorseKri();
        $this->bridge()->recordMeasurement($kri, '2026-03-14', 12.0, ['entered_by' => $this->actor->id]);

        $migrator = app(KriMeasureMigrator::class);
        $migrator->migrateOrganization($this->organization->id);
        $migrator->migrateOrganization($this->organization->id);

        $this->assertSame(1, Measure::where('code', $kri->kri_code)->count());
        $this->assertSame(1, MeasureValue::count());
        $this->assertSame(1, MeasureThreshold::count());
    }

    #[Test]
    public function the_nightly_check_opens_the_breach_it_never_used_to(): void
    {
        $this->actingAs($this->actor);
        $kri = $this->higherWorseKri();

        // A reading recorded without breach detection — the shape an import or
        // a direct engine write leaves behind.
        $measure = Measure::where('code', $kri->kri_code)->firstOrFail();
        $this->measures()->record($measure, $kri, $this->month('2026-03-14'), 12.0, ['detect_breach' => false]);

        $this->assertSame(0, MeasureBreach::count());

        $this->artisan('kri:check-breaches', ['--organization' => $this->organization->id])
            ->assertSuccessful();

        $breach = MeasureBreach::firstOrFail();
        $this->assertSame('red', $breach->band_to);
        $this->assertSame('open', $breach->status);
    }
}
