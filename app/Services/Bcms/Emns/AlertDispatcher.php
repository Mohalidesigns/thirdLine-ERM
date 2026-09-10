<?php

namespace App\Services\Bcms\Emns;

use App\Enums\Bcms\AlertSeverity;
use App\Enums\Bcms\ChannelKey;
use App\Enums\Bcms\DeliveryStatus;
use App\Enums\Bcms\RecipientStatus;
use App\Models\Bcms\Alert;
use App\Models\Bcms\AlertRecipient;
use App\Models\Bcms\Contact;
use App\Models\Bcms\NotificationDelivery;
use App\Services\Bcms\ContactResolver;
use App\Services\Bcms\Notification\ChannelRegistry;
use App\Services\Bcms\Notification\ProviderHealth;
use Illuminate\Support\Collection;

/**
 * Sending one chunk of an alert. The part that actually reaches people.
 *
 * IT IS DELIBERATELY SMALL AND DELIBERATELY STATELESS. A queued job hands it a
 * few hundred recipient rows and it sends them; everything about who, whether
 * and when was decided by `AlertService` before the first job was queued. That
 * split is what makes criterion 2 achievable — ten thousand recipients queued
 * in thirty seconds means the HTTP request does no sending at all.
 *
 * THE WRITE-AHEAD ROW GOES FIRST, ALWAYS (standing rule 8). A worker that dies
 * between the write and the provider call leaves a `queued` row the watchdog can
 * find; the reverse leaves an alert nobody knows was lost. This is the same
 * mechanism Phase 5's reminder dispatcher uses, against the same table, which
 * is why criterion 14 holds — the real adapters slot in behind an interface
 * both call.
 *
 * ONE PERSON, MANY CHANNELS, ONE ACKNOWLEDGEMENT. An EMNS targets individuals,
 * not devices (the design principle in the phase brief): somebody reached on
 * SMS and WhatsApp is one recipient with two deliveries, and answering on
 * either settles both. `bcms_alert_recipients` is the person;
 * `bcms_notification_deliveries` are the attempts.
 *
 * IT NEVER DECIDES TO SKIP SOMEBODY. A contact with no usable channel was
 * already marked failed when the list was materialised, with a reason. A
 * dispatcher that silently dropped people would make the roll-call denominator
 * a lie, and the roll-call is how a bank knows whether everyone got out.
 */
class AlertDispatcher
{
    public function __construct(
        private ContactResolver $contacts,
        private ChannelRegistry $channels,
        private TemplateRenderer $renderer,
        private ProviderHealth $health,
    ) {}

    /**
     * Send to a batch of recipient rows.
     *
     * @param  Collection<int, AlertRecipient>  $recipients
     * @return array{sent: int, failed: int, skipped: int}
     */
    public function dispatchBatch(Alert $alert, Collection $recipients): array
    {
        $sent = 0;
        $failed = 0;
        $skipped = 0;

        $isLifeSafety = $alert->severity === AlertSeverity::LifeSafety;

        foreach ($recipients as $recipient) {
            $contact = $recipient->contact;

            if ($contact === null) {
                $recipient->forceFill(['status' => RecipientStatus::Failed->value])->save();
                $failed++;

                continue;
            }

            $channels = $this->channelsFor($recipient, $contact, $alert, $isLifeSafety);

            if ($channels === []) {
                $recipient->forceFill(['status' => RecipientStatus::Failed->value])->save();
                $failed++;

                continue;
            }

            $any = false;

            foreach ($channels as $channel) {
                $result = $this->sendOne($alert, $recipient, $contact, $channel);

                $any = $any || $result;
            }

            if ($any) {
                $recipient->forceFill(['status' => RecipientStatus::Sent->value])->save();
                $sent++;
            } else {
                $recipient->forceFill(['status' => RecipientStatus::Failed->value])->save();
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped];
    }

    /**
     * One person, one channel. Returns whether it left the building.
     */
    private function sendOne(Alert $alert, AlertRecipient $recipient, Contact $contact, ChannelKey $channel): bool
    {
        $adapter = $this->channels->for($channel);
        $to = $this->contacts->recipient($contact);

        $message = $this->renderer->render(
            $alert,
            $channel,
            $contact->preferred_language ?: 'en',
            [],
            $this->tokenFor($recipient),
        );

        // Standing rule 8: the row exists before the provider is called.
        $delivery = NotificationDelivery::query()->create([
            'organization_id' => $alert->organization_id,
            'alert_id' => $alert->getKey(),
            'recipient_contact_id' => $contact->getKey(),
            'channel' => $channel->value,
            'address' => $to->addressFor($channel),
            'status' => DeliveryStatus::Queued->value,
            'attempts' => 0,
        ]);

        /*
         * A SIMULATION WRITES THE ROW AND CALLS NOBODY (criterion 5). It has to
         * write it: the whole point of a simulation is that the operator sees
         * exactly what a real dispatch would produce — the funnel, the
         * per-recipient record, the cost — and a simulation that produced no
         * evidence would train people on a screen they will never see again.
         * The row says `simulated` in its raw response so an examiner reading
         * the audit cannot mistake it for a real send.
         */
        if ($alert->is_simulation) {
            $delivery->forceFill([
                'status' => DeliveryStatus::Sent->value,
                'provider' => 'simulation',
                'provider_message_id' => 'sim-'.$delivery->getKey(),
                'attempts' => 1,
                'sent_at' => now(),
                'raw_response' => [
                    'simulated' => true,
                    'would_have_sent' => $message->wireBody(),
                    'channel' => $channel->value,
                ],
            ])->save();

            return true;
        }

        $receipt = $adapter->send($to, $message);

        $delivery->forceFill(array_merge(
            $receipt->toDeliveryAttributes(),
            ['attempts' => $delivery->attempts + 1],
        ))->save();

        if ($receipt->status === DeliveryStatus::Failed) {
            $this->health->recordFailure($receipt->provider);

            return false;
        }

        $this->health->recordSuccess($receipt->provider);

        return true;
    }

    /**
     * Which channels this person is actually reached on.
     *
     * The list resolved at dispatch is preferred — it was computed when the
     * audience was fixed and is what the operator was shown — but it is
     * re-checked against consent and addresses, because a withdrawal between
     * composing and sending must bite.
     *
     * @return list<ChannelKey>
     */
    private function channelsFor(AlertRecipient $recipient, Contact $contact, Alert $alert, bool $isLifeSafety): array
    {
        $resolved = array_values(array_filter(array_map(
            fn ($c) => is_string($c) ? ChannelKey::tryFrom($c) : null,
            (array) ($recipient->resolved_channels ?? []),
        )));

        $requested = $resolved !== [] ? $resolved : $this->requestedChannels($alert);

        return $this->contacts->channelsFor($contact, $requested, $isLifeSafety);
    }

    /** @return list<ChannelKey> */
    private function requestedChannels(Alert $alert): array
    {
        return array_values(array_filter(array_map(
            fn ($c) => is_string($c) ? ChannelKey::tryFrom($c) : null,
            (array) ($alert->channels ?? []),
        )));
    }

    /**
     * The token an SMS reply, a WhatsApp reply, a USSD session or a web link
     * carries back, identifying one recipient of one alert.
     *
     * An HMAC rather than a stored column: the schema is frozen, the value is
     * derivable, and `hash_equals` compares it in constant time so it cannot be
     * recovered a character at a time. The same shape Phase 6 uses for cascade
     * acknowledgement, deliberately.
     */
    public function tokenFor(AlertRecipient $recipient): string
    {
        return substr(
            hash_hmac('sha256', 'bcms-alert-'.$recipient->getKey(), (string) config('app.key')),
            0,
            16,
        );
    }
}
