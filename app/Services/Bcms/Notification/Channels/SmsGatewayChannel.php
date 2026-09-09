<?php

namespace App\Services\Bcms\Notification\Channels;

use App\Contracts\Bcms\DeliveryReceipt;
use App\Contracts\Bcms\Recipient;
use App\Contracts\Bcms\RenderedMessage;
use App\Enums\Bcms\ChannelKey;
use Illuminate\Http\Client\Response;

/**
 * One Nigerian SMS gateway, described by configuration rather than by a class.
 *
 * THREE PROVIDERS, ONE ADAPTER. Termii, Africa's Talking and Infobip differ in
 * the name of the field the message goes in and the shape of the JSON that
 * comes back, and in nothing else that matters here. Writing three classes
 * would give three places for the "never throw, never retry" rules to be
 * subtly re-implemented, and the third one would get it wrong. The differences
 * live in `config/bcms-gateways.php` as a payload map; the behaviour lives here.
 *
 * WHY THIS IS NOT OVER-ABSTRACTION. `FailoverSmsChannel` has to hold N of these
 * and treat them identically — that is the whole point of failover — so they
 * were always going to share a shape. A per-provider class would be a
 * distinction the caller could never use.
 *
 * SENDER ID IS A CONFIGURED VALUE AND ITS ABSENCE IS A CONFIGURATION FAILURE,
 * not a default. An SMS sent from an unregistered alphanumeric sender in
 * Nigeria is silently dropped by the networks, which is the worst possible
 * failure mode: the gateway returns success and nobody's phone rings.
 */
class SmsGatewayChannel extends HttpChannel
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config = [])
    {
        parent::__construct(ChannelKey::Sms, $config);
    }

    public function provider(): string
    {
        return (string) ($this->config['name'] ?? 'sms-gateway');
    }

    /** @return list<string> */
    protected function requiredConfig(): array
    {
        return ['endpoint', 'api_key', 'sender_id'];
    }

    protected function perform(string $address, Recipient $to, RenderedMessage $message): Response
    {
        $map = $this->config['payload'] ?? [];

        $payload = [
            ($map['to'] ?? 'to') => $address,
            ($map['from'] ?? 'from') => $this->config['sender_id'],
            ($map['body'] ?? 'sms') => $message->wireBody(),
        ];

        foreach ((array) ($this->config['extra'] ?? []) as $key => $value) {
            $payload[$key] = $value;
        }

        $request = $this->http();

        // Two auth styles cover all three gateways: a bearer header, or the key
        // in the body. Which one is configuration, not code.
        if (($this->config['auth'] ?? 'header') === 'header') {
            $request = $request->withToken((string) $this->config['api_key']);
        } else {
            $payload[$map['api_key'] ?? 'api_key'] = $this->config['api_key'];
        }

        return $request->post((string) $this->config['endpoint'], $payload);
    }

    protected function interpret(Response $response, Recipient $to, RenderedMessage $message): DeliveryReceipt
    {
        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if ($response->failed()) {
            return DeliveryReceipt::failed(
                $this->provider(),
                $this->reasonFrom($response, $body),
                $this->safeResponse($response),
            );
        }

        $idKey = $this->config['response']['message_id'] ?? 'message_id';
        $messageId = data_get($body, $idKey);

        if (! is_string($messageId) || $messageId === '') {
            // A 200 with no message id is not a success anybody can audit: the
            // delivery receipt webhook arrives keyed on that id, and without it
            // this send can never move past `sent`.
            return DeliveryReceipt::failed(
                $this->provider(),
                'Gateway accepted the message but returned no message id, so delivery cannot be tracked.',
                $this->safeResponse($response),
            );
        }

        return DeliveryReceipt::sent(
            provider: $this->provider(),
            messageId: $messageId,
            costMinor: $this->costFrom($body, $message),
            currency: $this->config['currency'] ?? 'NGN',
            raw: $this->safeResponse($response),
        );
    }

    /**
     * @param  array<array-key, mixed>  $body
     */
    private function reasonFrom(Response $response, array $body): string
    {
        $key = $this->config['response']['error'] ?? 'message';
        $reason = data_get($body, $key);

        return is_string($reason) && $reason !== ''
            ? $reason
            : 'Gateway returned HTTP '.$response->status().'.';
    }

    /**
     * What this send cost, from the gateway if it says and from the configured
     * rate if it does not.
     *
     * SEGMENTS ARE COUNTED, NOT MESSAGES. A 320-character alert is two billable
     * SMS and a cost report that called it one would understate a crisis
     * dispatch by half — which is exactly the number a Nigerian CFO checks.
     *
     * @param  array<array-key, mixed>  $body
     */
    private function costFrom(array $body, RenderedMessage $message): ?int
    {
        $reported = data_get($body, $this->config['response']['cost'] ?? 'cost');

        if (is_numeric($reported)) {
            return (int) round((float) $reported * 100);
        }

        $rate = $this->config['cost_minor'] ?? null;

        if (! is_numeric($rate)) {
            return null;
        }

        return (int) $rate * SmsSegmenter::segments($message->wireBody());
    }
}
