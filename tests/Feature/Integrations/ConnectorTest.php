<?php

namespace Tests\Feature\Integrations;

use App\Models\Connector;
use App\Models\Measure;
use App\Models\MeasureValue;
use App\Services\Integrations\ConnectorRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\Support\CreatesMeasureFixtures;
use Tests\TestCase;

/**
 * WP-07 TASK 4 acceptance: a CSV connector pulls values into measure_values on
 * a schedule.
 *
 * THE COLUMNS THIS BRINGS TO LIFE. key_risk_indicators.is_automated and
 * automation_config have been in the schema since the KRI module shipped and
 * nothing has ever read them: an "automated" KRI was a checkbox that changed
 * nothing while somebody still typed the number in every month.
 */
class ConnectorTest extends TestCase
{
    use CreatesDomainFixtures, CreatesMeasureFixtures, RefreshDatabase;

    private Measure $measure;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->bootDomainFixtures();
        $this->bootMeasureEngine();

        Storage::fake('local');

        $this->measure = $this->measures()->requireMeasure(
            \App\Support\Measures\MeasureCatalog::RISK_FINANCIAL_EXPOSURE
        );
    }

    /* ================================================================== */

    #[Test]
    public function a_csv_connector_writes_readings_into_the_measure_engine(): void
    {
        $object = $this->anyObjectId();
        $period = $this->anyPeriod();

        $this->putCsv("measure_code,object_id,period_code,value\n{$this->measure->code},{$object},{$period->code},4200\n");

        $run = app(ConnectorRunner::class)->run($this->connector(), dryRun: false, trigger: 'manual');

        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->records_read);
        $this->assertSame(1, $run->records_written);

        $value = MeasureValue::withoutGlobalScopes()
            ->where('measure_id', $this->measure->id)
            ->where('object_id', $object)
            ->where('period_id', $period->id)
            ->first();

        $this->assertNotNull($value, 'A KRI reading is a measure value; there is no second place for it to go.');
        $this->assertSame(4200.0, (float) $value->value);
        $this->assertStringStartsWith('connector:', (string) $value->source);
    }

    #[Test]
    public function running_the_same_file_twice_does_not_double_the_readings(): void
    {
        $object = $this->anyObjectId();
        $period = $this->anyPeriod();

        $this->putCsv("measure_code,object_id,period_code,value\n{$this->measure->code},{$object},{$period->code},4200\n");

        $connector = $this->connector();

        app(ConnectorRunner::class)->run($connector, false, 'manual');
        app(ConnectorRunner::class)->run($connector->fresh(), false, 'manual');

        // updateOrCreate on the WP-04 uniqueness is what makes a connector safe
        // to re-run: reading yesterday's file again must not produce two
        // readings for one period.
        $this->assertSame(1, MeasureValue::withoutGlobalScopes()
            ->where('measure_id', $this->measure->id)
            ->where('period_id', $period->id)
            ->count());
    }

    #[Test]
    public function a_dry_run_writes_nothing_and_reports_what_it_would_have_done(): void
    {
        $object = $this->anyObjectId();
        $period = $this->anyPeriod();

        $this->putCsv("measure_code,object_id,period_code,value\n{$this->measure->code},{$object},{$period->code},999\n");

        $run = app(ConnectorRunner::class)->run($this->connector(), dryRun: true, trigger: 'manual');

        $this->assertSame(0, MeasureValue::withoutGlobalScopes()->count(),
            'A connector that silently writes four hundred wrong numbers is worse than one that does nothing.');

        $this->assertSame(1, $run->reconciliation['would_write']);
        $this->assertSame('would write', $run->reconciliation['rows'][0]['action']);
        $this->assertSame(0, $run->records_written);
    }

    #[Test]
    public function a_row_naming_an_unknown_measure_is_skipped_and_explained(): void
    {
        $object = $this->anyObjectId();
        $period = $this->anyPeriod();

        $this->putCsv(
            "measure_code,object_id,period_code,value\n"
            ."{$this->measure->code},{$object},{$period->code},10\n"
            ."NOT.A.MEASURE,{$object},{$period->code},20\n"
        );

        $run = app(ConnectorRunner::class)->run($this->connector(), dryRun: true, trigger: 'manual');

        $this->assertSame(2, $run->records_read);
        $this->assertSame(1, $run->records_skipped);

        $skipped = collect($run->reconciliation['rows'])->firstWhere('measure', 'NOT.A.MEASURE');

        // "Read 2, wrote 1" with no reason attached is the report that sends
        // somebody looking through a spreadsheet by hand.
        $this->assertSame('no measure matches that code', $skipped['skipped']);
    }

    #[Test]
    public function a_mapping_with_no_value_column_fails_loudly(): void
    {
        $this->putCsv("measure_code,object_id\nX,1\n");

        $connector = $this->connector(['measure_code' => 'measure_code', 'object_id' => 'object_id']);

        $run = app(ConnectorRunner::class)->run($connector, dryRun: true, trigger: 'manual');

        // Without this the run reads every row, writes none, and the log says
        // "read 412, wrote 0" with nothing to explain it.
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('no "value" column', (string) collect($run->errors)->first());
    }

    #[Test]
    public function a_missing_file_is_a_failure_not_an_empty_success(): void
    {
        $run = app(ConnectorRunner::class)->run($this->connector(), dryRun: false, trigger: 'manual');

        $this->assertSame('failed', $run->status);
        $this->assertSame('failed', $this->connector()->fresh()->last_status ?? 'failed');
    }

    #[Test]
    public function a_connector_that_keeps_failing_switches_itself_off(): void
    {
        $connector = $this->connector();

        $connector->forceFill(['consecutive_failures' => Connector::FAILURE_LIMIT - 1])->save();
        $connector->recordRun('failed', 'still gone');

        $this->assertFalse($connector->fresh()->is_active,
            'Retrying a dead source on every tick hides the connectors that are genuinely working.');
    }

    #[Test]
    public function the_scheduler_queues_only_the_connectors_due_on_that_cadence(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $this->connector([], ['schedule' => 'daily']);
        $this->connector([], ['schedule' => 'weekly', 'name' => 'Weekly one']);

        $this->artisan('connectors:run', ['--schedule' => 'daily'])->assertExitCode(0);

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\RunConnectorJob::class, 1);
    }

    #[Test]
    public function a_connectors_first_run_is_a_dry_run(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $this->connector([], ['schedule' => 'daily']);

        $this->artisan('connectors:run', ['--schedule' => 'daily'])->assertExitCode(0);

        // The first run is when the field mapping is most likely to be wrong,
        // and getting it wrong writes hundreds of numbers into the wrong
        // measures with nothing downstream able to tell.
        \Illuminate\Support\Facades\Queue::assertPushed(
            \App\Jobs\RunConnectorJob::class,
            fn (\App\Jobs\RunConnectorJob $job) => $job->dryRun === true,
        );
    }

    /* ================================================================== */

    private function connector(array $fieldMap = [], array $overrides = []): Connector
    {
        return Connector::firstOrCreate(
            ['organization_id' => $this->organization->id, 'name' => $overrides['name'] ?? 'Nightly KRI feed'],
            array_merge([
                'type' => 'csv',
                'config' => ['source' => 'disk', 'disk' => 'local', 'path' => 'kri/feed.csv', 'has_header' => true],
                'field_map' => $fieldMap ?: [
                    'measure_code' => 'measure_code',
                    'object_id' => 'object_id',
                    'period_code' => 'period_code',
                    'value' => 'value',
                ],
                'is_active' => true,
            ], $overrides),
        );
    }

    private function putCsv(string $contents): void
    {
        Storage::disk('local')->put('kri/feed.csv', $contents);
    }

    private function anyObjectId(): int
    {
        return \App\Models\GraphObject::withoutGlobalScopes()
            ->where('organization_id', $this->organization->id)
            ->value('id')
            ?? \App\Models\GraphObject::withoutGlobalScopes()->create([
                'organization_id' => $this->organization->id,
                'object_type_id' => \App\Models\ObjectType::withoutGlobalScopes()->value('id'),
                'code' => 'NODE-1',
                'name' => 'Test node',
            ])->id;
    }

    private function anyPeriod(): \App\Models\Period
    {
        return \App\Models\Period::withoutGlobalScopes()
            ->where('organization_id', $this->organization->id)
            ->where('type', 'month')
            ->orderBy('start_date')
            ->firstOrFail();
    }
}
