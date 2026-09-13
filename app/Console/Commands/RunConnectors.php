<?php

namespace App\Console\Commands;

use App\Jobs\RunConnectorJob;
use App\Models\Connector;
use Illuminate\Console\Command;

/**
 * Sweep the connectors due on a cadence and queue each one.
 *
 * Queues rather than runs: a sweep that executed twelve connectors inline would
 * be a scheduler tick that takes twenty minutes and blocks everything behind
 * it, and one slow source would delay all the others.
 */
class RunConnectors extends Command
{
    protected $signature = 'connectors:run
                            {--schedule=daily : Only connectors set to this cadence}
                            {--connector= : One connector id, ignoring its schedule}
                            {--dry-run : Produce a reconciliation without writing}';

    protected $description = 'Run the connectors due on a schedule';

    public function handle(): int
    {
        $connectors = Connector::withoutGlobalScopes()
            ->when(
                $this->option('connector'),
                fn ($q) => $q->whereKey($this->option('connector')),
                fn ($q) => $q->active()->where('schedule', (string) $this->option('schedule')),
            )
            ->orderBy('organization_id')
            ->get();

        if ($connectors->isEmpty()) {
            $this->info('Nothing due.');

            return self::SUCCESS;
        }

        foreach ($connectors as $connector) {
            // A connector that has never run does its first pass as a dry run,
            // so the field mapping is proved against real rows before anything
            // is written. Getting that wrong writes hundreds of numbers into
            // the wrong measures, and nothing downstream can tell.
            $dryRun = (bool) $this->option('dry-run')
                || ($connector->last_run_at === null && config('connectors.dry_run_first', true));

            $jobRun = RunConnectorJob::track(
                label: 'Connector: '.$connector->name.($dryRun ? ' (dry run)' : ''),
                subject: $connector,
                organizationId: $connector->organization_id,
            );

            RunConnectorJob::dispatch($connector->id, $dryRun, 'schedule', null, $jobRun->id);

            $this->line(sprintf(
                '  queued %s (#%d)%s',
                $connector->name,
                $connector->id,
                $dryRun ? ' — dry run, nothing will be written' : '',
            ));
        }

        $this->info('Queued '.$connectors->count().' connector run(s).');

        return self::SUCCESS;
    }
}
