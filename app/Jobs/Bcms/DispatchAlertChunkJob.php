<?php

namespace App\Jobs\Bcms;

use App\Enums\Bcms\RecipientStatus;
use App\Models\Bcms\Alert;
use App\Models\Bcms\AlertRecipient;
use App\Services\Bcms\Emns\AlertDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * One chunk of an alert's recipients, sent on the queue their severity earns.
 *
 * CHUNKS, NOT ONE JOB PER PERSON. Ten thousand jobs is ten thousand round trips
 * to Redis and ten thousand model hydrations before a single message moves;
 * criterion 2 gives thirty seconds to queue the lot. Two hundred per chunk
 * keeps a failed chunk small enough to retry cheaply while making the enqueue a
 * few dozen writes rather than ten thousand.
 *
 * THE QUEUE COMES FROM THE SEVERITY AND NOTHING ELSE. `AlertSeverity::queue()`
 * puts life-safety traffic on its own pool with its own Horizon supervisor —
 * standing rule 6, and criterion 12: the life-safety queue drains while
 * `bcms-sync` is backed up with ten thousand jobs, because they are different
 * workers and not different priorities on one.
 *
 * TENANCY IS SET EXPLICITLY. A queue worker has no session and
 * `OrganizationScope` is inert without a resolved tenant — the same trap Phase 4
 * hit on the ICS feed and Phase 6 hit on cascade acknowledgement. Without this
 * line a worker would read every organisation's recipients.
 *
 * IT IS IDEMPOTENT ON RECIPIENT STATUS. A retried chunk re-selects only rows
 * still `queued`, so a worker that died halfway does not send the first half
 * twice. Sending an evacuation notice twice is survivable; sending it twice to
 * half a branch and not at all to the rest is not.
 */
class DispatchAlertChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * Long, and deliberately so. Blueprint §7.2 point 5: a recipient's phone
     * may be off for hours during a power failure, and a gateway that is down
     * at 02:00 is often up at 02:20. Writing the attempt off after ninety
     * seconds is what a system designed somewhere with reliable power does.
     */
    public int $backoff = 60;

    public int $timeout = 300;

    /**
     * @param  list<int>  $recipientIds
     */
    public function __construct(
        public int $alertId,
        public int $organizationId,
        public array $recipientIds,
    ) {}

    public function handle(AlertDispatcher $dispatcher): void
    {
        TenantContext::set($this->organizationId);

        try {
            $alert = Alert::query()->find($this->alertId);

            if ($alert === null) {
                return;
            }

            $recipients = AlertRecipient::query()
                ->whereIn('id', $this->recipientIds)
                ->where('status', RecipientStatus::Queued->value)
                ->with('contact')
                ->get();

            if ($recipients->isEmpty()) {
                return;
            }

            $dispatcher->dispatchBatch($alert, $recipients);
        } finally {
            TenantContext::clear();
        }
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['bcms', 'alert:'.$this->alertId, 'recipients:'.count($this->recipientIds)];
    }
}
