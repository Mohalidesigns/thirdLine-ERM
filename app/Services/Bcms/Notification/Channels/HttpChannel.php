<?php

namespace App\Services\Bcms\Notification\Channels;

use App\Contracts\Bcms\Configurable;
use App\Contracts\Bcms\DeliveryReceipt;
use App\Contracts\Bcms\NotificationChannel;
use App\Contracts\Bcms\Recipient;
use App\Contracts\Bcms\RenderedMessage;
use App\Enums\Bcms\ChannelKey;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The base every REAL adapter is built on. Phase 7 owns all of them
 * (Orchestration §5); no other track writes one.
 *
 * IT NEVER THROWS FOR A PROVIDER FAILURE, which is rule 2 of the frozen
 * interface and the single most important thing in this file. A gateway that is
 * down, slow, rate-limiting or returning nonsense produces a receipt with
 * `status = failed` and a reason — because the caller decides what to do next,
 * and an adapter that threw would abort a thousand-recipient dispatch on the
 * first bad number. An exception from here means the ADAPTER is broken, not the
 * provider, and that is worth crashing over.
 *
 * IT DOES NOT RETRY. Rule 3. Failover between gateways is the dispatcher's
 * decision, made by reading receipts; an adapter that retried internally would
 * make the delivery audit a lie about how many attempts were made, and the
 * audit is the evidence a regulator asks for after a real event.
 *
 * IT IS UNCONFIGURED UNTIL SOMEBODY CONFIGURES IT. `isConfigured()` is false
 * with no credentials, and `ChannelRegistry` keeps returning the Phase 0 mock
 * until `config('bcms.channels')` names a real class. That is deliberate: the
 * Nigerian sender-ID, WhatsApp template and USSD short-code paperwork runs four
 * to ten weeks (Orchestration §9) and will not clear for all channels together,
 * so each one goes live on its own day without a deployment.
 *
 * THE TIMEOUT IS SHORT AND IS NOT NEGOTIABLE. A life-safety dispatch to a
 * thousand people cannot spend thirty seconds discovering that a gateway is
 * unreachable. Failing fast is what makes failover worth having.
 */
abstract class HttpChannel implements Configurable, NotificationChannel
{
    /** Seconds. A gateway that cannot answer in this is a gateway to fail over from. */
    protected int $timeoutSeconds = 8;

    protected int $connectTimeoutSeconds = 3;

    public function __construct(
        protected ChannelKey $channel,
        /** @var array<string, mixed> */
        protected array $config = [],
    ) {}

    public function key(): ChannelKey
    {
        return $this->channel;
    }

    public function supports(Recipient $to): bool
    {
        return $to->addressFor($this->channel) !== null;
    }

    /**
     * Has an operator given this adapter what it needs to reach the provider?
     *
     * The EMNS console asks, and shows the answer. A crisis manager looking at
     * a channel list needs to know which of them would actually carry a
     * message before the emergency, not during one.
     */
    public function isConfigured(): bool
    {
        foreach ($this->requiredConfig() as $key) {
            if (blank($this->config[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    abstract protected function requiredConfig(): array;

    public function send(Recipient $to, RenderedMessage $message): DeliveryReceipt
    {
        $address = $to->addressFor($this->channel);

        if ($address === null) {
            return DeliveryReceipt::failed($this->provider(), 'No address for this channel.');
        }

        if (! $this->isConfigured()) {
            // Not an exception. An unconfigured adapter that threw would take
            // down a dispatch that could still have reached this person on
            // another channel.
            return DeliveryReceipt::failed(
                $this->provider(),
                'This gateway has no credentials configured; nothing was sent.',
            );
        }

        try {
            $response = $this->perform($address, $to, $message);
        } catch (ConnectionException $e) {
            // The gateway did not answer. This is the case failover exists for
            // and it is reported as its own reason so provider health can tell
            // "unreachable" from "rejected the message".
            return DeliveryReceipt::failed($this->provider(), 'Gateway unreachable: '.$e->getMessage());
        } catch (Throwable $e) {
            return DeliveryReceipt::failed($this->provider(), 'Gateway error: '.$e->getMessage());
        }

        return $this->interpret($response, $to, $message);
    }

    /** Make the call. Anything thrown here becomes a failed receipt. */
    abstract protected function perform(string $address, Recipient $to, RenderedMessage $message): Response;

    /** Turn the provider's answer into the receipt the delivery row is written from. */
    abstract protected function interpret(Response $response, Recipient $to, RenderedMessage $message): DeliveryReceipt;

    public function estimateCostMinor(Recipient $to, RenderedMessage $message): ?int
    {
        // Null, never zero. A channel that cannot price a send says so, and a
        // cost report that summed nulls as zero would understate a crisis
        // dispatch by however many providers did not report.
        $rate = $this->config['cost_minor'] ?? null;

        return is_numeric($rate) ? (int) $rate : null;
    }

    protected function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::timeout($this->timeoutSeconds)
            ->connectTimeout($this->connectTimeoutSeconds)
            ->acceptJson()
            ->withUserAgent('NexusRisk-BCMS/1.0');
    }

    /**
     * What of the provider's response is safe to keep.
     *
     * THE REQUEST BODY IS NEVER RECORDED and neither is any credential. A
     * delivery row is retained for years as evidence and is exported to
     * regulators; the message text is already on the alert, and putting an API
     * key in an audit table is how a key outlives its rotation.
     *
     * @return array<string, mixed>
     */
    protected function safeResponse(Response $response): array
    {
        $body = $response->json();

        return [
            'status' => $response->status(),
            'provider' => $this->provider(),
            'body' => is_array($body) ? $this->redact($body) : null,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    protected function redact(array $payload): array
    {
        $secrets = ['api_key', 'apikey', 'token', 'secret', 'password', 'authorization', 'access_token'];

        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $secrets, true)) {
                $payload[$key] = '[redacted]';

                continue;
            }

            if (is_array($value)) {
                $payload[$key] = $this->redact($value);
            }
        }

        return $payload;
    }
}
