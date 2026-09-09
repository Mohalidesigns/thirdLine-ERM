<?php

namespace App\Services\Bcms\Notification\Channels;

use App\Contracts\Bcms\Configurable;
use App\Contracts\Bcms\DeliveryReceipt;
use App\Contracts\Bcms\NotificationChannel;
use App\Contracts\Bcms\Recipient;
use App\Contracts\Bcms\RenderedMessage;
use App\Enums\Bcms\ChannelKey;
use App\Enums\Bcms\DeliveryStatus;
use App\Services\Bcms\Notification\ProviderHealth;

/**
 * Two or three Nigerian SMS gateways behind one channel — the differentiator of
 * Blueprint §7.2, point 1, and acceptance criterion 3.
 *
 * NIGERIAN SMS GATEWAYS FAIL. Not rarely, and not politely: they return 200 and
 * deliver nothing, they rate-limit without saying so, they go down for an hour.
 * A continuity product whose evacuation message depends on one of them has
 * bought the customer a single point of failure in the one system that exists
 * to remove single points of failure.
 *
 * IT IS ITSELF A `NotificationChannel`, so nothing above it knows there is more
 * than one gateway. Phase 5's reminder dispatcher and Phase 6's cascade engine
 * both call `send()` and get a receipt; failover is invisible to them, which is
 * what criterion 14 requires — their code does not change.
 *
 * BOTH ATTEMPTS ARE RECORDED, and that is the part worth being careful about.
 * The receipt returned carries the WHOLE chain in `rawResponse.attempts`, so a
 * delivery row shows that Termii was tried and refused before Africa's Talking
 * accepted. An audit that showed only the successful send would answer "did the
 * message arrive" and not "how close did we come to it not arriving", and the
 * second question is the one that gets a gateway replaced.
 *
 * A SUCCESS RESETS THE BREAKER FOR THAT GATEWAY ONLY. Providers are demoted and
 * recovered independently, because "SMS is down" is almost never true — one
 * gateway is down.
 */
class FailoverSmsChannel implements Configurable, NotificationChannel
{
    /** @var array<string, SmsGatewayChannel> */
    private array $gateways = [];

    /** @var list<string> */
    private array $order = [];

    /**
     * @param  list<array<string, mixed>>  $gatewayConfigs  in the operator's priority order
     */
    public function __construct(
        private ProviderHealth $health,
        array $gatewayConfigs = [],
    ) {
        foreach ($gatewayConfigs as $config) {
            $name = (string) ($config['name'] ?? '');

            if ($name === '') {
                continue;
            }

            $this->gateways[$name] = new SmsGatewayChannel($config);
            $this->order[] = $name;
        }
    }

    public function key(): ChannelKey
    {
        return ChannelKey::Sms;
    }

    public function provider(): string
    {
        // The name of the pool, not of a gateway. The gateway that actually
        // carried a message is on the receipt, and the delivery row is written
        // from the receipt.
        return 'sms-failover';
    }

    public function supports(Recipient $to): bool
    {
        return $to->addressFor(ChannelKey::Sms) !== null && $this->configuredGateways() !== [];
    }

    public function send(Recipient $to, RenderedMessage $message): DeliveryReceipt
    {
        $configured = $this->configuredGateways();

        if ($configured === []) {
            return DeliveryReceipt::failed(
                $this->provider(),
                'No SMS gateway is configured with credentials; nothing was sent.',
            );
        }

        $attempts = [];

        foreach ($this->health->order($configured) as $name) {
            $gateway = $this->gateways[$name];
            $receipt = $gateway->send($to, $message);

            $attempts[] = [
                'provider' => $name,
                'status' => $receipt->status->value,
                'reason' => $receipt->failedReason,
                'was_cooling_off' => $this->health->isCoolingOff($name),
            ];

            if ($receipt->status !== DeliveryStatus::Failed) {
                $this->health->recordSuccess($name);

                // The receipt is rebuilt so the delivery row names the gateway
                // that carried it, and carries the whole chain beside it.
                return new DeliveryReceipt(
                    status: $receipt->status,
                    provider: $name,
                    providerMessageId: $receipt->providerMessageId,
                    failedReason: null,
                    costMinor: $receipt->costMinor,
                    currency: $receipt->currency,
                    rawResponse: array_merge($receipt->rawResponse ?? [], ['attempts' => $attempts]),
                    sentAt: $receipt->sentAt,
                );
            }

            $this->health->recordFailure($name);
        }

        // Everything refused. The reason names every gateway that was tried,
        // because "SMS failed" sends somebody to check the wrong thing.
        return new DeliveryReceipt(
            status: DeliveryStatus::Failed,
            provider: $this->provider(),
            failedReason: 'All '.count($attempts).' SMS gateways refused this message: '
                .implode('; ', array_map(
                    fn (array $a) => $a['provider'].' — '.($a['reason'] ?? 'no reason given'),
                    $attempts,
                )),
            rawResponse: ['attempts' => $attempts],
        );
    }

    public function estimateCostMinor(Recipient $to, RenderedMessage $message): ?int
    {
        // Priced at the gateway that would actually be tried first, not at an
        // average: the estimate has to be within 5% of what is really spent
        // (criterion 9), and the cheapest-gateway average is not what happens.
        foreach ($this->health->order($this->configuredGateways()) as $name) {
            return $this->gateways[$name]->estimateCostMinor($to, $message);
        }

        return null;
    }

    /**
     * The pool is configured if ANY gateway in it is. Requiring all three would
     * keep SMS on the mock until the slowest provider's paperwork cleared,
     * which is the opposite of what failover is for.
     */
    public function isConfigured(): bool
    {
        return $this->configuredGateways() !== [];
    }

    /** @return list<string> */
    private function configuredGateways(): array
    {
        return array_values(array_filter(
            $this->order,
            fn (string $name) => $this->gateways[$name]->isConfigured(),
        ));
    }

    /**
     * What the provider-health screen lists, including gateways that are
     * configured but currently demoted.
     *
     * @return list<array<string, mixed>>
     */
    public function gatewayStates(): array
    {
        $out = [];

        foreach ($this->order as $name) {
            $out[] = [
                'name' => $name,
                'configured' => $this->gateways[$name]->isConfigured(),
                'cooling_off' => $this->health->isCoolingOff($name),
            ];
        }

        return $out;
    }
}
