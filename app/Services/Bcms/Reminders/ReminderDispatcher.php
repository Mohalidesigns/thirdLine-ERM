<?php

namespace App\Services\Bcms\Reminders;

use App\Contracts\Bcms\RenderedMessage;
use App\Enums\Bcms\AlertSeverity;
use App\Enums\Bcms\ChannelKey;
use App\Enums\Bcms\DeliveryStatus;
use App\Models\Bcms\Contact;
use App\Models\Bcms\NotificationDelivery;
use App\Models\Bcms\ReminderSchedule;
use App\Services\Bcms\BcmsSettings;
use App\Services\Bcms\ContactResolver;
use App\Services\Bcms\Notification\ChannelRegistry;
use App\Services\NotificationService;
use App\Support\Bcms\ReminderLadder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The hourly tick that actually sends the countdown. Gate G1 lives here.
 *
 * TWO FAILURE MODES END THIS FEATURE, and every decision below is shaped by one:
 *
 *   (a) AN ALERT THAT SHOULD HAVE FIRED AND DID NOT — a compliance failure for
 *       the customer, found by an examiner. Hence: rows are claimed rather than
 *       deleted, a deferred send stays `pending` with a new time rather than
 *       being dropped, a delivery row is written BEFORE the provider is called
 *       (standing rule 8), and anything still `pending` past its time is a
 *       defect the watchdog reports.
 *
 *   (b) ALERT FATIGUE — staff filter BCMS mail to junk and the rhythm dies.
 *       Hence: one digest per person per day, consolidating every exercise they
 *       are in. A person in five overlapping exercises gets one email.
 *
 * THE CLAIM IS A CONDITIONAL UPDATE, NOT A READ-THEN-WRITE. `status` moves
 * `pending → sent` in a single `UPDATE … WHERE status = 'pending'`, so two
 * workers racing on the same tick have one of them update zero rows and stop.
 * Gate G1's "a re-run sends nothing twice" is therefore a property of the data
 * (ADR 0005), not of how carefully the query was written.
 *
 * IT SENDS THROUGH THE REGISTRY AND KNOWS NOTHING ABOUT GATEWAYS. Every channel
 * is a `NotificationChannel`; in Phase 5 they are all recording mocks. Phase 7
 * swaps the bindings and not one line here changes — if a swap would need a
 * change here, the abstraction is wrong and that is the defect.
 */
class ReminderDispatcher
{
    public function __construct(
        private readonly ReminderAudienceResolver $audience,
        private readonly DigestComposer $composer,
        private readonly ChannelRegistry $channels,
        private readonly ContactResolver $contacts,
        private readonly BcmsSettings $settings,
    ) {}

    /**
     * Dispatch everything due for the current tenant.
     *
     * @return array{claimed: int, sent: int, deferred: int, skipped: int, recipients: int, digests: int}
     */
    public function dispatchDue(?Carbon $now = null, bool $dryRun = false): array
    {
        $now ??= Carbon::now();

        $due = ReminderSchedule::query()
            ->where('status', 'pending')
            ->where('send_at', '<=', $now)
            ->orderBy('send_at')
            ->with(['occurrence.definition.exerciseType', 'occurrence.site'])
            ->get();

        $result = ['claimed' => 0, 'sent' => 0, 'deferred' => 0, 'skipped' => 0, 'recipients' => 0, 'digests' => 0];

        if ($due->isEmpty() || $dryRun) {
            $result['claimed'] = $dryRun ? $due->count() : 0;

            return $result;
        }

        // Quiet hours are decided ONCE, per tick, before anything is claimed.
        // Deferring after claiming would leave a row marked sent that was not.
        [$sendable, $deferred] = $this->partitionByQuietHours($due, $now);
        $result['deferred'] = $deferred;

        $discrete = [];
        $digestItems = [];

        foreach ($sendable as $schedule) {
            if (! $this->claim($schedule)) {
                // Another worker got it. Not an error — the point of the claim.
                continue;
            }

            $result['claimed']++;

            $occurrence = $schedule->occurrence;

            if ($occurrence === null) {
                $this->skip($schedule, 'The occurrence has gone.');
                $result['skipped']++;

                continue;
            }

            $recipients = $this->audience->resolve($occurrence, $schedule);

            $schedule->forceFill(['recipient_count' => $recipients->count()])->save();

            if ($recipients->isEmpty()) {
                // A rung with nobody to send to is recorded as sent with the
                // reason, not left pending: it fired and reached nobody, and
                // the plan screen should say so rather than showing a send that
                // never happens.
                $this->skip($schedule, 'No recipients resolved for this audience.', markSent: true);
                $result['skipped']++;

                continue;
            }

            if ($schedule->mode === 'digest') {
                foreach ($recipients as $contact) {
                    $digestItems[$contact->getKey()][] = [
                        'schedule' => $schedule,
                        'occurrence' => $occurrence,
                        'contact' => $contact,
                    ];
                }

                continue;
            }

            $discrete[] = ['schedule' => $schedule, 'occurrence' => $occurrence, 'recipients' => $recipients];
        }

        // ---- Discrete rungs: one message each ------------------------
        foreach ($discrete as $entry) {
            foreach ($entry['recipients'] as $contact) {
                $message = $this->composer->discrete($contact, $entry['schedule'], $entry['occurrence'], $now);

                $result['sent'] += $this->deliver($entry['schedule'], $contact, $message);
                $result['recipients']++;
            }
        }

        // ---- The fatigue guard: one digest per person per day --------
        foreach ($digestItems as $contactId => $items) {
            $contact = $items[0]['contact'];

            $message = $this->composer->digest($contact, array_map(
                fn (array $i) => ['schedule' => $i['schedule'], 'occurrence' => $i['occurrence']],
                $items,
            ), $now);

            // ONE SEND, MANY EVIDENCE ROWS. The message goes out once; a
            // delivery row is written against EACH schedule row it covered, so
            // "show me the reminders for this exercise" is complete even when
            // the message also mentioned four others. They share a provider
            // message id, which is what shows they were one send.
            $sentAny = false;
            $sharedId = null;

            foreach ($items as $index => $item) {
                $sent = $this->deliver(
                    $item['schedule'],
                    $contact,
                    $message,
                    consolidatedInto: $index === 0 ? null : $sharedId,
                    actuallySend: $index === 0,
                );

                if ($index === 0) {
                    $sharedId = $this->lastProviderMessageId;
                    $sentAny = $sent > 0;
                    $result['sent'] += $sent;
                }
            }

            if ($sentAny) {
                $result['digests']++;
                $result['recipients']++;
            }
        }

        return $result;
    }

    /* ------------------------------------------------------------------ */

    private ?string $lastProviderMessageId = null;

    /**
     * Claim a row: `pending → sent` as one conditional update.
     *
     * Returns false when somebody else got there first, which is the whole
     * point rather than an error.
     */
    private function claim(ReminderSchedule $schedule): bool
    {
        $claimed = ReminderSchedule::query()
            ->whereKey($schedule->getKey())
            ->where('status', 'pending')
            ->update(['status' => 'sent', 'dispatched_at' => now(), 'updated_at' => now()]);

        if ($claimed === 1) {
            $schedule->refresh();
        }

        return $claimed === 1;
    }

    /**
     * Quiet hours defer; they never drop.
     *
     * The row keeps `status = pending` and its `send_at` moves to the next
     * permitted window. Criterion 6 is explicit that a suppressed reminder is
     * sent later — a dropped one is failure mode (a) wearing a feature's
     * clothes.
     *
     * @param  \Illuminate\Support\Collection<int, ReminderSchedule>  $due
     * @return array{0: \Illuminate\Support\Collection<int, ReminderSchedule>, 1: int}
     */
    private function partitionByQuietHours($due, Carbon $now): array
    {
        $deferred = 0;

        $sendable = $due->reject(function (ReminderSchedule $schedule) use ($now, &$deferred) {
            $organizationId = (int) $schedule->organization_id;

            // Routine reminders respect quiet hours. Life-safety traffic never
            // does, and never comes through this dispatcher (standing rule 6).
            if (! $this->settings->mayDefer(AlertSeverity::Advisory, $now, $organizationId)) {
                return false;
            }

            $next = $this->nextPermittedWindow($now, $organizationId);

            $schedule->forceFill([
                'send_at' => $next,
                // The column is named for a skip and this is a deferral, which
                // is the same thing from the row's point of view: why it did
                // not send when it was first due.
                'skip_reason' => 'Deferred from '.$now->toDateTimeString().' — inside the tenant\'s quiet hours. '
                    .'Rescheduled for '.$next->toDateTimeString().'.',
            ])->save();

            $deferred++;

            return true;
        });

        return [$sendable, $deferred];
    }

    private function nextPermittedWindow(Carbon $from, int $organizationId): Carbon
    {
        $candidate = $from->copy();

        // Walk forward in fifteen-minute steps to the end of the quiet window,
        // bounded. A tenant whose quiet hours cover the whole day is a
        // misconfiguration, and looping for ever on it would take the tick down
        // rather than surfacing it.
        for ($i = 0; $i < 24 * 4; $i++) {
            $candidate = $candidate->addMinutes(15);

            if (! $this->settings->isQuietHour($candidate, $organizationId)) {
                return $candidate;
            }
        }

        Log::warning('BCMS quiet hours appear to cover the entire day', ['organization_id' => $organizationId]);

        return $from->copy()->addDay();
    }

    private function skip(ReminderSchedule $schedule, string $reason, bool $markSent = false): void
    {
        $schedule->forceFill([
            'status' => $markSent ? 'sent' : 'skipped',
            'skip_reason' => $reason,
            'dispatched_at' => now(),
        ])->save();
    }

    /**
     * Write ahead, then send.
     *
     * STANDING RULE 8. The delivery row is `queued` before the provider is
     * called, so a crash between the write and the call leaves a row the
     * watchdog can find. The reverse leaves an alert nobody knows was lost —
     * which is exactly criterion 10, "killing a worker mid-dispatch loses no
     * alert and duplicates none".
     */
    private function deliver(
        ReminderSchedule $schedule,
        Contact $contact,
        RenderedMessage $message,
        ?string $consolidatedInto = null,
        bool $actuallySend = true,
    ): int {
        $requested = $this->channelKeys($schedule);
        $sent = 0;

        // In-app is not a gateway channel: this product has its own bell, and
        // ADR 0004's eight channels are the ones that need a provider.
        if ($this->wantsInApp($schedule) && $actuallySend) {
            $this->sendInApp($schedule, $contact, $message);
        }

        if ($requested === []) {
            return $sent;
        }

        $usable = $this->contacts->channelsFor($contact, $requested, isLifeSafety: false);

        if ($usable === []) {
            // Nothing this person can be reached on. Recorded as a failed
            // delivery rather than skipped silently — a participant with no
            // usable address is a contact-hygiene problem somebody has to fix.
            $this->record($schedule, $contact, $requested[0], DeliveryStatus::Failed,
                'No usable address for this contact on any requested channel.', $consolidatedInto);

            return $sent;
        }

        foreach ($usable as $channelKey) {
            $delivery = $this->record($schedule, $contact, $channelKey, DeliveryStatus::Queued, null, $consolidatedInto);

            if (! $actuallySend) {
                // A consolidated digest was sent once, under the first schedule
                // row. This row is evidence that this exercise's reminder was
                // covered by it, not a second send.
                $delivery->forceFill([
                    'status' => DeliveryStatus::Sent->value,
                    'sent_at' => now(),
                    'provider_message_id' => $consolidatedInto,
                ])->save();

                continue;
            }

            $channel = $this->channels->for($channelKey);
            $recipient = $this->contacts->recipient($contact);

            $receipt = $channel->send($recipient, $message);

            $this->lastProviderMessageId = $receipt->providerMessageId ?? $this->lastProviderMessageId;

            $delivery->forceFill([
                'status' => $receipt->status->value,
                'provider' => $channel->provider(),
                'provider_message_id' => $receipt->providerMessageId,
                'attempts' => $delivery->attempts + 1,
                'sent_at' => $receipt->status === DeliveryStatus::Failed ? null : now(),
                'failed_reason' => $receipt->failedReason,
                'cost_minor' => $receipt->costMinor,
                'currency' => $receipt->currency,
                'raw_response' => $receipt->rawResponse,
            ])->save();

            if ($receipt->status !== DeliveryStatus::Failed) {
                $sent++;
            }
        }

        return $sent > 0 ? 1 : 0;
    }

    private function record(
        ReminderSchedule $schedule,
        Contact $contact,
        ChannelKey $channel,
        DeliveryStatus $status,
        ?string $failedReason = null,
        ?string $consolidatedInto = null,
    ): NotificationDelivery {
        return NotificationDelivery::query()->create([
            'organization_id' => $schedule->organization_id,
            'reminder_schedule_id' => $schedule->getKey(),
            'recipient_contact_id' => $contact->getKey(),
            'channel' => $channel->value,
            'address' => $this->contacts->recipient($contact)->addressFor($channel),
            'status' => $status->value,
            'attempts' => 0,
            'failed_reason' => $failedReason,
            'raw_response' => $consolidatedInto === null
                ? null
                // Evidence that this exercise's reminder went out inside
                // somebody else's digest — the fatigue guard, made auditable.
                : ['consolidated_into' => $consolidatedInto],
        ]);
    }

    private function sendInApp(ReminderSchedule $schedule, Contact $contact, RenderedMessage $message): void
    {
        if ($contact->user_id === null) {
            return;
        }

        NotificationService::send(
            organizationId: (int) $schedule->organization_id,
            userId: (int) $contact->user_id,
            type: 'bcms.exercise.reminder',
            subject: (string) $message->subject,
            body: $message->wireBody(),
            metadata: [
                'occurrence_id' => $schedule->occurrence_id,
                'day_offset' => $schedule->day_offset,
                'template' => $schedule->template_key,
            ],
            priority: $schedule->day_offset >= -1 ? 'high' : 'medium',
            category: 'bcms',
        );
    }

    /** @return list<ChannelKey> */
    private function channelKeys(ReminderSchedule $schedule): array
    {
        $keys = [];

        foreach ((array) $schedule->channel_set as $value) {
            if ($value === ReminderLadder::CHANNEL_IN_APP) {
                continue;
            }

            $key = ChannelKey::tryFrom((string) $value);

            if ($key !== null) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    private function wantsInApp(ReminderSchedule $schedule): bool
    {
        return in_array(ReminderLadder::CHANNEL_IN_APP, (array) $schedule->channel_set, true);
    }
}
