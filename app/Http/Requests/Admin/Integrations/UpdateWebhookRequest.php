<?php

namespace App\Http\Requests\Admin\Integrations;

/**
 * Amend a subscription (migration Phase 6.7).
 *
 * The same rules. The secret is deliberately not among them: rotating it is its
 * own action, because every receiver has to be updated afterwards and that
 * should never happen as a side effect of saving a name.
 */
class UpdateWebhookRequest extends StoreWebhookRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('webhook'));
    }
}
