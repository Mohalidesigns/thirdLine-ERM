<?php

namespace App\Jobs;

use App\Mail\KriReturnReminder;
use App\Mail\KriReturnRequested;
use App\Models\KriReturnNotice;
use App\Models\KriReturnNoticeRecipient;
use App\Services\Kri\KriNoticeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
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
 *
 * AT MOST ONE SEND PER RECIPIENT ROW, UNDER CONCURRENCY. The row is claimed
 * with SELECT ... FOR UPDATE and held for the send. Two copies of this job (a
 * re-dispatch, or a queue redelivery) serialise on that lock; the second finds
 * the row no longer `pending` and does nothing. A plain status check without
 * the lock let both copies read `pending` and both send. The lock is one row,
 * contended only by a duplicate of this same job, and $timeout bounds how long
 * it is held: a worker killed mid-send drops its connection, InnoDB rolls the
 * claim back, and the row is `pending` again for the retry.
 *
 * THE ONE WINDOW LEFT is between the transport accepting the message and the
 * commit that records it: a crash exactly there re-sends on retry. No SMTP
 * transport offers an idempotency key, so that window is the floor; it is the
 * at-least-once side of "accepted by the transport", and is stated here rather
 * than hidden.
 *
 * A TRANSPORT FAILURE NEVER MARKS A ROW SENT. The exception is caught inside the
 * claim so that `attempts` and `error` COMMIT, then rethrown outside it so the
 * queue retries (60s, then 300s). After the third failure failed() records
 * `failed` and `failed_at` and rolls the notice up.
 *
 * A1.13 RE-CHECK at send time, under the claim: a return submitted after the
 * notice was queued means this recipient AND every other still-pending one on
 * the notice becomes `skipped` / `return_submitted`, and the notice rolls up to
 * `cancelled` when nothing had gone out.
 */
class SendKriReturnNoticeMail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /**
     * Bounds how long the recipient row stays locked across an SMTP call. Well
     * inside the `interactive` supervisor's 120s and the redis connection's
     * retry_after, so a hung transport is killed by the worker, not redelivered
     * alongside itself.
     */
    public int $timeout = 60;

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
            /** @var array{outcome: string, notice_id?: int, error?: Throwable} $result */
            $result = DB::transaction(function () use ($notices) {
                $recipient = KriReturnNoticeRecipient::query()->whereKey($this->recipientId)->lockForUpdate()->first();

                // A retry after success, a duplicate job, or a skipped row.
                if ($recipient === null || $recipient->status !== KriReturnNoticeRecipient::STATUS_PENDING) {
                    return ['outcome' => 'noop'];
                }

                $notice = KriReturnNotice::query()->find($recipient->kri_return_notice_id);

                if ($notice === null) {
                    return ['outcome' => 'noop'];
                }

                $recipient->setRelation('notice', $notice);
                $noticeId = (int) $notice->id;

                // Re-check at send time (A1.13): never chase a return already submitted.
                if ($notices->returnIsSubmitted((int) $notice->kri_return_id)) {
                    $recipient->forceFill([
                        'status' => KriReturnNoticeRecipient::STATUS_SKIPPED,
                        'skip_reason' => KriReturnNoticeRecipient::SKIP_RETURN_SUBMITTED,
                    ])->save();

                    return ['outcome' => 'submitted', 'notice_id' => $noticeId];
                }

                $mailable = $notice->kind === KriReturnNotice::KIND_REQUEST
                    ? new KriReturnRequested($recipient)
                    : new KriReturnReminder($recipient);

                $recipient->forceFill(['attempts' => min(255, (int) $recipient->attempts + 1)])->save();

                try {
                    $sent = Mail::to($recipient->email)->send($mailable);
                } catch (Throwable $e) {
                    // Commit the attempt and the error; the row stays `pending`.
                    $recipient->forceFill(['error' => mb_substr($e->getMessage(), 0, 500)])->save();

                    return ['outcome' => 'error', 'notice_id' => $noticeId, 'error' => $e];
                }

                $messageId = $sent?->getMessageId();

                $recipient->forceFill([
                    'status' => KriReturnNoticeRecipient::STATUS_SENT,
                    'message_id' => $messageId === null ? null : mb_substr((string) $messageId, 0, 255),
                    'sent_at' => now(),
                    'error' => null,
                    'failed_at' => null,
                ])->save();

                return ['outcome' => 'sent', 'notice_id' => $noticeId];
            });

            match ($result['outcome']) {
                // Outside the claim, holding no lock, so two jobs that both see
                // the submission cannot deadlock on each other's rows.
                'submitted' => $notices->cancelPendingForSubmission($result['notice_id']),
                'sent' => $notices->rollUp($result['notice_id']),
                'error' => throw $result['error'],
                default => null,
            };
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
            // Conditional: never turns a row another copy has just sent into `failed`.
            $updated = KriReturnNoticeRecipient::query()
                ->whereKey($this->recipientId)
                ->where('status', KriReturnNoticeRecipient::STATUS_PENDING)
                ->update([
                    'status' => KriReturnNoticeRecipient::STATUS_FAILED,
                    'error' => mb_substr($exception->getMessage(), 0, 500),
                    'failed_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($updated === 0) {
                return;
            }

            $noticeId = KriReturnNoticeRecipient::query()->whereKey($this->recipientId)->value('kri_return_notice_id');

            if ($noticeId !== null) {
                app(KriNoticeService::class)->rollUp((int) $noticeId);
            }
        });
    }
}
