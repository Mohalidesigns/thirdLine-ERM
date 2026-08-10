<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Services\Webhooks\WebhookDispatcher;
use App\Support\Http\OutboundUrlGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * WP-07 TASK 3 — managing subscriptions and reading the delivery log.
 *
 * The delivery log is the point of the screen. "Did you send it?" is the first
 * question in every integration incident, and it needs an answer with a status
 * code and a response body in it, not "the job ran".
 */
class WebhookController extends Controller
{
    public function __construct(private WebhookDispatcher $dispatcher) {}

    public function index()
    {
        return view('admin.webhooks.index', [
            'subscriptions' => WebhookSubscription::withCount([
                'deliveries as delivered_count' => fn ($q) => $q->where('status', WebhookDelivery::STATUS_DELIVERED),
                'deliveries as failed_count' => fn ($q) => $q->whereIn('status', [
                    WebhookDelivery::STATUS_FAILED, WebhookDelivery::STATUS_ABANDONED,
                ]),
            ])->latest()->get(),
            'events' => $this->availableEvents(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'url' => 'required|url|max:2000',
            'events' => 'required|array|min:1',
            'events.*' => 'string|max:100',
            'filters' => 'nullable|array',
        ]);

        try {
            // Checked before it is stored, so the person who typed it finds out
            // now rather than from a failed delivery in an hour.
            OutboundUrlGuard::assertSafe($validated['url']);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $subscription = WebhookSubscription::create($validated + [
            'created_by' => $request->user()->id,
        ]);

        $this->forgetSubscriptionCache();

        return back()->with('success', 'Webhook created. Its signing secret is shown once, below.')
            ->with('revealed_secret', $subscription->secret)
            ->with('revealed_secret_for', $subscription->name);
    }

    public function update(Request $request, WebhookSubscription $webhook)
    {
        $this->authorizeTenant($webhook);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'url' => 'required|url|max:2000',
            'events' => 'required|array|min:1',
            'is_active' => 'nullable|boolean',
        ]);

        try {
            OutboundUrlGuard::assertSafe($validated['url']);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $webhook->update($validated + [
            'is_active' => $request->boolean('is_active'),
            // Re-enabling clears the automatic disable and its counter,
            // otherwise a subscription fixed after twenty failures is switched
            // straight off again by the twenty-first.
            'disabled_at' => null,
            'disabled_reason' => null,
        ]);

        $webhook->forceFill(['consecutive_failures' => 0])->save();

        $this->forgetSubscriptionCache();

        return back()->with('success', 'Webhook updated.');
    }

    public function destroy(WebhookSubscription $webhook)
    {
        $this->authorizeTenant($webhook);

        $webhook->delete();
        $this->forgetSubscriptionCache();

        return back()->with('success', 'Webhook removed. Its delivery history is kept.');
    }

    /**
     * Rotate the signing secret.
     *
     * Shown once, then only its encrypted form remains. Every receiver has to
     * be updated, which is why it is a deliberate action rather than something
     * that happens on edit.
     */
    public function rotateSecret(WebhookSubscription $webhook)
    {
        $this->authorizeTenant($webhook);

        $secret = \Illuminate\Support\Str::random(48);
        $webhook->update(['secret' => $secret]);

        return back()
            ->with('success', 'Secret rotated. Deliveries signed with the old one will now fail verification.')
            ->with('revealed_secret', $secret)
            ->with('revealed_secret_for', $webhook->name);
    }

    public function deliveries(Request $request, WebhookSubscription $webhook)
    {
        $this->authorizeTenant($webhook);

        return view('admin.webhooks.deliveries', [
            'subscription' => $webhook,
            'deliveries' => $webhook->deliveries()
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
                ->when($request->filled('event'), fn ($q) => $q->where('event', $request->input('event')))
                ->with('replayer')
                ->paginate(50)
                ->withQueryString(),
        ]);
    }

    public function replay(WebhookDelivery $delivery)
    {
        abort_unless($delivery->organization_id === TenantContext::organizationId(), 403);

        $replay = $this->dispatcher->replay($delivery, auth()->user());

        return back()->with('success', 'Queued a replay of that delivery (#'.$replay->id.').');
    }

    /**
     * Send a test event, so an integrator can prove the endpoint and the
     * signature before waiting for something to actually happen.
     */
    public function test(WebhookSubscription $webhook)
    {
        $this->authorizeTenant($webhook);

        $delivery = WebhookDelivery::create([
            'organization_id' => $webhook->organization_id,
            'subscription_id' => $webhook->id,
            'event' => 'webhook.test',
            'payload' => [
                'event' => 'webhook.test',
                'occurred_at' => now()->toIso8601String(),
                'organization_id' => $webhook->organization_id,
                'data' => ['message' => 'If you can verify this signature, your receiver is configured correctly.'],
            ],
            'attempt' => 1,
            'status' => WebhookDelivery::STATUS_PENDING,
        ]);

        \App\Jobs\DeliverWebhookJob::dispatch($delivery->id);

        return back()->with('success', 'Test event queued. Its result appears in the delivery log.');
    }

    /* ------------------------------------------------------------------ */

    private function authorizeTenant(WebhookSubscription $webhook): void
    {
        abort_unless($webhook->organization_id === TenantContext::organizationId(), 403);
    }

    private function forgetSubscriptionCache(): void
    {
        // The observer caches "does this organization have any subscriptions"
        // for a minute. Without this a new webhook would silently receive
        // nothing for up to sixty seconds, which reads as "it does not work".
        Cache::forget('webhooks:any:'.TenantContext::organizationId());
    }

    /**
     * The events a subscription may name.
     *
     * Derived from the API registry, so the webhook vocabulary and the API
     * resource names stay the same words for the same things.
     *
     * @return array<string, list<string>>
     */
    private function availableEvents(): array
    {
        $events = [];

        foreach (\App\Http\Api\ApiResourceRegistry::all() as $definition) {
            $alias = (new $definition['model'])->getMorphClass();

            $events[$alias] = [
                $alias.'.created',
                $alias.'.updated',
                $alias.'.state_changed',
                $alias.'.deleted',
                $alias.'.*',
            ];
        }

        return $events;
    }
}
