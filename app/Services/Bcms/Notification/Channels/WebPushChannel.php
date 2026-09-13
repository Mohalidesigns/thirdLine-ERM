<?php

namespace App\Services\Bcms\Notification\Channels;

use App\Contracts\Bcms\DeliveryReceipt;
use App\Contracts\Bcms\Recipient;
use App\Contracts\Bcms\RenderedMessage;
use App\Enums\Bcms\ChannelKey;
use Illuminate\Http\Client\Response;

/**
 * Mobile and desktop push, through a push gateway (FCM or a self-hosted Web
 * Push relay).
 *
 * IT CARRIES THE RESPONSE OPTIONS AS ACTIONS. Push is the only channel where
 * "I'm safe" can be one tap from the lock screen without opening anything, and
 * in a roll-call the difference between one tap and opening an app is most of
 * the response rate. `response_options` come from the alert and are passed
 * through as notification actions.
 *
 * PUSH IS NOT OFFLINE-CAPABLE and Blueprint §7.2's whole argument is that the
 * data network is the first thing to fail. It is a fast channel for the people
 * who have signal, never the only one in a life-safety dispatch.
 *
 * A STALE TOKEN IS A CONTACT-HYGIENE PROBLEM, NOT A TRANSPORT ERROR. Push
 * tokens expire when an app is reinstalled or a browser clears storage, and the
 * gateway answers 404 or 410. That is reported as its own reason so the contact
 * roster can be cleaned rather than the gateway blamed.
 */
class WebPushChannel extends HttpChannel
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config = [])
    {
        parent::__construct(ChannelKey::Push, $config);
    }

    public function provider(): string
    {
        return (string) ($this->config['name'] ?? 'push-gateway');
    }

    /** @return list<string> */
    protected function requiredConfig(): array
    {
        return ['endpoint', 'api_key'];
    }

    protected function perform(string $address, Recipient $to, RenderedMessage $message): Response
    {
        $actions = [];

        foreach ((array) ($message->metadata['response_options'] ?? []) as $option) {
            if (is_array($option) && isset($option['value'], $option['label'])) {
                $actions[] = ['action' => (string) $option['value'], 'title' => (string) $option['label']];
            }
        }

        return $this->http()
            ->withToken((string) $this->config['api_key'])
            ->post((string) $this->config['endpoint'], [
                'token' => $address,
                'title' => $message->wireSubject() ?? 'Business continuity notification',
                'body' => $message->wireBody(),
                // Life-safety traffic asks the operating system not to bundle
                // or delay it. Standing rule 6 in the one place the OS can hear
                // it.
                'priority' => $message->severity->value === 'life_safety' ? 'high' : 'normal',
                'require_interaction' => $message->responseRequired,
                'actions' => $actions,
                'data' => [
                    'callback_token' => $message->callbackToken,
                    'locale' => $message->locale,
                ],
            ]);
    }

    protected function interpret(Response $response, Recipient $to, RenderedMessage $message): DeliveryReceipt
    {
        if (in_array($response->status(), [404, 410], true)) {
            return DeliveryReceipt::failed(
                $this->provider(),
                'Push token is no longer registered; the contact record needs a fresh token.',
                $this->safeResponse($response),
            );
        }

        if ($response->failed()) {
            return DeliveryReceipt::failed(
                $this->provider(),
                'Push gateway returned HTTP '.$response->status().'.',
                $this->safeResponse($response),
            );
        }

        $id = data_get($response->json(), 'id');

        return DeliveryReceipt::sent(
            provider: $this->provider(),
            messageId: is_string($id) && $id !== '' ? $id : 'push-'.bin2hex(random_bytes(8)),
            costMinor: null,
            currency: null,
            raw: $this->safeResponse($response),
        );
    }
}
