<?php

namespace App\Services\Bcms\Notification\Channels;

use App\Contracts\Bcms\Configurable;
use App\Contracts\Bcms\DeliveryReceipt;
use App\Contracts\Bcms\NotificationChannel;
use App\Contracts\Bcms\Recipient;
use App\Contracts\Bcms\RenderedMessage;
use App\Enums\Bcms\ChannelKey;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Email, through the platform's own mailer.
 *
 * THE ONE CHANNEL THAT NEEDS NO NIGERIAN PAPERWORK, and therefore the one that
 * can go live the day this ships. SMS sender IDs, WhatsApp templates and USSD
 * short codes run four to ten weeks (Orchestration §9); an SMTP host is a
 * config line. That asymmetry is why `ChannelRegistry` swaps channels one at a
 * time rather than all at once.
 *
 * IT GOES THROUGH `Mail`, NOT THROUGH AN HTTP GATEWAY, so it inherits whatever
 * the deployment already uses — SES, a bank's own Exchange relay, or the log
 * driver on a demo box. A second mail configuration inside BCMS would be a
 * second thing to get wrong, and the failure mode is silent.
 *
 * EMAIL IS NOT AN EMERGENCY CHANNEL AND THIS FILE WILL NOT PRETEND OTHERWISE.
 * It is not offline-capable, nobody reads it during an evacuation, and its
 * value here is the durable record it leaves. `ChannelKey::offlineCapable()`
 * excludes it deliberately, and a life-safety dispatch that offered only email
 * has not been thought through.
 */
class SmtpEmailChannel implements Configurable, NotificationChannel
{
    /**
     * No constructor arguments, and that is the point: this adapter has nothing
     * of its own to configure. It uses whatever mailer the deployment already
     * has — SES, a bank's Exchange relay, the log driver on a demo box — and a
     * second mail configuration inside BCMS would be a second thing to get
     * wrong, silently.
     */
    public function key(): ChannelKey
    {
        return ChannelKey::Email;
    }

    public function provider(): string
    {
        return 'smtp-'.(string) (config('mail.default') ?? 'mail');
    }

    public function supports(Recipient $to): bool
    {
        return $to->email !== null && $to->email !== '';
    }

    public function isConfigured(): bool
    {
        // The `log` and `array` mailers are real Laravel transports that write
        // nothing to a person. Reporting them as configured would show a green
        // tick on the EMNS console for a channel nobody receives.
        return ! in_array(config('mail.default'), [null, '', 'log', 'array'], true);
    }

    public function send(Recipient $to, RenderedMessage $message): DeliveryReceipt
    {
        if (! $this->supports($to)) {
            return DeliveryReceipt::failed($this->provider(), 'No email address for this contact.');
        }

        $messageId = 'bcms-'.Str::uuid()->toString();

        try {
            Mail::raw($message->wireBody(), function ($mail) use ($to, $message, $messageId) {
                $mail->to($to->email, $to->name)
                    ->subject($message->wireSubject() ?? 'Business continuity notification')
                    // Carried so a bounce or a reply can be matched back to the
                    // delivery row without parsing the body.
                    ->getHeaders()->addTextHeader('X-BCMS-Message-Id', $messageId);
            });
        } catch (Throwable $e) {
            // Never throws for a provider failure — rule 2 of the frozen
            // interface. A refused mailbox must not abort a thousand-recipient
            // dispatch.
            //
            // GATE 2 ADVISORY 11 (round 1). `$e->getMessage()` used to go
            // straight into `failed_reason`, a regulator-facing column kept
            // for years. `redact()` strips known keys out of a structured
            // array and has nothing to match in free text, so moving it out
            // of that column was correct and stands.
            //
            // GATE 2, ROUND 2 (blocking defect 2). Logging the same message
            // was not a fix. Symfony Mailer's `RfcComplianceException` message
            // *is* the offending address — "<ade.okon@bank.ng>: Recipient
            // address rejected" is the whole exception — and
            // `SocketStream`/`TransportException` messages can carry the SMTP
            // host and, on some transports, DSN fragments. None of that has a
            // declared retention or residency once it is in the application
            // log, and a deployment that wires Sentry or a log aggregator
            // turns it into an eleventh processor nobody registered.
            //
            // What is logged instead: the exception class, which already
            // separates a malformed address (`RfcComplianceException`,
            // resolved before any network call) from a transport failure
            // (`TransportException` and its `UnexpectedResponseException`
            // subclass) from a misconfigured DSN (`IncompleteDsnException`,
            // `UnsupportedSchemeException`) without reading a single
            // character of message text; and, for the transport-failure
            // branch, `getCode()` — which Symfony sets to the *numeric SMTP
            // reply code* for both an authentication failure
            // (`EsmtpTransport`: 535/504) and a rejected-recipient failure
            // (`SmtpTransport::assertResponseCode`: e.g. 550, 421). That is a
            // structured protocol field the library already provides, not a
            // pattern match against prose, and it is exactly the bounded
            // status code that tells an operator "our credential is wrong"
            // (535) from "that mailbox does not exist" (550) from "temporary,
            // try again" (421) apart from a bare "the gateway is down". A code
            // of 0 means the transport did not set one (e.g. a socket-level
            // failure before any SMTP reply) — reported as absent, not
            // invented.
            \Illuminate\Support\Facades\Log::warning('BCMS mail transport error', [
                'transport' => config('mail.default'),
                'exception' => get_class($e),
                'smtp_code' => $e->getCode() ?: null,
            ]);

            return DeliveryReceipt::failed($this->provider(), 'Mail transport error.');
        }

        return DeliveryReceipt::sent(
            provider: $this->provider(),
            messageId: $messageId,
            // Email has no per-message cost worth reporting. Null rather than
            // zero, so a spend report does not claim it was free when it is
            // simply not metered.
            costMinor: null,
            currency: null,
            raw: ['transport' => config('mail.default')],
        );
    }

    public function estimateCostMinor(Recipient $to, RenderedMessage $message): ?int
    {
        return null;
    }
}
