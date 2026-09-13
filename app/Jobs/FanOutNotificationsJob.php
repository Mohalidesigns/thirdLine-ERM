<?php

namespace App\Jobs;

use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * WP-07 TASK 1 — one message to many people, off the request cycle.
 *
 * A single notification is one insert and belongs inline. A fan-out is not:
 * "notify every holder of the risk-manager role" in a bank with two hundred of
 * them is two hundred inserts plus two hundred mail attempts, in the request
 * that was supposed to be somebody pressing Approve.
 *
 * RETRIED, unlike the import and simulation jobs, because it IS idempotent
 * enough to be: a duplicate notification is noise, a missing one is a decision
 * nobody knew they owed.
 */
class FanOutNotificationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    /**
     * @param  list<int>  $userIds
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public int $organizationId,
        public array $userIds,
        public string $type,
        public string $subject,
        public string $body,
        public array $metadata = [],
        public ?string $actionUrl = null,
        public string $priority = 'medium',
        public string $category = 'workflow',
    ) {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        TenantContext::actingAs($this->organizationId, function () {
            foreach (array_unique($this->userIds) as $userId) {
                NotificationService::send(
                    $this->organizationId,
                    (int) $userId,
                    $this->type,
                    $this->subject,
                    $this->body,
                    $this->metadata,
                    $this->actionUrl,
                    $this->priority,
                    $this->category,
                );
            }
        });
    }
}
