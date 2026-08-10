<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Support\Http\OutboundUrlGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * WP-07 TASK 3 — one delivery attempt.
 *
 * RETRIES ARE SCHEDULED, NOT DELEGATED TO THE QUEUE. Laravel's own retry would
 * work, but the delivery log has to show every attempt with its response, and a
 * queue retry re-runs the same job with no separate row. So each attempt writes
 * its own record and, if it should try again, queues the next one with the
 * backoff delay.
 *
 * THE URL IS RE-CHECKED HERE, not only when the subscription was created. DNS
 * changes; a hostname that resolved to a public address last month can resolve
 * to 169.254.169.254 today, and the check that matters is the one nearest the
 * request.
 *
 * A 4xx IS NOT RETRIED. It means the receiver understood and refused —
 * retrying a 401 five times just tells the same wrong endpoint four more times.
 * A 5xx or a timeout is retried, because that is a receiver having a bad
 * minute.
 */
class DeliverWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $deliveryId)
    {
        $this->onQueue('webhooks');
    }

    public function handle(): void
    {
        $delivery = WebhookDelivery::withoutGlobalScopes()->with('subscription')->find($this->deliveryId);

        if ($delivery === null || $delivery->succeeded()) {
            return;
        }

        $subscription = $delivery->subscription;

        if ($subscription === null || $subscription->isDisabled()) {
            $delivery->update([
                'status' => WebhookDelivery::STATUS_ABANDONED,
                'error' => 'The subscription is disabled or has been removed.',
            ]);

            return;
        }

        $body = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES);
        $timestamp = now()->timestamp;
        $startedAt = microtime(true);

        try {
            OutboundUrlGuard::assertSafe($subscription->url);

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'User-Agent' => 'Atheris-ERM-Webhook/1',
                'X-Atheris-Event' => $delivery->event,
                'X-Atheris-Delivery' => $delivery->uuid,
                config('webhooks.timestamp_header') => (string) $timestamp,
                config('webhooks.signature_header') => $this->sign($body, $timestamp, $subscription->secret),
            ])
                ->timeout((int) config('webhooks.timeout_seconds', 10))
                // The platform sends; it does not follow. A redirect is a way
                // to point a validated URL at an unvalidated one.
                ->withoutRedirecting()
                ->withBody($body, 'application/json')
                ->post($subscription->url);

            $duration = (int) round((microtime(true) - $startedAt) * 1000);

            if ($response->successful()) {
                $delivery->update([
                    'status' => WebhookDelivery::STATUS_DELIVERED,
                    'status_code' => $response->status(),
                    'response_body' => $this->truncate($response->body()),
                    'duration_ms' => $duration,
                    'delivered_at' => now(),
                ]);

                $subscription->recordSuccess();

                return;
            }

            $this->fail(
                $delivery,
                "The receiver answered {$response->status()}.",
                $response->status(),
                $this->truncate($response->body()),
                $duration,
                // 4xx means understood and refused; retrying tells the same
                // wrong endpoint the same wrong thing four more times.
                retryable: $response->status() >= 500 || $response->status() === 429,
            );
        } catch (Throwable $e) {
            $this->fail(
                $delivery,
                $e->getMessage(),
                null,
                null,
                (int) round((microtime(true) - $startedAt) * 1000),
                // A URL refused by the guard will be refused every time.
                retryable: ! $e instanceof RuntimeException,
            );
        }
    }

    /* ------------------------------------------------------------------ */

    private function fail(
        WebhookDelivery $delivery,
        string $error,
        ?int $status,
        ?string $body,
        int $duration,
        bool $retryable,
    ): void {
        $maxAttempts = (int) config('webhooks.max_attempts', 5);
        $backoff = (array) config('webhooks.backoff_seconds', [10, 60, 300, 1800, 7200]);
        $willRetry = $retryable && $delivery->attempt < $maxAttempts;

        $delivery->update([
            'status' => $willRetry ? WebhookDelivery::STATUS_RETRYING : WebhookDelivery::STATUS_FAILED,
            'status_code' => $status,
            'response_body' => $body,
            'error' => $error,
            'duration_ms' => $duration,
            'next_retry_at' => $willRetry ? now()->addSeconds($backoff[$delivery->attempt - 1] ?? 3600) : null,
        ]);

        $delivery->subscription?->recordFailure($error);

        if (! $willRetry) {
            Log::warning('Webhook delivery gave up.', [
                'delivery' => $delivery->uuid,
                'subscription' => $delivery->subscription_id,
                'event' => $delivery->event,
                'attempts' => $delivery->attempt,
                'error' => $error,
            ]);

            return;
        }

        // The next attempt is its OWN row, so the log shows four attempts with
        // four responses rather than one row overwritten four times.
        $next = WebhookDelivery::withoutGlobalScopes()->create([
            'organization_id' => $delivery->organization_id,
            'subscription_id' => $delivery->subscription_id,
            'event' => $delivery->event,
            'payload' => $delivery->payload,
            'attempt' => $delivery->attempt + 1,
            'status' => WebhookDelivery::STATUS_PENDING,
            'replay_of_id' => $delivery->replay_of_id ?? $delivery->id,
        ]);

        self::dispatch($next->id)->delay(now()->addSeconds($backoff[$delivery->attempt - 1] ?? 3600));
    }

    private function sign(string $body, int $timestamp, string $secret): string
    {
        // The timestamp is signed WITH the body. A signature over the body
        // alone stays valid forever, so a captured payload could be replayed at
        // the receiver indefinitely.
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    private function truncate(?string $body): ?string
    {
        if ($body === null) {
            return null;
        }

        $limit = (int) config('webhooks.max_response_bytes', 8192);

        return strlen($body) > $limit
            ? substr($body, 0, $limit)."\n… truncated at {$limit} bytes."
            : $body;
    }
}
