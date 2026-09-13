<?php

namespace App\Observers;

use App\Models\WebhookSubscription;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * WP-07 TASK 3 — publishes `<type>.created`, `.updated` and `.deleted`.
 *
 * Registered against every model the API publishes, so the webhook event names
 * and the API resource names describe the same things. An integrator who can
 * GET /api/v1/risks can subscribe to `risk.created` without learning a second
 * vocabulary.
 *
 * COSTS NOTHING WHEN NOBODY IS SUBSCRIBED, which matters because this runs on
 * every save of every governed record. The guard is a cached existence check
 * per organization: one query per minute rather than one per write. The cache
 * is cleared when a subscription is created, so a new one takes effect
 * immediately rather than within the minute.
 *
 * `.updated` CARRIES WHAT CHANGED, not the whole record. A receiver reacting to
 * "the residual rating moved to Critical" should not have to diff two payloads
 * to discover that, and a webhook body crosses the network and is stored in the
 * delivery log — so it names the fields rather than dumping the row.
 */
class WebhookEventObserver
{
    /**
     * Columns whose change is never interesting on its own and would otherwise
     * fire an event on every touch.
     */
    private const NOISE = ['updated_at', 'last_used_at', 'remember_token'];

    public function __construct(private WebhookDispatcher $dispatcher) {}

    public function created(Model $model): void
    {
        $this->publish($model, 'created', ['attributes' => $this->safe($model, $model->getAttributes())]);
    }

    public function updated(Model $model): void
    {
        $changes = collect($model->getChanges())
            ->except(self::NOISE)
            ->all();

        if ($changes === []) {
            return;
        }

        $this->publish($model, 'updated', [
            'changed' => array_keys($changes),
            'attributes' => $this->safe($model, $changes),
            'previous' => $this->safe($model, collect($model->getOriginal())->only(array_keys($changes))->all()),
        ]);

        // A lifecycle move is the thing most integrations are actually waiting
        // for, and finding it inside a generic `.updated` means every receiver
        // writes the same conditional.
        foreach (['lifecycle_state', 'status', 'issue_status', 'current_status'] as $column) {
            if (array_key_exists($column, $changes)) {
                $this->publish($model, 'state_changed', [
                    'column' => $column,
                    'from' => $model->getOriginal($column),
                    'to' => $changes[$column],
                ]);

                return;
            }
        }
    }

    public function deleted(Model $model): void
    {
        $this->publish($model, 'deleted', []);
    }

    /* ------------------------------------------------------------------ */

    /** @param array<string, mixed> $data */
    private function publish(Model $model, string $action, array $data): void
    {
        $organizationId = $model->getAttribute('organization_id') ?? TenantContext::organizationIdOrNull();

        if ($organizationId === null || ! $this->anySubscriptions($organizationId)) {
            return;
        }

        $this->dispatcher->dispatch(
            $model->getMorphClass().'.'.$action,
            $model,
            $data,
            $organizationId,
        );
    }

    private function anySubscriptions(int $organizationId): bool
    {
        return Cache::remember(
            'webhooks:any:'.$organizationId,
            now()->addMinute(),
            fn () => WebhookSubscription::withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->active()
                ->exists(),
        );
    }

    /**
     * Only fields the API publishes for this type.
     *
     * A webhook body is stored in the delivery log and crosses the network, so
     * it must not carry columns nobody decided to publish — the same allowlist
     * the API uses, for the same reason.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function safe(Model $model, array $attributes): array
    {
        $published = collect(\App\Http\Api\ApiResourceRegistry::all())
            ->firstWhere('model', $model::class)['fields'] ?? null;

        if ($published === null) {
            return ['id' => $model->getKey()];
        }

        return collect($attributes)->only($published)->all();
    }
}
