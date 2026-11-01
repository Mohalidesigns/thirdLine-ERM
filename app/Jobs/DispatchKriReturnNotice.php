<?php

namespace App\Jobs;

use App\Models\KriReturnNotice;
use App\Services\Kri\KriNoticeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Step 2 of the notice flow (ADR 0026 section 5): lock the notice, resolve who
 * receives it NOW, record them, and hand each deliverable recipient to its own
 * send job.
 *
 * Idempotent: it returns unless the notice is still `queued`, so a duplicate
 * delivery of this job, or the watchdog's re-dispatch of a stuck notice, is
 * harmless. The queue is `notifications` unless reliability-engineer places it
 * elsewhere; the retry count and backoff are theirs to tune.
 *
 * FLAT IN app/Jobs: the ERM module follows RCSA and TPRM, not BCMS.
 */
class DispatchKriReturnNotice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

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
            $pending = $notices->resolveRecipients($this->noticeId);

            $notices->dispatchSends($pending);
        });
    }
}
