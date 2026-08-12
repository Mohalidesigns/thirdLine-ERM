<?php

namespace App\Console\Commands;

use App\Mail\MyResponsibilitiesDigest;
use App\Models\Organization;
use App\Models\User;
use App\Services\MyResponsibilitiesService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * WP-08 TASK 5 — my:digest, the daily nudge behind /my.
 *
 * Per organization, per active user: compute the queue, and if anything is
 * owed, send the digest mail and (when the tenant has configured a chat
 * webhook) post a summary card with deep links. A user with an empty queue
 * gets NOTHING — a daily "you have 0 items" email trains everyone to delete
 * the one that finally matters.
 *
 * The chat webhook URL lives in organizations.settings->notifications->
 * chat_webhook_url and is posted a plain MessageCard-compatible JSON that
 * both Teams (incoming webhook) and Slack (via workflow webhook) accept as
 * text + link. Nothing here holds credentials.
 */
class SendMyResponsibilitiesDigest extends Command
{
    protected $signature = 'my:digest {--user= : Only this user id (for testing)}';

    protected $description = 'Email each user their My Responsibilities digest and post chat cards';

    public function handle(MyResponsibilitiesService $responsibilities): int
    {
        $sent = 0;

        foreach (Organization::query()->where('is_active', true)->get() as $organization) {
            TenantContext::set($organization->id);

            $webhookUrl = data_get($organization->settings, 'notifications.chat_webhook_url');

            $users = User::query()
                ->where('organization_id', $organization->id)
                ->where('is_active', true)
                ->whereNotNull('email')
                ->when($this->option('user'), fn ($q, $id) => $q->whereKey($id))
                ->get();

            foreach ($users as $user) {
                $queue = $responsibilities->for($user);

                if ($queue['total_items'] === 0) {
                    continue;
                }

                Mail::to($user->email)->queue(new MyResponsibilitiesDigest($user, $queue));
                $sent++;

                if (is_string($webhookUrl) && $webhookUrl !== '') {
                    $this->postChatCard($webhookUrl, $user, $queue);
                }
            }
        }

        TenantContext::clear();

        $this->info("Digest queued for {$sent} users.");

        return self::SUCCESS;
    }

    private function postChatCard(string $url, User $user, array $queue): void
    {
        $overdue = count($queue['buckets']['overdue'] ?? []);

        $lines = collect($queue['buckets'])
            ->flatMap(fn (array $items) => $items)
            ->take(5)
            ->map(fn (array $item) => '• '.$item['title'].($item['due_at'] ? ' (due '.\Carbon\Carbon::parse($item['due_at'])->format('d M').')' : ''))
            ->implode("\n");

        try {
            Http::timeout(5)->post($url, [
                // 'text' renders in Slack and Teams incoming webhooks alike.
                'text' => "*{$user->name}* — {$queue['total_items']} risk items".($overdue ? ", {$overdue} overdue" : '')
                    ."\n{$lines}\n".route('my.index'),
            ]);
        } catch (\Throwable $e) {
            // A chat outage must not stop the mail run; the log is enough.
            logger()->warning('my:digest chat card failed', ['error' => $e->getMessage()]);
        }
    }
}
