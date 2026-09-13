<?php

namespace App\Jobs;

use App\Jobs\Concerns\TracksJobProgress;
use App\Models\Tprm\Document;
use App\Services\Tprm\Extraction\ExtractionDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Extraction, off the request cycle — ADR 0015 §6a, phase-11a-ai-contract.md
 * §2.4.
 *
 * FLAT IN `app/Jobs/`. TPRM has no `app/Jobs/Tprm` — BCMS does; follow the
 * module you are in.
 *
 * WHY THIS JOB HAS TO EXIST FOR §6 TO BE DELIVERABLE AT ALL. Retry with
 * backoff plus a circuit breaker is not implementable on a synchronous HTTP
 * request: 1s + 120s + 3s + 120s inside one request is a guaranteed proxy
 * timeout presented to the user as a broken page, and a breaker whose purpose
 * is to stop people waiting on a dead box cannot do its job while the person
 * is already sixty-nine seconds into waiting for the FIRST attempt.
 * `DocumentController::extract()` now only dispatches; the wait happens here,
 * where nobody is looking at a spinner.
 *
 * `$tries = 1`. The gateway owns transport retry (`config('llm.retry')`) and
 * `ExtractionDispatcher` owns schema retry (`MAX_ATTEMPTS = 2`); a third retry
 * layer at the queue would multiply both, and ADR 0015 §6 is explicit that
 * they must not.
 *
 * `$timeout` MUST EXCEED `services.llm.budgets.extraction.timeout` x the
 * gateway's own transport attempts, plus backoff, or the worker kills the
 * HTTP call mid-flight and the usage row records a timeout that was ours, not
 * the model's. Two extraction attempts (schema retry) x up to two transport
 * attempts each x 120s, plus the two backoff waits (1s, 3s), comfortably
 * inside 900s with room for the citation check and the write.
 */
class RunTprmDocumentExtraction implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TracksJobProgress;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        public int $documentId,
        public ?int $jobRunId = null,
    ) {
        $this->onQueue('tprm-extraction');
    }

    public function handle(ExtractionDispatcher $dispatcher): void
    {
        $document = Document::withoutGlobalScopes()->find($this->documentId);

        if ($document === null) {
            $this->failRun('The document no longer exists.');

            return;
        }

        $this->startRun();

        // Gate 2 blocking defect 3: the actor who triggered this run, read
        // off the SAME `JobRun` the progress bar is watching — never
        // `auth()->id()` here, because a queue worker has no session. A
        // missing `created_by` (a path this class cannot presently reach)
        // stays null rather than being guessed at, which is exactly the
        // "not recorded" the usage grid must say for it.
        $userId = $this->jobRun()?->created_by;

        $this->runTenanted($document->organization_id, function () use ($dispatcher, $document, $userId) {
            try {
                $outcome = $dispatcher->dispatch($document, $userId);
            } catch (Throwable $e) {
                $this->failRun($e);

                throw $e;
            }

            if ($outcome->succeeded()) {
                $this->completeRun($outcome->toArray(), 'The document was read. Check each field against the quoted text before confirming.');

                return;
            }

            // Not a job FAILURE — AI off, an unreadable PDF, or a model that
            // could not produce a usable answer are the module working as
            // configured (AC-16). The manual form is still there; the job
            // itself did its job.
            $this->completeRun($outcome->toArray(), (string) $outcome->message);
        });
    }

    public function failed(Throwable $e): void
    {
        $this->failRun($e);
    }
}
