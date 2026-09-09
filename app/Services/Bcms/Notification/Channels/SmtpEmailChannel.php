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
            return DeliveryReceipt::failed($this->provider(), 'Mail transport error: '.$e->getMessage());
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
