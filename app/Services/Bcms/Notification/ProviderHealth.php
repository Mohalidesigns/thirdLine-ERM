<?php

namespace App\Services\Bcms\Notification;

use App\Enums\Bcms\DeliveryStatus;
use App\Models\Bcms\NotificationDelivery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Which gateways are actually working, from what actually happened.
 *
 * IT IS DERIVED, NOT STORED. Every fact it needs is already on
 * `bcms_notification_deliveries` — provider, status, failure reason,
 * timestamps — and a second table recording the same events would be a second
 * answer to "did that message get through". Blueprint §7.2 asks for
 * per-provider delivery rate as a KRI; this is where it comes from.
 *
 * THE CIRCUIT BREAKER IS A CACHE KEY, NOT A COLUMN. A gateway that has just
 * failed hard should stop being tried for a few minutes, and that decision has
 * to be readable in microseconds by a dispatcher fanning out to a thousand
 * people. A database round trip per recipient to ask "is Termii up" would cost
 * more than the send. It is deliberately NOT persistent: a worker restart
 * clearing it means the worst case is that a recovered gateway is tried again
 * a little early, which is the direction to be wrong in.
 *
 * A COOLED-OFF GATEWAY IS NEVER THE LAST RESORT. `order()` demotes an unhealthy
 * provider; it does not remove it. If every gateway is cold, the message still
 * goes out through the least-bad one, because in a life-safety dispatch a
 * probably-broken gateway beats no attempt at all.
 */
class ProviderHealth
{
    /** How long a provider stays demoted after tripping the breaker. */
    private const COOLDOWN_SECONDS = 300;

    /** Consecutive failures that trip it. */
    private const FAILURE_THRESHOLD = 3;

    public function recordSuccess(string $provider): void
    {
        Cache::forget($this->failureKey($provider));
        Cache::forget($this->cooldownKey($provider));
    }

    public function recordFailure(string $provider): void
    {
        $failures = (int) Cache::get($this->failureKey($provider), 0) + 1;

        Cache::put($this->failureKey($provider), $failures, self::COOLDOWN_SECONDS);

        if ($failures >= self::FAILURE_THRESHOLD) {
            Cache::put($this->cooldownKey($provider), Carbon::now()->timestamp, self::COOLDOWN_SECONDS);
        }
    }

    public function isCoolingOff(string $provider): bool
    {
        return Cache::has($this->cooldownKey($provider));
    }

    /**
     * Providers in the order they should be tried: healthy ones first, in the
     * operator's configured priority, then the cooling-off ones.
     *
     * @param  list<string>  $providers
     * @return list<string>
     */
    public function order(array $providers): array
    {
        $healthy = [];
        $cold = [];

        foreach ($providers as $provider) {
            if ($this->isCoolingOff($provider)) {
                $cold[] = $provider;
            } else {
                $healthy[] = $provider;
            }
        }

        return array_merge($healthy, $cold);
    }

    /**
     * The dashboard: delivery rate, volume, cost and failures per provider.
     *
     * A PROVIDER WITH NO TRAFFIC HAS NO DELIVERY RATE. Null, not 100% and not
     * zero — a gateway configured last week and never used is neither perfect
     * nor broken, and a green tick against it would be a lie an operator relies
     * on during an emergency (development standard §5).
     *
     * @return list<array<string, mixed>>
     */
    public function dashboard(?Carbon $since = null): array
    {
        $since = $since ?? Carbon::now()->subDays(30);

        /** @var Collection<int, NotificationDelivery> $rows */
        $rows = NotificationDelivery::query()
            ->whereNotNull('provider')
            ->where('created_at', '>=', $since)
            ->get(['provider', 'channel', 'status', 'failed_reason', 'cost_minor', 'currency', 'sent_at', 'delivered_at', 'created_at']);

        $out = [];

        foreach ($rows->groupBy('provider') as $provider => $group) {
            $total = $group->count();
            $delivered = $group->filter(fn (NotificationDelivery $d) => in_array(
                $d->status, [DeliveryStatus::Delivered, DeliveryStatus::Read], true
            ))->count();
            $sent = $group->filter(fn (NotificationDelivery $d) => $d->status !== DeliveryStatus::Failed)->count();
            $failed = $group->filter(fn (NotificationDelivery $d) => $d->status === DeliveryStatus::Failed);

            $latencies = $group
                ->filter(fn (NotificationDelivery $d) => $d->sent_at !== null && $d->delivered_at !== null)
                ->map(fn (NotificationDelivery $d) => $d->sent_at->diffInSeconds($d->delivered_at, absolute: true))
                ->values();

            $priced = $group->filter(fn (NotificationDelivery $d) => $d->cost_minor !== null);

            $out[] = [
                'provider' => (string) $provider,
                'channel' => $group->first()?->channel->value,
                'attempts' => $total,
                'accepted' => $sent,
                'delivered' => $delivered,
                // Delivery rate is against ATTEMPTS, not against accepted. A
                // gateway that accepts everything and delivers nothing is the
                // failure this metric exists to catch.
                'delivery_rate' => $total === 0 ? null : round($delivered / $total * 100, 1),
                'failure_rate' => $total === 0 ? null : round($failed->count() / $total * 100, 1),
                'median_latency_seconds' => $latencies->isEmpty() ? null : $this->median($latencies->all()),
                // Null where nothing reported a cost, so a spend report does
                // not read unpriced traffic as free.
                'cost_minor' => $priced->isEmpty() ? null : (int) $priced->sum('cost_minor'),
                'currency' => $priced->first()?->currency,
                'is_cooling_off' => $this->isCoolingOff((string) $provider),
                'top_failures' => $failed->groupBy('failed_reason')
                    ->map(fn (Collection $g) => $g->count())
                    ->sortDesc()->take(3)
                    ->map(fn (int $count, string $reason) => ['reason' => $reason, 'count' => $count])
                    ->values()->all(),
            ];
        }

        usort($out, fn (array $a, array $b) => $b['attempts'] <=> $a['attempts']);

        return $out;
    }

    /**
     * Spend by channel and by month — the question a Nigerian CFO asks
     * (Blueprint §7.2, point 6).
     *
     * @return array<string, mixed>
     */
    public function spend(?Carbon $since = null): array
    {
        $since = $since ?? Carbon::now()->subMonths(6)->startOfMonth();

        $rows = NotificationDelivery::query()
            ->where('created_at', '>=', $since)
            ->get(['channel', 'cost_minor', 'currency', 'created_at', 'alert_id']);

        $priced = $rows->filter(fn (NotificationDelivery $d) => $d->cost_minor !== null);

        $firstPriced = $priced->first();

        return [
            'currency' => $firstPriced === null ? 'NGN' : ((string) $firstPriced->currency ?: 'NGN'),
            'total_minor' => $priced->isEmpty() ? null : (int) $priced->sum('cost_minor'),
            // The honest denominator: how much of the traffic could be priced
            // at all. A total that ignored this would look authoritative and be
            // wrong by however many providers do not report cost.
            'priced_messages' => $priced->count(),
            'unpriced_messages' => $rows->count() - $priced->count(),
            'by_channel' => $priced->groupBy(fn (NotificationDelivery $d) => $d->channel->value)
                ->map(fn (Collection $g, string $channel) => [
                    'channel' => $channel,
                    'messages' => $g->count(),
                    'cost_minor' => (int) $g->sum('cost_minor'),
                ])->values()->all(),
            'by_month' => $rows->groupBy(fn (NotificationDelivery $d) => $d->created_at?->format('Y-m') ?? 'unknown')
                ->map(fn (Collection $g, string $month) => [
                    'month' => $month,
                    'messages' => $g->count(),
                    'cost_minor' => $g->whereNotNull('cost_minor')->isEmpty()
                        ? null
                        : (int) $g->sum('cost_minor'),
                ])->sortKeys()->values()->all(),
        ];
    }

    /** @param list<int|float> $values */
    private function median(array $values): int
    {
        sort($values);
        $mid = intdiv(count($values), 2);

        return count($values) % 2 === 1
            ? (int) round((float) $values[$mid])
            : (int) round(((float) $values[$mid - 1] + (float) $values[$mid]) / 2);
    }

    private function failureKey(string $provider): string
    {
        return 'bcms:provider:'.$provider.':failures';
    }

    private function cooldownKey(string $provider): string
    {
        return 'bcms:provider:'.$provider.':cooldown';
    }
}
