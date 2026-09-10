<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WebhookSubscription;

/**
 * Who may manage outbound webhooks (migration Phase 6.7).
 *
 * The controller asked this inline, in four places, as
 * `abort_unless($webhook->organization_id === TenantContext::organizationId(), 403)`.
 * The answer is the same in all four; having it in one place is what lets a
 * console command or an API ask it too.
 *
 * A SUBSCRIPTION IS A STANDING INSTRUCTION TO SEND THIS ORGANISATION'S DATA TO
 * A URL, so the tenant check is not ceremony: another institution's
 * subscription is an endpoint this institution has never approved.
 */
class WebhookSubscriptionPolicy
{
    /**
     * Reading and changing are separate permissions on the routes —
     * `webhook.view` and `webhook.manage` — so they are separate here. A policy
     * that quietly demanded more than its route would make the route's
     * middleware a lie.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('webhook.view');
    }

    public function view(User $user, WebhookSubscription $webhook): bool
    {
        return $user->can('webhook.view') && $this->own($user, $webhook);
    }

    public function create(User $user): bool
    {
        return $user->can('webhook.manage');
    }

    public function update(User $user, WebhookSubscription $webhook): bool
    {
        return $user->can('webhook.manage') && $this->own($user, $webhook);
    }

    public function delete(User $user, WebhookSubscription $webhook): bool
    {
        return $this->update($user, $webhook);
    }

    /**
     * Rotate the signing secret.
     *
     * Named separately because it is not an edit: every receiver has to be
     * updated afterwards, which is why the screen makes it a deliberate action.
     */
    public function rotateSecret(User $user, WebhookSubscription $webhook): bool
    {
        return $this->update($user, $webhook);
    }

    private function own(User $user, WebhookSubscription $webhook): bool
    {
        return (int) $webhook->organization_id === (int) $user->organization_id;
    }
}
