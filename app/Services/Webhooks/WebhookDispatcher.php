<?php

namespace App\Services\Webhooks;

use App\Jobs\DeliverWebhookJob;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * WP-07 TASK 3 — turns something happening into a queued delivery.
 *
 * DISPATCH IS NOT DELIVERY. This writes a pending row and queues a job; nothing
 * here makes an HTTP request. A receiver that takes nine seconds to respond
 * must not add nine seconds to the request in which somebody approved a loss
 * event, and a receiver that is down must not make approving one fail.
 */
class WebhookDispatcher
{
    /**
     * Publish an event to every subscription that wants it.
     *
     * @param  array<string, mixed>  $data
     * @return int how many deliveries were queued
     */
    public function dispatch(string $event, ?Model $subject = null, array $data = [], ?int $organizationId = null): int
    {
        $organizationId ??= $subject?->getAttribute('organization_id') ?? TenantContext::organizationIdOrNull();

        if ($organizationId === null) {
            return 0;
        }

        $payload = $this->payload($event, $subject, $data, $organizationId);

        $subscriptions = WebhookSubscription::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->active()
            ->get()
            ->filter(fn (WebhookSubscription $s) => $s->wants($event) && $s->matches($payload));

        foreach ($subscriptions as $subscription) {
            $delivery = WebhookDelivery::withoutGlobalScopes()->create([
                'organization_id' => $organizationId,
                'subscription_id' => $subscription->id,
                'event' => $event,
                'payload' => $payload,
                'attempt' => 1,
                'status' => WebhookDelivery::STATUS_PENDING,
            ]);

            DeliverWebhookJob::dispatch($delivery->id);
        }

        return $subscriptions->count();
    }

    /**
     * Send a past delivery again, as a new attempt.
     *
     * The payload is the ORIGINAL one, not a fresh read of the record. A replay
     * is "send that event again", and re-reading would send today's state under
     * a timestamp from last week — which is worse than not replaying at all,
     * because the receiver cannot tell.
     */
    public function replay(WebhookDelivery $original, ?User $actor = null): WebhookDelivery
    {
        $delivery = WebhookDelivery::withoutGlobalScopes()->create([
            'organization_id' => $original->organization_id,
            'subscription_id' => $original->subscription_id,
            'event' => $original->event,
            'payload' => $original->payload,
            'attempt' => 1,
            'status' => WebhookDelivery::STATUS_PENDING,
            'replay_of_id' => $original->id,
            'replayed_by' => $actor?->id ?? auth()->id(),
        ]);

        DeliverWebhookJob::dispatch($delivery->id);

        return $delivery;
    }

    /**
     * The event envelope.
     *
     * `id` is per delivery attempt and `event_id` per event, so a receiver can
     * deduplicate retries without discarding a genuine second occurrence of the
     * same kind of thing.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function payload(string $event, ?Model $subject, array $data, int $organizationId): array
    {
        return [
            'event_id' => (string) Str::uuid(),
            'event' => $event,
            'occurred_at' => now()->toIso8601String(),
            'organization_id' => $organizationId,
            'subject' => $subject === null ? null : [
                'type' => $subject->getMorphClass(),
                'id' => $subject->getKey(),
                // Enough to act on without a callback, not the whole record: a
                // webhook body is stored in the delivery log and crosses the
                // network, so it carries identifiers and status rather than
                // every field of an examination finding.
                'reference' => $subject->getAttribute('risk_code')
                    ?? $subject->getAttribute('event_reference')
                    ?? $subject->getAttribute('issue_reference')
                    ?? $subject->getAttribute('treatment_code')
                    ?? $subject->getAttribute('code'),
                'title' => $subject->getAttribute('title') ?? $subject->getAttribute('name'),
                // Read from the STORED attributes rather than through the
                // model's accessors. Several models carry a `status` accessor
                // that lower-cases a canonical upper-case column for backward
                // compatibility (LossEvent::status is the clearest case), so
                // going through it would publish 'escalated' for a row that
                // holds 'ESCALATED' — and a receiver filtering on the value
                // would silently match nothing.
                'status' => $this->storedStatus($subject),
            ],
            'data' => $data,
        ];
    }

    /**
     * The record's state, as stored.
     *
     * The platform has four spellings of "what state is this in", because each
     * module named its own. They are checked in order of specificity so the
     * module's own column wins over the generic one.
     */
    private function storedStatus(Model $subject): ?string
    {
        $stored = $subject->getAttributes();

        foreach (['current_status', 'issue_status', 'lifecycle_state', 'status'] as $column) {
            if (isset($stored[$column]) && $stored[$column] !== null) {
                return (string) $stored[$column];
            }
        }

        return null;
    }
}
