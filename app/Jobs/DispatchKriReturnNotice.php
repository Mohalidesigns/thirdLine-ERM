<?php

namespace App\Jobs;

use App\Models\KriReturnNotice;
use App\Services\Kri\KriNoticeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use ThirdLine\Platform\Tenancy\TenantContext;
use Throwable;

/**
 * Step 2 of the notice flow (ADR 0026 section 5): lock the notice, resolve who
 * receives it NOW, record them, and hand each deliverable recipient to its own
 * send job.
 *
 * Idempotent: recipients are resolved only while the notice is still
 * `queued` (under a row lock), so a duplicate delivery of this job, or
 * `kri:cycle-watchdog --redispatch`, resolves nobody twice.
 *
 * RETRY-SAFE, which "idempotent" alone was not. The sends it queues are read
 * back from the rows - every `pending` recipient of a `dispatching` notice - not
 * taken from the resolving call's return value. A first attempt that resolved
 * and committed, then died or met a dead Redis before queueing the sends, is
 * finished by its own retry instead of leaving every recipient `pending` for
 * ever. A send job queued twice is harmless: SendKriReturnNoticeMail claims its
 * row under a lock.
 *
 * Queue `notifications` (Horizon `interactive` pool). 3 tries, 30s then 120s:
 * the failure being ridden out is a database or Redis blip, not a mail server.
 * A notice whose job exhausts its tries stays `queued` (or `dispatching`), and
 * kri:cycle-watchdog reports it after 60 minutes.
 *
 * FLAT IN app/Jobs: the ERM module follows RCSA and TPRM, not BCMS.
 */
class DispatchKriReturnNotice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** Resolution is a handful of inserts; anything near this is a stuck lock. */
    public int $timeout = 60;

    public function __construct(public int $noticeId)
    {
        $this->onQueue('notifications');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(KriNoticeService $notices): void
    {
        // The job is handed an id only, so it resolves its own tenant. Logged
        // as a bypass because that is exactly what it is.
        $organizationId = KriReturnNotice::bypassTenancy('kri notice job resolves its own tenant')
            ->whereKey($this->noticeId)
            ->value('organization_id');

        if ($organizationId === null) {
            return;
        }

        TenantContext::actingAs((int) $organizationId, function () use ($notices) {
            $notices->resolveRecipients($this->noticeId);

            // From the rows, not from resolveRecipients()'s return (see above).
            $notices->dispatchSends($notices->pendingRecipientIds($this->noticeId));
        });
    }

    /** The notice stays where it is; the watchdog reports it. Say so loudly now. */
    public function failed(Throwable $exception): void
    {
        Log::error('KRI notice dispatch job exhausted its retries; the notice is left for kri:cycle-watchdog', [
            'kri_return_notice_id' => $this->noticeId,
            'error' => $exception->getMessage(),
        ]);
    }
}
