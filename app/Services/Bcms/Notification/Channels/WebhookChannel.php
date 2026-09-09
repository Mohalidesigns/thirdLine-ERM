<?php

namespace App\Services\Bcms\Notification\Channels;

use App\Contracts\Bcms\DeliveryReceipt;
use App\Contracts\Bcms\Recipient;
use App\Contracts\Bcms\RenderedMessage;
use App\Enums\Bcms\ChannelKey;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * Microsoft Teams and Slack — both an HTTP POST of a JSON body to a webhook the
 * customer's IT team pastes in.
 *
 * ONE CLASS FOR TWO CHANNELS because the difference is the shape of one JSON
 * object. Two classes would be two places for the never-throw rule to drift.
 *
 * THE ADDRESS IS PER-RECIPIENT, NOT A CHANNEL-WIDE WEBHOOK, and that is the
 * design decision worth naming. `Recipient::addressFor()` returns the person's
 * `teams_id` or `slack_id`; a single "post to the #ops channel" webhook would
 * reach whoever happened to be watching a channel, which is a broadcast, not a
 * notification to a named person whose acknowledgement is being tracked. The
 * roll-call needs to know that Amina specifically was reached.
 *
 * THESE ARE CORPORATE CHANNELS AND NEED NO NDPA CONSENT (`ContactResolver`
 * treats them as work channels): the bank issues the identity and administers
 * it. A withdrawal of consent for personal-phone contact does not remove
 * somebody from Teams, which is why they stay reachable in a roll-call.
 */
class WebhookChannel extends HttpChannel
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(ChannelKey $channel, array $config = [])
    {
        parent::__construct($channel, $config);
    }

    public function provider(): string
    {
        return $this->channel->value.'-webhook';
    }

    /** @return list<string> */
    protected function requiredConfig(): array
    {
        return ['webhook_url'];
    }

    protected function perform(string $address, Recipient $to, RenderedMessage $message): Response
    {
        $body = $message->wireBody();
        $subject = $message->wireSubject();

        $payload = $this->channel === ChannelKey::Teams
            ? [
                // The legacy MessageCard shape, which every Teams webhook
                // accepts. Adaptive Cards need a Power Automate flow the
                // customer has to build, and an emergency channel that depends
                // on the customer having built something is not one.
                '@type' => 'MessageCard',
                '@context' => 'https://schema.org/extensions',
                'summary' => $subject ?? 'Business continuity notification',
                'themeColor' => $message->severity->value === 'life_safety' ? 'D93025' : '1F6FEB',
                'title' => $subject,
                'text' => $body,
            ]
            : [
                'text' => ($subject !== null ? '*'.$subject.'*'."\n" : '').$body,
                'username' => 'Business Continuity',
            ];

        // The recipient's own identity travels with the payload so a webhook
        // that fans out on the customer's side can address them.
        $payload['bcms_recipient'] = $address;

        return $this->http()->post((string) $this->config['webhook_url'], $payload);
    }

    protected function interpret(Response $response, Recipient $to, RenderedMessage $message): DeliveryReceipt
    {
        if ($response->failed()) {
            return DeliveryReceipt::failed(
                $this->provider(),
                'Webhook returned HTTP '.$response->status().'.',
                $this->safeResponse($response),
            );
        }

        // Neither Teams nor Slack returns a usable message id from an incoming
        // webhook, so one is minted here. It is what the delivery row is keyed
        // on and it is honest about its origin: there will never be a delivery
        // receipt to match it against, and the audit says `sent`, not
        // `delivered`.
        return DeliveryReceipt::sent(
            provider: $this->provider(),
            messageId: 'local-'.Str::uuid()->toString(),
            costMinor: null,
            currency: null,
            raw: $this->safeResponse($response),
        );
    }
}
