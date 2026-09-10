<?php

namespace App\Jobs;

use App\Jobs\Concerns\TracksJobProgress;
use App\Models\DataImport;
use App\Services\Import\DataImportProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * WP-07 TASK 1 — spreadsheet import, off the request cycle.
 *
 * NOT RETRIED. An import is not idempotent: rows already created stay created,
 * so a retry after a partial failure duplicates everything it managed the first
 * time. The failure is recorded on the import row for a person to look at,
 * which is the honest outcome — a silently doubled risk register is not.
 */
class ProcessDataImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TracksJobProgress;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(
        public int $importId,
        public ?int $jobRunId = null,
    ) {
        $this->onQueue('imports');
    }

    public function handle(DataImportProcessor $processor): void
    {
        $import = DataImport::withoutGlobalScopes()->find($this->importId);

        if ($import === null) {
            $this->failRun('The import no longer exists.');

            return;
        }

        $this->startRun();

        $this->runTenanted($import->organization_id, function () use ($processor, $import) {
            $result = $processor->process(
                $import,
                onProgress: fn (int $done, int $total) => $this->reportProgress(
                    $done,
                    $total,
                    "Imported {$done} of {$total} rows.",
                ),
                shouldCancel: fn () => $this->shouldCancel(),
            );

            if ($result['cancelled']) {
                $this->cancelRun(
                    "Cancelled after {$result['success']} rows. Those rows were kept; re-import the rest."
                );

                return;
            }

            $this->completeRun($result, sprintf(
                '%d imported, %d failed.',
                $result['success'],
                $result['errors'],
            ));
        });
    }

    public function failed(Throwable $e): void
    {
        $this->failRun($e);

        DataImport::withoutGlobalScopes()
            ->whereKey($this->importId)
            ->update([
                'status' => 'failed',
                'errors' => [$e->getMessage()],
                'completed_at' => now(),
            ]);
    }
}
