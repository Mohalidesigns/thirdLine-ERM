<?php

namespace App\Jobs;

use App\Jobs\Concerns\TracksJobProgress;
use App\Models\Rcsa\RcsaImportBatch;
use App\Services\Rcsa\RcsaImportProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Parse and stage an RCSA template upload, off the request cycle (§7.2).
 *
 * SAFE TO RETRY, unlike ProcessDataImportJob beside it — and the difference is
 * the whole point of the staging table. This job writes nothing to the universe;
 * it truncates the batch's staged rows and rebuilds them, so running it twice
 * leaves exactly what running it once leaves. `$tries` is still 1, because a
 * file that failed to parse will fail the same way a second time and the user
 * is better served by the error than by three silent attempts.
 */
class ProcessRcsaImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TracksJobProgress;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(
        public int $batchId,
        public ?int $jobRunId = null,
    ) {
        $this->onQueue('imports');
    }

    public function handle(RcsaImportProcessor $processor): void
    {
        $batch = RcsaImportBatch::withoutGlobalScopes()->find($this->batchId);

        if ($batch === null) {
            $this->failRun('The import batch no longer exists.');

            return;
        }

        $this->startRun();

        $this->runTenanted($batch->organization_id, function () use ($processor, $batch) {
            try {
                $counts = $processor->process(
                    $batch,
                    onProgress: fn (int $done, int $total) => $this->reportProgress(
                        $done,
                        $total,
                        "Checked {$done} of {$total} rows.",
                    ),
                );
            } catch (Throwable $e) {
                // The reason belongs ON THE BATCH, not only in the job log: the
                // preview screen is where the user is waiting, and "failed"
                // with no explanation is indistinguishable from a hung queue.
                $batch->update([
                    'status' => RcsaImportBatch::FAILED,
                    'failure_reason' => $e->getMessage(),
                ]);

                throw $e;
            }

            $this->completeRun($counts, sprintf(
                '%d rows checked: %d ready, %d with warnings, %d duplicates, %d with errors.',
                $counts['total'],
                $counts['valid'],
                $counts['warning'],
                $counts['duplicate'],
                $counts['error'],
            ));
        });
    }

    public function failed(Throwable $e): void
    {
        $this->failRun($e);
    }
}
