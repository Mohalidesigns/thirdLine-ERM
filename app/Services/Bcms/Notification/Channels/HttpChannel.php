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
use Illuminate\Support\Facades\Log;
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
            //
            // GATE 2 ADVISORY 11 (round 1). `$e->getMessage()` used to go
            // straight into `failed_reason`, a column kept for years and
            // handed to a regulator on export. `redact()` only strips known
            // KEYS out of a structured array — it has nothing to match against
            // in a free-text exception message — so that move out of
            // `failed_reason` was correct and stands.
            //
            // GATE 2, ROUND 2 (blocking defect 2). Routing the same message to
            // `Log::warning` was not a fix, it was a relocation: this
            // exception wraps Guzzle's `ConnectException`, whose message and
            // `getHandlerContext()` both carry the full request URI —
            // query string and all, which on an aggregator that puts the API
            // key and destination MSISDN in the query string is precisely the
            // identifier this method exists to keep out of any durable sink.
            // The application log has no declared retention or residency, and
            // a deployment that wires Sentry or a log aggregator turns it into
            // an eleventh processor nobody registered. So nothing derived from
            // `getMessage()` or the handler context's `url`/`error` strings is
            // logged. What is logged is the exception class (Guzzle
            // distinguishes DNS failure, refused connection, TLS failure and
            // similar by class/errno, not by prose) and, when the wrapped
            // exception is a `ConnectException`, the bare libcurl `errno`
            // integer from its handler context — 6 (could not resolve host),
            // 7 (could not connect), 28 (timed out), 35 (SSL) and so on. That
            // is a closed, numeric vocabulary with no capacity to carry an
            // address, a number or a key, and it is exactly what separates
            // "gateway is down" (7, 28) from "our config points at the wrong
            // host" (6) at 3am without naming the host.
            Log::warning('BCMS gateway unreachable', [
                'provider' => $this->provider(),
                'exception' => get_class($e),
                'curl_errno' => $this->curlErrno($e),
            ]);

            return DeliveryReceipt::failed($this->provider(), 'Gateway unreachable.');
        } catch (Throwable $e) {
            // This is the adapter-is-broken path (see the class docblock): a
            // provider failure never reaches here, it comes back as a
            // `Response` for `interpret()` to read. Nothing in `perform()`
            // handles recipient data as text, so the same rule applies for the
            // same reason as above — the exception class is logged, the
            // message is not, because a future `perform()` override could
            // legitimately throw something that stringifies request context.
            Log::warning('BCMS gateway error', [
                'provider' => $this->provider(),
                'exception' => get_class($e),
            ]);

            return DeliveryReceipt::failed($this->provider(), 'Gateway error.');
        }

        return $this->interpret($response, $to, $message);
    }

    /**
     * A bare libcurl error number, and nothing else out of Guzzle's handler
     * context.
     *
     * Laravel always wraps the underlying `GuzzleHttp\Exception\ConnectException`
     * as `getPrevious()` when it throws its own `ConnectionException` (see
     * `PendingRequest::marshalConnectionException`), so it is always there to
     * ask. Its `getHandlerContext()` also carries a `url` key with the full
     * request URI and an `error` string that can repeat the host — both are
     * deliberately never read here. `errno` is a closed set of small integers
     * (curl.se/libcurl/c/libcurl-errors.html) that classifies the failure
     * without naming anything.
     */
    protected function curlErrno(ConnectionException $e): ?int
    {
        $previous = $e->getPrevious();

        if (! $previous instanceof \GuzzleHttp\Exception\ConnectException) {
            return null;
        }

        $errno = $previous->getHandlerContext()['errno'] ?? null;

        return is_int($errno) ? $errno : null;
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
     * GATE 2, THIRD ROUND (advisory 11, carried into `raw_response`).
     * `redact()` matches by KEY and exists for secrets, which are always
     * keyed. It cannot reach a provider's free-text failure description,
     * because the number it can carry — "Invalid recipient 2348031234567" — is
     * a VALUE, not a key, and can sit under any field name a gateway chooses
     * to call `message`, `error`, `Message` or `text`. That field lands in
     * `raw_response` on the exact same retained, exported row as
     * `failed_reason`, so fixing the reason string alone would leave the same
     * number one JSON key away. `$textValuePaths` names the dot-paths a
     * *caller* knows are free text (from its own `response.error` config, or
     * hard-coded for a fixed API shape like Meta's `error.message`) so this
     * method can null exactly those values before anything is stored, without
     * this base class having to know each provider's field names itself.
     *
     * @param  list<string>  $textValuePaths  dot-paths whose VALUE is a
     *                                        provider's free-text description, not whose key names a secret.
     * @return array<string, mixed>
     */
    protected function safeResponse(Response $response, array $textValuePaths = []): array
    {
        $body = $response->json();
        $body = is_array($body) ? $this->redact($body) : null;

        if ($body !== null) {
            foreach ($textValuePaths as $path) {
                if (data_get($body, $path) !== null) {
                    data_set($body, $path, '[see failed_reason category]');
                }
            }
        }

        return [
            'status' => $response->status(),
            'provider' => $this->provider(),
            'body' => $body,
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

    /**
     * A closed, bounded classification of a provider's free-text failure
     * description — never the text itself.
     *
     * GATE 2, THIRD ROUND, ADVISORY 11 (one adapter along). `SmsGatewayChannel`,
     * `VoiceTtsChannel` and `WhatsAppCloudChannel` used to put the provider's
     * own error STRING straight into `failed_reason`, a column proposed for
     * seven years' retention and export to a regulator. Nigerian SMS gateways
     * routinely echo the recipient in that string — "Invalid recipient
     * 2348031234567", "DND active for 234803…" — so the number rode along.
     * `redact()` cannot reach it: the number is a value inside free text, not
     * a keyed field.
     *
     * THIS IS CLASSIFICATION, NOT SCRUBBING, AND THE DIFFERENCE IS THE WHOLE
     * POINT. Scrubbing returns the input with matched pieces cut out, so a
     * pattern that misses a format is a silent leak wearing the shape of a
     * fix — the exact `redact()` trap this file argued against elsewhere.
     * This method never returns any part of its input: the result is always
     * one of the fixed labels below, or `null`. A miss here costs a
     * diagnosis, not a leak — the caller falls back to "reason not
     * classified" plus the HTTP status, which is honest about being less
     * specific rather than quietly wrong.
     *
     * WHAT THIS CATCHES, EXACTLY — English-language substrings matched
     * case-insensitively, chosen because they are the wording Termii, Africa's
     * Talking, Infobip and Meta's Cloud API are documented to use for these
     * conditions:
     *   - `invalid_recipient`: "invalid" together with "recipient", "number"
     *     or "msisdn"
     *   - `dnd_blocked`: "dnd" (the NCC's Do-Not-Disturb registry)
     *   - `recipient_blocked`: "blacklist" or "opted out"
     *   - `insufficient_balance`: "insufficient" together with "balance" or
     *     "credit"
     *   - `sender_id_rejected`: "sender id" or "sender name"
     *   - `credential_rejected`: "unauthoriz", "invalid api", "invalid key" or
     *     "authentication"
     *   - `rate_limited`: "rate limit", "throttle" or "too many"
     *
     * WHAT THIS DOES NOT CATCH: any other wording, any non-English provider
     * message, and any condition phrased in a way not listed above —
     * including new wording a gateway starts using tomorrow. All of those
     * fall through to `null`. This list is deliberately short rather than a
     * guess at every string a gateway might send; each entry was picked
     * because it is the term that provider's own API documentation uses, not
     * because it seemed plausible.
     */
    protected function classifyProviderError(string $text): ?string
    {
        $normalised = strtolower($text);

        return match (true) {
            str_contains($normalised, 'invalid') && (
                str_contains($normalised, 'recipient')
                || str_contains($normalised, 'number')
                || str_contains($normalised, 'msisdn')
            ) => 'invalid_recipient',
            str_contains($normalised, 'dnd') => 'dnd_blocked',
            str_contains($normalised, 'blacklist') || str_contains($normalised, 'opted out') => 'recipient_blocked',
            str_contains($normalised, 'insufficient') && (
                str_contains($normalised, 'balance') || str_contains($normalised, 'credit')
            ) => 'insufficient_balance',
            str_contains($normalised, 'sender id') || str_contains($normalised, 'sender name') => 'sender_id_rejected',
            str_contains($normalised, 'unauthoriz')
                || str_contains($normalised, 'invalid api')
                || str_contains($normalised, 'invalid key')
                || str_contains($normalised, 'authentication') => 'credential_rejected',
            str_contains($normalised, 'rate limit')
                || str_contains($normalised, 'throttle')
                || str_contains($normalised, 'too many') => 'rate_limited',
            default => null,
        };
    }
}
