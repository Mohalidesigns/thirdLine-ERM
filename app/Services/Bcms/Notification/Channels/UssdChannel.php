<?php

namespace App\Services\Bcms\Notification\Channels;

use App\Contracts\Bcms\DeliveryReceipt;
use App\Contracts\Bcms\Recipient;
use App\Contracts\Bcms\RenderedMessage;
use App\Enums\Bcms\ChannelKey;
use Illuminate\Http\Client\Response;

/**
 * USSD — the channel for when data is down but GSM works, and for the branch
 * staff who have no smartphone at all (Blueprint §7.2, point 2).
 *
 * USSD IS PULL, NOT PUSH, AND THAT IS THE HONEST SHAPE OF IT. A network cannot
 * make a handset display a USSD menu; the person dials a short code. What this
 * adapter does is REGISTER the alert against the caller's number with the
 * aggregator, so that when they dial `*347*8#` they are shown this alert and can
 * confirm safety. Some Nigerian aggregators offer a network-initiated USSD push;
 * where the operator has that contract, `push_endpoint` is configured and it is
 * used, and where they do not, the registration is still what makes the pull
 * path work.
 *
 * WHICH MEANS "SENT" HERE MEANS SOMETHING NARROWER THAN ON OTHER CHANNELS: the
 * alert is now retrievable by this person. It does not mean their phone did
 * anything. Recording it as delivered would tell a crisis manager the message
 * arrived when nothing has yet reached the handset, and that is the kind of
 * false comfort this module exists to remove.
 *
 * THE AGGREGATOR IS WIRED IN PHASE 12 (short-code allocation runs weeks). The
 * flow, the registration call and the inbound handler are built here; criterion
 * 3 of Phase 6 and criterion 7 of Phase 7 both mark end-to-end USSD as
 * [verify at integration] for that reason.
 */
class UssdChannel extends HttpChannel
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config = [])
    {
        parent::__construct(ChannelKey::Ussd, $config);
    }

    public function provider(): string
    {
        return (string) ($this->config['name'] ?? 'ussd-aggregator');
    }

    /** @return list<string> */
    protected function requiredConfig(): array
    {
        return ['endpoint', 'api_key', 'short_code'];
    }

    protected function perform(string $address, Recipient $to, RenderedMessage $message): Response
    {
        return $this->http()
            ->withToken((string) $this->config['api_key'])
            ->post((string) $this->config['endpoint'], [
                'msisdn' => $address,
                'short_code' => $this->config['short_code'],
                // A USSD screen is ~182 characters. Truncated on a word
                // boundary, never mid-word: a half-word instruction on a
                // feature phone is worse than a shorter one.
                'text' => SmsSegmenter::truncate($message->wireBody(), 1),
                'session_ttl_minutes' => (int) ($this->config['session_ttl_minutes'] ?? 120),
                'callback_token' => $message->callbackToken,
                'push' => (bool) ($this->config['push_enabled'] ?? false),
            ]);
    }

    protected function interpret(Response $response, Recipient $to, RenderedMessage $message): DeliveryReceipt
    {
        if ($response->failed()) {
            return DeliveryReceipt::failed(
                $this->provider(),
                'USSD aggregator returned HTTP '.$response->status().'.',
                $this->safeResponse($response),
            );
        }

        $id = data_get($response->json(), 'session_id');

        return DeliveryReceipt::sent(
            provider: $this->provider(),
            messageId: is_string($id) && $id !== '' ? $id : 'ussd-'.bin2hex(random_bytes(8)),
            costMinor: is_numeric($this->config['cost_minor'] ?? null) ? (int) $this->config['cost_minor'] : null,
            currency: $this->config['currency'] ?? 'NGN',
            raw: array_merge($this->safeResponse($response), [
                // Recorded on the row so an examiner reading the audit is not
                // misled by the word "sent".
                'semantics' => 'registered_for_pull',
            ]),
        );
    }
}
