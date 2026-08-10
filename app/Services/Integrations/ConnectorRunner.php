<?php

namespace App\Services\Integrations;

use App\Integrations\Contracts\Connector as ConnectorContract;
use App\Models\Connector;
use App\Models\ConnectorRun;
use App\Models\KeyRiskIndicator;
use App\Models\Measure;
use App\Models\MeasureValue;
use App\Models\Period;
use App\Models\User;
use App\Services\PeriodService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * WP-07 TASK 4 — runs a connector and writes what it read into the measure
 * engine.
 *
 * THE CONNECTOR NEVER WRITES. It returns rows; this writes them. That is what
 * makes tenancy, the dry run, the reconciliation report and the run log behave
 * identically whoever wrote the connector — including a customer's own
 * integration team, who should not have to get the organization scope right in
 * order to read a CSV.
 *
 * WHAT IT WRITES INTO. measure_values, keyed on
 * (measure, object, period, scenario) — the WP-04 model. A KRI reading is a
 * measure value; there is no second place for it to go, and writing to
 * kri_measurements instead would put automated readings somewhere the trend
 * charts and the breach register do not look.
 *
 * DRY RUN IS THE DEFAULT FOR A FIRST RUN, and produces a reconciliation an
 * operator reads before letting anything write: how many rows matched a
 * measure, how many did not, and what the values would have been. A connector
 * that silently writes four hundred wrong numbers into a capital return is
 * worse than one that does nothing.
 */
class ConnectorRunner
{
    public function __construct(private PeriodService $periods) {}

    /**
     * @return ConnectorRun the run record, with counts and any reconciliation
     */
    public function run(
        Connector $connector,
        bool $dryRun = false,
        string $trigger = 'schedule',
        ?User $actor = null,
        ?int $jobRunId = null,
    ): ConnectorRun {
        $run = ConnectorRun::withoutGlobalScopes()->create([
            'organization_id' => $connector->organization_id,
            'connector_id' => $connector->id,
            'trigger' => $trigger,
            'dry_run' => $dryRun,
            'status' => 'running',
            'started_at' => now(),
            'triggered_by' => $actor?->id,
            'job_run_id' => $jobRunId,
        ]);

        try {
            $result = TenantContext::actingAs(
                $connector->organization_id,
                fn () => $this->execute($connector, $run, $dryRun),
            );

            $run->update([
                'status' => 'completed',
                'finished_at' => now(),
            ] + $result);

            $connector->recordRun('completed');
        } catch (Throwable $e) {
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'errors' => array_merge((array) $run->errors, [$e->getMessage()]),
            ]);

            $connector->recordRun('failed', $e->getMessage());
        }

        return $run->fresh();
    }

    /* ------------------------------------------------------------------ */

    /**
     * @return array{records_read:int, records_written:int, records_skipped:int, errors:?array, reconciliation:?array}
     */
    private function execute(Connector $connector, ConnectorRun $run, bool $dryRun): array
    {
        $driver = $this->driver($connector);
        $driver->authenticate($connector);

        $map = (array) $connector->field_map;

        $this->assertMapped($map);

        // Incremental where the connector supports it: last_run_at less a
        // margin, because a source that stamps rows on write and a platform
        // that reads on a schedule will otherwise miss anything written during
        // the previous run.
        $since = $connector->last_run_at !== null
            ? CarbonImmutable::instance($connector->last_run_at)->subMinutes(10)
            : null;

        $read = 0;
        $written = 0;
        $skipped = 0;
        $errors = [];
        $reconciliation = [];

        foreach ($driver->pull($connector, $since) as $row) {
            $read++;

            try {
                $mapped = $this->mapRow((array) $row, $map);
                $outcome = $this->write($connector, $mapped, $dryRun);

                if ($outcome['written']) {
                    $written++;
                } else {
                    $skipped++;
                }

                // Bounded: a reconciliation nobody can open is not a
                // reconciliation.
                if ($dryRun && count($reconciliation) < 500) {
                    $reconciliation[] = $outcome['summary'];
                }
            } catch (Throwable $e) {
                $skipped++;

                if (count($errors) < 200) {
                    $errors[] = "Row {$read}: ".$e->getMessage();
                }
            }
        }

        return [
            'records_read' => $read,
            'records_written' => $dryRun ? 0 : $written,
            'records_skipped' => $skipped,
            'errors' => $errors ?: null,
            'reconciliation' => $dryRun
                ? ['would_write' => $written, 'would_skip' => $skipped, 'rows' => $reconciliation]
                : null,
        ];
    }

    /**
     * Turn a source row into the platform's field names.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $map  platform field => source column
     * @return array<string, mixed>
     */
    private function mapRow(array $row, array $map): array
    {
        $mapped = [];

        foreach ($map as $field => $column) {
            if ($column === null || $column === '') {
                continue;
            }

            $value = $row[$column] ?? null;

            // An empty cell is absent, not an empty string — the same rule the
            // spreadsheet importer applies, for the same reason.
            if ($value !== null && $value !== '') {
                $mapped[$field] = is_string($value) ? trim($value) : $value;
            }
        }

        return $mapped;
    }

    /**
     * Write one reading into the measure engine.
     *
     * @param  array<string, mixed>  $row
     * @return array{written: bool, summary: array<string, mixed>}
     */
    private function write(Connector $connector, array $row, bool $dryRun): array
    {
        $measure = $this->resolveMeasure($connector, $row);
        $period = $this->resolvePeriod($connector, $row);
        $objectId = $this->resolveObjectId($measure, $row);
        $value = $row['value'] ?? null;

        $summary = [
            'measure' => $measure?->code ?? ($row['measure_code'] ?? $row['kri_code'] ?? null),
            'period' => $period?->code,
            'object_id' => $objectId,
            'value' => $value,
        ];

        if ($measure === null) {
            return ['written' => false, 'summary' => $summary + ['skipped' => 'no measure matches that code']];
        }

        if ($period === null) {
            return ['written' => false, 'summary' => $summary + ['skipped' => 'no period covers that date']];
        }

        if ($objectId === null) {
            return ['written' => false, 'summary' => $summary + ['skipped' => 'the measure has no object to record against']];
        }

        if (! is_numeric($value)) {
            return ['written' => false, 'summary' => $summary + ['skipped' => 'the value is not a number']];
        }

        if ($dryRun) {
            return ['written' => true, 'summary' => $summary + ['action' => 'would write']];
        }

        DB::transaction(function () use ($connector, $measure, $period, $objectId, $value, $row) {
            MeasureValue::withoutGlobalScopes()->updateOrCreate(
                [
                    // The WP-04 uniqueness. updateOrCreate rather than create
                    // is what makes a connector safe to re-run: reading the
                    // same file twice must not produce two readings for one
                    // period.
                    'measure_id' => $measure->id,
                    'object_id' => $objectId,
                    'period_id' => $period->id,
                    'scenario' => $row['scenario'] ?? 'actual',
                    'currency_code' => $row['currency_code'] ?? null,
                ],
                [
                    'organization_id' => $connector->organization_id,
                    'value' => (float) $value,
                    'status' => 'approved',
                    'source' => 'connector:'.$connector->type,
                    'entered_at' => now(),
                    'note' => 'Collected automatically by "'.$connector->name.'".',
                    'evidence_ref' => $row['evidence_ref'] ?? null,
                ],
            );
        });

        return ['written' => true, 'summary' => $summary + ['action' => 'written']];
    }

    /* ------------------------------------------------------------------ */

    private function resolveMeasure(Connector $connector, array $row): ?Measure
    {
        $code = $row['measure_code'] ?? $row['kri_code'] ?? $connector->config('measure_code');

        if (blank($code)) {
            return null;
        }

        return Measure::withoutGlobalScopes()
            ->where('organization_id', $connector->organization_id)
            ->where('code', $code)
            ->first();
    }

    private function resolvePeriod(Connector $connector, array $row): ?Period
    {
        // An explicit period code wins, and is checked FIRST. A source that
        // names the period it is reporting for — "2026-Q1" — is telling us
        // something more precise than a date, and deriving the period from
        // today's date instead would file a quarterly return against the
        // current month.
        if (! blank($row['period_code'] ?? null)) {
            return Period::withoutGlobalScopes()
                ->where('organization_id', $connector->organization_id)
                ->where('code', $row['period_code'])
                ->first();
        }

        $date = $row['period_date'] ?? $row['date'] ?? $row['measurement_date'] ?? null;

        if (blank($date)) {
            // No date and no code means "now", which is what a live feed means
            // by omitting both.
            return $this->periods->current(
                $connector->config('period_type', 'month'),
                $connector->organization_id,
            );
        }

        try {
            return $this->periods->resolve(
                CarbonImmutable::parse($date),
                $connector->config('period_type', 'month'),
                $connector->organization_id,
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Which object the reading is about.
     *
     * A KRI-backed measure records against the object its indicator hangs off,
     * which is nearly always what a source file means when it gives a code and
     * a number and nothing else.
     */
    private function resolveObjectId(?Measure $measure, array $row): ?int
    {
        if (isset($row['object_id'])) {
            return (int) $row['object_id'];
        }

        if ($measure === null) {
            return null;
        }

        $kri = KeyRiskIndicator::withoutGlobalScopes()
            ->where('organization_id', $measure->organization_id)
            ->where('kri_code', $measure->code)
            ->first();

        return $kri?->node_id ?? $measure->object_id ?? null;
    }

    private function driver(Connector $connector): ConnectorContract
    {
        $class = config('connectors.drivers.'.$connector->type);

        if ($class === null || ! class_exists($class)) {
            throw new RuntimeException("There is no connector driver registered for type [{$connector->type}].");
        }

        return app($class);
    }

    /** @param array<string, string> $map */
    private function assertMapped(array $map): void
    {
        if (! isset($map['value'])) {
            // Without it the run reads every row and writes none, and the run
            // log would say "read 412, wrote 0" with no reason attached.
            throw new RuntimeException(
                'The field mapping has no "value" column, so there is nothing to record. '
                .'Map the source column holding the number.'
            );
        }
    }
}
