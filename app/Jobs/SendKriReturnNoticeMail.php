<?php

namespace App\Jobs;

use App\Enums\KriReturnStatus;
use App\Mail\KriReturnReminder;
use App\Mail\KriReturnRequested;
use App\Models\KriReturn;
use App\Models\KriReturnNotice;
use App\Models\KriReturnNoticeRecipient;
use App\Services\Kri\KriNoticeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;
use ThirdLine\Platform\Tenancy\TenantContext;
use Throwable;

/**
 * Step 3 of the notice flow (ADR 0026 section 5): one mailable to one
 * recipient, sent with Mail::to()->send() INSIDE the job, so the job is the
 * retry unit. Records sent_at and message_id, or attempts and error and, on the
 * final failure, failed_at.
 *
 * `sent` means "accepted by the mail transport". Bounces are not tracked.
 */
class SendKriReturnNoticeMail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public int $recipientId)
    {
        $this->onQueue('notifications');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(KriNoticeService $notices): void
    {
        $organizationId = KriReturnNoticeRecipient::bypassTenancy('kri notice mail job resolves its own tenant')
            ->whereKey($this->recipientId)
            ->value('organization_id');

        if ($organizationId === null) {
            return;
        }

        TenantContext::actingAs((int) $organizationId, function () use ($notices) {
            $recipient = KriReturnNoticeRecipient::query()->with('notice')->find($this->recipientId);

            // A retry after success, or a skipped row: nothing to do.
            if ($recipient === null || $recipient->status !== KriReturnNoticeRecipient::STATUS_PENDING) {
                return;
            }

            $notice = $recipient->notice;

            // Re-check at send time (A1.13): never chase a return already submitted.
            $status = KriReturn::query()->whereKey($notice->kri_return_id)->value('status');

            if ($status === KriReturnStatus::Submitted->value) {
                $recipient->forceFill([
                    'status' => KriReturnNoticeRecipient::STATUS_SKIPPED,
                    'skip_reason' => KriReturnNoticeRecipient::SKIP_RETURN_SUBMITTED,
                ])->save();
                $notices->rollUp((int) $recipient->kri_return_notice_id);

                return;
            }

            $mailable = $notice->kind === KriReturnNotice::KIND_REQUEST
                ? new KriReturnRequested($recipient)
                : new KriReturnReminder($recipient);

            $recipient->forceFill(['attempts' => min(255, $recipient->attempts + 1)])->save();

            try {
                $sent = Mail::to($recipient->email)->send($mailable);
            } catch (Throwable $e) {
                $recipient->forceFill(['error' => mb_substr($e->getMessage(), 0, 500)])->save();

                throw $e;
            }

            $recipient->forceFill([
                'status' => KriReturnNoticeRecipient::STATUS_SENT,
                'message_id' => $sent?->getMessageId() === null ? null : mb_substr((string) $sent->getMessageId(), 0, 255),
                'sent_at' => now(),
                'error' => null,
            ])->save();

            $notices->rollUp((int) $recipient->kri_return_notice_id);
        });
    }

    /** Called by the queue after the final attempt. */
    public function failed(Throwable $exception): void
    {
        $organizationId = KriReturnNoticeRecipient::bypassTenancy('kri notice mail job failure handler')
            ->whereKey($this->recipientId)
            ->value('organization_id');

        if ($organizationId === null) {
            return;
        }

        TenantContext::actingAs((int) $organizationId, function () use ($exception) {
            $recipient = KriReturnNoticeRecipient::query()->find($this->recipientId);

            if ($recipient === null || $recipient->status !== KriReturnNoticeRecipient::STATUS_PENDING) {
                return;
            }

            $recipient->forceFill([
                'status' => KriReturnNoticeRecipient::STATUS_FAILED,
                'error' => mb_substr($exception->getMessage(), 0, 500),
                'failed_at' => now(),
            ])->save();

            app(KriNoticeService::class)->rollUp((int) $recipient->kri_return_notice_id);
        });
    }
}
