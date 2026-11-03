<?php

namespace App\Services\Kri;

use App\Enums\KriReturnStatus;
use App\Jobs\DispatchKriReturnNotice;
use App\Jobs\SendKriReturnNoticeMail;
use App\Models\KriReturn;
use App\Models\KriReturnNotice;
use App\Models\KriReturnNoticeRecipient;
use App\Models\User;
use App\Support\Kri\KriContactLadder;
use App\Support\Kri\KriDepartmentScope;
use App\Support\Kri\KriWorkingCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The monthly request and the manual reminders, as rows (ADR 0026 section 5).
 *
 * PERSIST BEFORE YOU DISPATCH. A notice is inserted `queued` with no recipients,
 * and only then handed to the queue after commit. A worker crash never loses it:
 * kri:cycle-watchdog finds a notice that stayed `queued`. Recipients are
 * resolved when the mail is sent, not when it is requested, so a contact
 * changed in between is honoured.
 *
 * THE IDEMPOTENCY KEYS ARE THE "AT MOST ONCE" RULES:
 *   request:r{kri_return_id}                one request per return, ever;
 *   reminder:bu{unit}:{Y-m-d Lagos}         one manual reminder per department per day.
 * A duplicate insert is a unique-key violation, reported as
 * `already_reminded_today` for that department and never a 500 for the batch.
 * ONLY a unique-key violation (UniqueConstraintViolationException, MySQL and
 * MariaDB error 1062) means "somebody else won". A lost connection, a deadlock
 * or a lock-wait timeout is a real failure and is not reported as a duplicate.
 *
 * A DISPATCH THAT FAILS IS NOT A LOST NOTICE. The row is already committed as
 * `queued`; a dead Redis at the moment of dispatch leaves it there, logged, for
 * kri:cycle-watchdog to report and `kri:cycle-watchdog --redispatch` (or a
 * forced re-run of kri:open-cycle, for a request) to hand back to the queue.
 * Re-dispatching is safe because both jobs are idempotent on row state.
 */
class KriNoticeService
{
    public function __construct(private readonly KriDepartmentScope $scope) {}

    /**
     * Insert the one request notice for a return, if it has none. The caller
     * dispatches DispatchKriReturnNotice after commit with the returned id.
     *
     * @return int|null the new notice id; null when the return already has its request
     */
    public function queueRequest(KriReturn $return, CarbonImmutable $day): ?int
    {
        $key = KriReturnNotice::requestKey((int) $return->id);

        $exists = KriReturnNotice::query()
            ->where('organization_id', $return->organization_id)
            ->where('idempotency_key', $key)
            ->exists();

        if ($exists) {
            return null;
        }

        try {
            $notice = KriReturnNotice::query()->create([
                'organization_id' => $return->organization_id,
                'kri_return_id' => $return->id,
                'business_unit_id' => $return->business_unit_id,
                'kind' => KriReturnNotice::KIND_REQUEST,
                'sequence' => 0,
                'tier' => 1,
                'idempotency_key' => $key,
                'sent_on' => $day->toDateString(),
                'status' => KriReturnNotice::STATUS_QUEUED,
                'requested_by' => null,
                'queued_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another server queued it between the check and the insert.
            return null;
        }

        return (int) $notice->id;
    }

    /**
     * Hand a persisted notice to the queue. Never throws: the row is the
     * record, and a notice that could not be queued stays `queued` where the
     * watchdog finds it.
     */
    public function dispatchNotice(int $noticeId): bool
    {
        try {
            DispatchKriReturnNotice::dispatch($noticeId)->afterCommit();

            return true;
        } catch (Throwable $e) {
            Log::error('KRI notice persisted but could not be queued; kri:cycle-watchdog will report it', [
                'kri_return_notice_id' => $noticeId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Remind the contacts of each return's department, one tier further up for
     * every reminder already sent on that return.
     *
     * @param  list<int>  $returnIds
     * @return list<array{return_id: int, department: string|null, outcome: string, tier: int|null}>
     */
    public function remind(array $returnIds, User $actor): array
    {
        $results = [];
        $today = KriWorkingCalendar::today()->toDateString();

        $ids = array_values(array_unique(array_map('intval', $returnIds)));
        // One query for the batch (up to 50 ids), not one per id. The locked
        // re-read further down is still per return, by design.
        $loaded = KriReturn::query()->with('businessUnit')->whereIn('id', $ids)->get()->keyBy('id');

        foreach ($ids as $returnId) {
            $return = $loaded->get($returnId);

            if ($return === null || ! $this->scope->reaches($actor, (int) $return->business_unit_id)) {
                $results[] = ['return_id' => $returnId, 'department' => $return?->businessUnit?->name, 'outcome' => 'out_of_scope', 'tier' => null];

                continue;
            }

            $department = $return->businessUnit?->name;

            if ($return->isSubmitted()) {
                $results[] = ['return_id' => $returnId, 'department' => $department, 'outcome' => 'submitted', 'tier' => null];

                continue;
            }

            $noticeId = null;
            $tier = null;
            $outcome = 'queued';

            try {
                DB::transaction(function () use ($return, $actor, $today, &$noticeId, &$tier, &$outcome) {
                    // Serialise reminders on this return; the unique keys are the backstop.
                    $locked = KriReturn::query()->whereKey($return->id)->lockForUpdate()->first();

                    if ($locked === null || $locked->isSubmitted()) {
                        $outcome = 'submitted';

                        return;
                    }

                    $key = KriReturnNotice::reminderKey((int) $return->business_unit_id, $today);

                    if (KriReturnNotice::query()->where('organization_id', $return->organization_id)->where('idempotency_key', $key)->exists()) {
                        $outcome = 'already_reminded_today';

                        return;
                    }

                    $sequence = (int) KriReturnNotice::query()
                        ->where('kri_return_id', $return->id)
                        ->where('kind', KriReturnNotice::KIND_REMINDER)
                        ->max('sequence') + 1;

                    $tier = KriContactLadder::tierForSequence($sequence);

                    $notice = KriReturnNotice::query()->create([
                        'organization_id' => $return->organization_id,
                        'kri_return_id' => $return->id,
                        'business_unit_id' => $return->business_unit_id,
                        'kind' => KriReturnNotice::KIND_REMINDER,
                        'sequence' => $sequence,
                        'tier' => $tier,
                        'idempotency_key' => $key,
                        'sent_on' => $today,
                        'status' => KriReturnNotice::STATUS_QUEUED,
                        'requested_by' => $actor->id,
                        'queued_at' => now(),
                    ]);

                    $noticeId = (int) $notice->id;
                });
            } catch (UniqueConstraintViolationException) {
                // Lost a race on either unique key: another reminder won today.
                // Two clicks on the same return are serialised by the row lock
                // above and never get here; two returns of one department
                // (different periods) on one day race only on the day key.
                $outcome = 'already_reminded_today';
                $noticeId = null;
            }

            if ($noticeId !== null) {
                // Persisted and committed: from here a queue failure is the
                // watchdog's to report, not this click's to 500 on.
                $this->dispatchNotice($noticeId);
            }

            $results[] = [
                'return_id' => $returnId,
                'department' => $department,
                'outcome' => $outcome,
                'tier' => $outcome === 'queued' ? $tier : null,
            ];
        }

        return $results;
    }

    /**
     * Resolve who receives a queued notice NOW and record them. Idempotent: it
     * returns nothing unless the notice is still `queued`.
     *
     * @return list<int> the recipient rows left `pending`
     */
    public function resolveRecipients(int $noticeId): array
    {
        $pending = [];

        DB::transaction(function () use ($noticeId, &$pending) {
            $notice = KriReturnNotice::query()->whereKey($noticeId)->lockForUpdate()->first();

            if ($notice === null || $notice->status !== KriReturnNotice::STATUS_QUEUED) {
                return;
            }

            // Re-check at send time (A1.13): a return submitted while the notice
            // waited in the queue needs no chasing.
            if ($this->returnIsSubmitted((int) $notice->kri_return_id)) {
                $notice->status = KriReturnNotice::STATUS_CANCELLED;
                $notice->recipients_resolved_at = now();
                $notice->completed_at = now();
                $notice->save();

                return;
            }

            foreach (KriContactLadder::recipientsFor((int) $notice->business_unit_id, (int) $notice->tier) as $row) {
                /** @var User $user */
                $user = $row['user'];
                $email = trim((string) $user->email);

                $skip = null;

                if (! $user->is_active) {
                    $skip = KriReturnNoticeRecipient::SKIP_INACTIVE;
                } elseif ($email === '') {
                    $skip = KriReturnNoticeRecipient::SKIP_NO_EMAIL;
                }

                $recipient = KriReturnNoticeRecipient::query()->create([
                    'organization_id' => $notice->organization_id,
                    'kri_return_notice_id' => $notice->id,
                    'user_id' => $user->id,
                    'email' => $email,
                    'name' => mb_substr((string) $user->name, 0, 200),
                    'contact_role' => $row['role']->value,
                    'status' => $skip === null ? KriReturnNoticeRecipient::STATUS_PENDING : KriReturnNoticeRecipient::STATUS_SKIPPED,
                    'skip_reason' => $skip,
                ]);

                if ($skip === null) {
                    $pending[] = (int) $recipient->id;
                }
            }

            $notice->recipients_resolved_at = now();

            if ($pending === []) {
                $notice->status = KriReturnNotice::STATUS_NO_RECIPIENTS;
                $notice->completed_at = now();
            } else {
                $notice->status = KriReturnNotice::STATUS_DISPATCHING;
            }

            $notice->save();
        });

        return $pending;
    }

    /**
     * Hand each pending recipient to its own send job (the job is the retry
     * unit). A duplicate send job is harmless: SendKriReturnNoticeMail claims
     * its row under a lock and does nothing unless it is still `pending`.
     *
     * @param  list<int>  $recipientIds
     */
    public function dispatchSends(array $recipientIds): void
    {
        foreach ($recipientIds as $id) {
            SendKriReturnNoticeMail::dispatch((int) $id);
        }
    }

    /**
     * Every recipient of a `dispatching` notice still waiting to be sent.
     *
     * This, not the return value of resolveRecipients(), is what the dispatch
     * job hands to the queue. resolveRecipients() returns ids only on the run
     * that resolved them, so a dispatch job that resolved, committed and then
     * died (or met a dead Redis) before queueing the sends would, on retry,
     * find the notice no longer `queued` and queue nothing: every recipient
     * stranded on `pending` for ever, with the notice stuck on `dispatching`.
     *
     * @return list<int>
     */
    public function pendingRecipientIds(int $noticeId): array
    {
        $status = KriReturnNotice::query()->whereKey($noticeId)->value('status');

        if ($status !== KriReturnNotice::STATUS_DISPATCHING) {
            return [];
        }

        return KriReturnNoticeRecipient::query()
            ->where('kri_return_notice_id', $noticeId)
            ->where('status', KriReturnNoticeRecipient::STATUS_PENDING)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * A1.13 at send time: the return was submitted, so nobody still pending on
     * this notice is chased. One UPDATE on the recipients table (no subquery on
     * itself, so no error 1093); a row another worker is mid-send on is waited
     * for by InnoDB and, once it commits `sent`, no longer matches. Then the
     * notice is rolled up, which yields `cancelled` when nothing went out.
     */
    public function cancelPendingForSubmission(int $noticeId): void
    {
        KriReturnNoticeRecipient::query()
            ->where('kri_return_notice_id', $noticeId)
            ->where('status', KriReturnNoticeRecipient::STATUS_PENDING)
            ->update([
                'status' => KriReturnNoticeRecipient::STATUS_SKIPPED,
                'skip_reason' => KriReturnNoticeRecipient::SKIP_RETURN_SUBMITTED,
                'updated_at' => now(),
            ]);

        $this->rollUp($noticeId);
    }

    /**
     * The A1.13 re-check. Through the model, not `->value('status')` compared
     * with a string: Eloquent's value() applies the `status` cast and returns a
     * KriReturnStatus, so `value('status') === 'submitted'` was ALWAYS false and
     * the re-check could never fire - a return submitted at 09:00 was still
     * chased by the reminder queued at 08:59.
     */
    public function returnIsSubmitted(int $returnId): bool
    {
        return KriReturn::query()->whereKey($returnId)->first(['id', 'status'])?->isSubmitted() === true;
    }

    private function skippedForSubmission(int $noticeId): bool
    {
        return KriReturnNoticeRecipient::query()
            ->where('kri_return_notice_id', $noticeId)
            ->where('skip_reason', KriReturnNoticeRecipient::SKIP_RETURN_SUBMITTED)
            ->exists();
    }

    /**
     * The notice's rolled-up status: counts READ FIRST, then written (no
     * same-table subquery). all sent -> sent; some failed -> partially_failed;
     * all failed -> failed. Nothing changes while a recipient is still pending.
     */
    public function rollUp(int $noticeId): void
    {
        $counts = KriReturnNoticeRecipient::query()
            ->where('kri_return_notice_id', $noticeId)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        if ((int) ($counts[KriReturnNoticeRecipient::STATUS_PENDING] ?? 0) > 0) {
            return;
        }

        $sent = (int) ($counts[KriReturnNoticeRecipient::STATUS_SENT] ?? 0);
        $failed = (int) ($counts[KriReturnNoticeRecipient::STATUS_FAILED] ?? 0);

        $status = match (true) {
            $sent === 0 && $failed === 0 => $this->skippedForSubmission($noticeId)
                ? KriReturnNotice::STATUS_CANCELLED
                : KriReturnNotice::STATUS_NO_RECIPIENTS,
            $failed === 0 => KriReturnNotice::STATUS_SENT,
            $sent === 0 => KriReturnNotice::STATUS_FAILED,
            default => KriReturnNotice::STATUS_PARTIALLY_FAILED,
        };

        KriReturnNotice::query()->whereKey($noticeId)->update([
            'status' => $status,
            'completed_at' => now(),
        ]);
    }
}
