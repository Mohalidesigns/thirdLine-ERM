<?php

namespace App\Jobs;

use App\Jobs\Concerns\TracksJobProgress;
use App\Models\Connector;
use App\Models\User;
use App\Services\Integrations\ConnectorRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * WP-07 TASK 4 — a connector sync, off the request cycle.
 *
 * NOT RETRIED. The write is idempotent (updateOrCreate on the WP-04 uniqueness),
 * so a retry would be safe — but a source that failed is usually a source that
 * is still failing, and three automatic attempts turn one clear entry in the run
 * log into three confusing ones. The failure is recorded on the connector for a
 * person to look at, and the next schedule tick tries again anyway.
 */
class RunConnectorJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TracksJobProgress;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(
        public int $connectorId,
        public bool $dryRun = false,
        public string $trigger = 'schedule',
        public ?int $actorId = null,
        public ?int $jobRunId = null,
    ) {
        $this->onQueue('connectors');
    }

    public function handle(ConnectorRunner $runner): void
    {
        $connector = Connector::withoutGlobalScopes()->find($this->connectorId);

        if ($connector === null) {
            $this->failRun('The connector no longer exists.');

            return;
        }

        $this->startRun();

        $run = $runner->run(
            $connector,
            $this->dryRun,
            $this->trigger,
            $this->actorId ? User::withoutGlobalScopes()->find($this->actorId) : null,
            $this->jobRunId,
        );

        if ($run->status === 'failed') {
            $this->failRun((string) collect($run->errors)->first());

            return;
        }

        $this->completeRun(
            [
                'connector_run_id' => $run->id,
                'records_read' => $run->records_read,
                'records_written' => $run->records_written,
                'records_skipped' => $run->records_skipped,
            ],
            $run->summary(),
        );
    }

    public function failed(Throwable $e): void
    {
        $this->failRun($e);

        Connector::withoutGlobalScopes()->find($this->connectorId)?->recordRun('failed', $e->getMessage());
    }
}
