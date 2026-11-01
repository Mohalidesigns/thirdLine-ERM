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
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

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
        } catch (QueryException) {
            // Another server queued it between the check and the insert.
            return null;
        }

        return (int) $notice->id;
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

        foreach (array_values(array_unique(array_map('intval', $returnIds))) as $returnId) {
            $return = KriReturn::query()->with('businessUnit')->find($returnId);

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
            } catch (QueryException) {
                // Lost a race on either unique key: another reminder won today.
                $outcome = 'already_reminded_today';
                $noticeId = null;
            }

            if ($noticeId !== null) {
                DispatchKriReturnNotice::dispatch($noticeId)->afterCommit();
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
            if (KriReturn::query()->whereKey($notice->kri_return_id)->value('status') === KriReturnStatus::Submitted->value) {
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

    /** Hand each pending recipient to its own send job (the job is the retry unit). */
    public function dispatchSends(array $recipientIds): void
    {
        foreach ($recipientIds as $id) {
            SendKriReturnNoticeMail::dispatch((int) $id);
        }
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
