<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Integrations\StoreWebhookRequest;
use App\Http\Requests\Admin\Integrations\UpdateWebhookRequest;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Services\Webhooks\WebhookDispatcher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;

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
        Gate::authorize('viewAny', WebhookSubscription::class);

        return Inertia::render('Admin/Webhooks/Index', [
            'subscriptions' => WebhookSubscription::withCount([
                'deliveries as delivered_count' => fn ($q) => $q->where('status', WebhookDelivery::STATUS_DELIVERED),
                'deliveries as failed_count' => fn ($q) => $q->whereIn('status', [
                    WebhookDelivery::STATUS_FAILED, WebhookDelivery::STATUS_ABANDONED,
                ]),
            ])->latest()->get()->map(fn (WebhookSubscription $webhook) => array_merge($webhook->only([
                'id', 'name', 'description', 'url', 'is_active', 'consecutive_failures',
                'disabled_reason', 'delivered_count', 'failed_count',
            ]), [
                // The secret is NEVER here. It is shown once, when it is
                // created or rotated, and only its encrypted form is kept.
                'events' => array_values((array) ($webhook->events ?? [])),
                'last_delivered_at' => $webhook->last_delivered_at?->toIso8601String(),
                'last_failed_at' => $webhook->last_failed_at?->toIso8601String(),
                'disabled_at' => $webhook->disabled_at?->toIso8601String(),
                'deliveries_url' => route('admin.webhooks.deliveries', $webhook),
                'can_manage' => Gate::allows('update', $webhook),
            ]))->values()->all(),
            'events' => StoreWebhookRequest::eventsByResource(),

            // Read here rather than from the shared flash props. A secret is
            // shown once, on this screen, on the one request that follows
            // creating or rotating it — putting a secret-shaped key in the
            // props every page receives would be a worse habit than the one
            // saving of a line it buys.
            'revealedSecret' => fn () => session('revealed_secret'),
            'revealedSecretFor' => fn () => session('revealed_secret_for'),
        ]);
    }

    public function store(StoreWebhookRequest $request)
    {
        // The URL is checked by the request now, so the person who typed it
        // finds out at the field rather than from a flash message.
        $validated = $request->validated();

        $subscription = WebhookSubscription::create($validated + [
            'created_by' => $request->user()->id,
        ]);

        $this->forgetSubscriptionCache();

        return back()->with('success', 'Webhook created. Its signing secret is shown once, below.')
            ->with('revealed_secret', $subscription->secret)
            ->with('revealed_secret_for', $subscription->name);
    }

    public function update(UpdateWebhookRequest $request, WebhookSubscription $webhook)
    {
        $validated = $request->validated();

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
        Gate::authorize('delete', $webhook);

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
        Gate::authorize('rotateSecret', $webhook);

        $secret = \Illuminate\Support\Str::random(48);
        $webhook->update(['secret' => $secret]);

        return back()
            ->with('success', 'Secret rotated. Deliveries signed with the old one will now fail verification.')
            ->with('revealed_secret', $secret)
            ->with('revealed_secret_for', $webhook->name);
    }

    public function deliveries(Request $request, WebhookSubscription $webhook)
    {
        Gate::authorize('view', $webhook);

        $deliveries = $webhook->deliveries()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('event'), fn ($q) => $q->where('event', $request->input('event')))
            ->with('replayer:id,name')
            ->latest()
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Admin/Webhooks/Deliveries', [
            'subscription' => $webhook->only(['id', 'name', 'url', 'is_active']),
            'deliveries' => $deliveries->through(fn (WebhookDelivery $delivery) => array_merge($delivery->only([
                'id', 'event', 'status', 'attempt', 'response_status', 'error',
            ]), [
                'created_at' => $delivery->created_at?->toIso8601String(),
                'delivered_at' => $delivery->delivered_at?->toIso8601String(),
                // Truncated: a delivery log is read to answer "did you send it,
                // and what came back" — a megabyte of response body in the page
                // props answers that no better than the first kilobyte.
                'response_body' => Str::limit((string) $delivery->response_body, 1000),
                'replayer' => $delivery->getRelationValue('replayer')?->name,
                'can_replay' => Gate::allows('update', $webhook),
            ])),
            'filters' => [
                'status' => (string) $request->query('status', ''),
                'event' => (string) $request->query('event', ''),
            ],
            'statuses' => [
                WebhookDelivery::STATUS_PENDING,
                WebhookDelivery::STATUS_DELIVERED,
                WebhookDelivery::STATUS_FAILED,
                WebhookDelivery::STATUS_ABANDONED,
            ],
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
        Gate::authorize('update', $webhook);

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

    private function forgetSubscriptionCache(): void
    {
        // The observer caches "does this organization have any subscriptions"
        // for a minute. Without this a new webhook would silently receive
        // nothing for up to sixty seconds, which reads as "it does not work".
        Cache::forget('webhooks:any:'.TenantContext::organizationId());
    }
}
