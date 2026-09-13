<?php

namespace App\Services\Bcms\Notification\Channels;

use App\Contracts\Bcms\DeliveryReceipt;
use App\Contracts\Bcms\NotificationChannel;
use App\Contracts\Bcms\Recipient;
use App\Contracts\Bcms\RenderedMessage;
use App\Enums\Bcms\ChannelKey;
use App\Enums\Bcms\DeliveryStatus;
use Illuminate\Support\Str;

/**
 * The Phase 0 recording mock every channel is implemented as until Phase 7
 * replaces it (ADR 0004).
 *
 * IT RECORDS AND DISPATCHES NOTHING. The caller has already written the
 * `bcms_notification_deliveries` row as `queued`; this returns a receipt the
 * caller persists over it, exactly as a real adapter will. Tracks B, C and D
 * therefore exercise the real write-ahead, idempotency and audit path from
 * Week 2, and Gate G1's "a dispatcher re-run sends nothing twice" is a test of
 * the real mechanism rather than of a stub.
 *
 * IT CAN FAIL ON PURPOSE. A mock that always succeeds lets a broken failure
 * branch pass QA — the branch that matters, because the whole failover design
 * of Phase 7 hangs off it. `failEvery` makes every nth send return a failed
 * receipt, deterministically, so a test can assert the dispatcher's behaviour
 * rather than hope for it. Default zero: never fail unless asked.
 *
 * IT PRICES NOTHING. `estimateCostMinor()` returns null, because a mock does
 * not know what a Nigerian SMS costs and zero would be a claim that it is free.
 * A cost screen built against the mock must therefore say "not priced" rather
 * than render ₦0, which is the correct behaviour against a real provider that
 * does not report cost either.
 */
class MockChannel implements NotificationChannel
{
    /** @var list<array{recipient: Recipient, message: RenderedMessage}> */
    protected array $sent = [];

    protected int $attempts = 0;

    public function __construct(
        protected ChannelKey $channel,
        protected int $failEvery = 0,
    ) {}

    public function key(): ChannelKey
    {
        return $this->channel;
    }

    public function provider(): string
    {
        return 'mock-'.$this->channel->value;
    }

    public function supports(Recipient $to): bool
    {
        return $to->addressFor($this->channel) !== null;
    }

    public function send(Recipient $to, RenderedMessage $message): DeliveryReceipt
    {
        $this->attempts++;

        $address = $to->addressFor($this->channel);

        if ($address === null) {
            // Not an exception: the dispatcher asked a channel that cannot
            // reach this person, and the honest answer is a failed receipt with
            // a reason, not a crash.
            return DeliveryReceipt::failed($this->provider(), 'No address for this channel.');
        }

        if ($this->failEvery > 0 && $this->attempts % $this->failEvery === 0) {
            return DeliveryReceipt::failed($this->provider(), 'Simulated provider failure.', [
                'mock' => true,
                'attempt' => $this->attempts,
            ]);
        }

        $this->sent[] = ['recipient' => $to, 'message' => $message];

        return new DeliveryReceipt(
            status: DeliveryStatus::Sent,
            provider: $this->provider(),
            providerMessageId: 'mock-'.Str::uuid()->toString(),
            costMinor: null,
            currency: null,
            rawResponse: [
                'mock' => true,
                'channel' => $this->channel->value,
                'address' => $address,
                'locale' => $message->locale,
                // The wire body, not the raw one: a test asserting the
                // "THIS IS AN EXERCISE" prefix must see what would have gone
                // out, which is the point of recording it here.
                'body' => $message->wireBody(),
                'is_simulation' => $message->isSimulation,
            ],
            sentAt: new \DateTimeImmutable,
        );
    }

    public function estimateCostMinor(Recipient $to, RenderedMessage $message): ?int
    {
        return null;
    }

    /* ------------------------------------------------------------------ */
    /*  Test affordances */
    /* ------------------------------------------------------------------ */

    /** @return list<array{recipient: Recipient, message: RenderedMessage}> */
    public function sentMessages(): array
    {
        return $this->sent;
    }

    public function sentCount(): int
    {
        return count($this->sent);
    }

    public function reset(): void
    {
        $this->sent = [];
        $this->attempts = 0;
    }
}
