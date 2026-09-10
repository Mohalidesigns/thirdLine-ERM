<?php

namespace App\Http\Requests\Admin\Integrations;

use App\Http\Api\ApiResourceRegistry;
use App\Models\WebhookSubscription;
use App\Support\Http\OutboundUrlGuard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Subscribe to events (migration Phase 6.7).
 *
 * `events.*` was `string|max:100` — anything at all — while the form offered a
 * fixed vocabulary derived from the API resource registry. A subscription
 * naming an event nobody publishes never fires, and nothing anywhere says so:
 * the integrator sees a healthy subscription with an empty delivery log and no
 * way to tell "not configured" from "nothing has happened yet". The form offers
 * what the validator accepts now, which is Phase 4.6's rule.
 *
 * The URL is checked with OutboundUrlGuard here rather than at delivery time,
 * so the person who typed it finds out now rather than from a failed delivery
 * in an hour. The guard runs again in DeliverWebhookJob, because a URL that was
 * safe when it was typed can resolve somewhere else later.
 */
class StoreWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', WebhookSubscription::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'url' => ['required', 'url', 'max:2000'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', 'max:100', Rule::in(self::availableEvents())],
            'filters' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator) {
            $url = (string) $this->input('url', '');

            if ($url === '' || $validator->errors()->has('url')) {
                return;
            }

            try {
                OutboundUrlGuard::assertSafe($url);
            } catch (RuntimeException $e) {
                $validator->errors()->add('url', $e->getMessage());
            }
        });
    }

    /**
     * Every event a subscription may name.
     *
     * Derived from the API registry, so the webhook vocabulary and the API
     * resource names stay the same words for the same things.
     *
     * @return list<string>
     */
    public static function availableEvents(): array
    {
        $events = [];

        foreach (self::eventsByResource() as $names) {
            foreach ($names as $name) {
                $events[] = $name;
            }
        }

        return $events;
    }

    /**
     * The same list, grouped for the form.
     *
     * @return array<string, list<string>>
     */
    public static function eventsByResource(): array
    {
        $events = [];

        foreach (ApiResourceRegistry::all() as $definition) {
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
