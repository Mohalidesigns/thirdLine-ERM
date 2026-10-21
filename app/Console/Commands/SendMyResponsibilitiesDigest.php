<?php

namespace App\Console\Commands;

use App\Mail\MyResponsibilitiesDigest;
use App\Models\Organization;
use App\Models\User;
use App\Services\MyResponsibilitiesService;
use App\Support\Chat\ChatText;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * WP-08 TASK 5 — my:digest, the daily nudge behind /my.
 *
 * Per organization, per active user: compute the queue, and if anything is
 * owed, send the digest mail and (when the tenant has configured a chat
 * webhook) post a summary card with deep links. A user with an empty queue
 * gets NOTHING — a daily "you have 0 items" email trains everyone to delete
 * the one that finally matters. The same goes for a queue that is only
 * `later` (not yet due, or undated): the digest counts, lists and decides to
 * send from overdue, today and this-week items alone.
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
        $failed = 0;

        try {
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
                    // One user's failure (queue backend down, a bad row) must
                    // not cost every later user and organisation their digest.
                    try {
                        // Only what is due soon. The page also shows `later`; a
                        // user whose items are all `later` gets neither mail nor card.
                        $queue = MyResponsibilitiesDigest::dueSoon($responsibilities->for($user));

                        if ($queue['total_items'] === 0) {
                            continue;
                        }

                        Mail::to($user->email)->queue(new MyResponsibilitiesDigest($user, $queue));
                        $sent++;

                        if (is_string($webhookUrl) && $webhookUrl !== '') {
                            $this->postChatCard($webhookUrl, $user, $queue);
                        }
                    } catch (\Throwable $e) {
                        $failed++;

                        // Ids and the exception class only. The message of a
                        // mailer or driver exception can carry the address, and
                        // a name or email in a log is personal data (NDPA).
                        logger()->error('my:digest failed for a user', [
                            'user_id' => $user->id,
                            'organization_id' => $organization->id,
                            'exception' => $e::class,
                        ]);
                    }
                }
            }
        } finally {
            TenantContext::clear();
        }

        $this->info("Digest queued for {$sent} users.");

        if ($failed > 0) {
            // Non-zero so the scheduler records the run as failed.
            $this->error("Digest failed for {$failed} users; see the log for ids.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function postChatCard(string $url, User $user, array $queue): void
    {
        $overdue = count($queue['buckets']['overdue'] ?? []);
        $total = $queue['total_items'];

        // Titles and the user's name are typed by people. Escaped for the chat
        // product the URL points at before they are placed in the card; the
        // `*bold*` and bullets below are ours and are added after.
        $lines = collect($queue['buckets'])
            ->flatMap(fn (array $items) => $items)
            ->take(5)
            ->map(fn (array $item) => '• '.ChatText::forUrl($url, $item['title']).($item['due_at'] ? ' (due '.\Carbon\Carbon::parse($item['due_at'])->format('d M').')' : ''))
            ->implode("\n");

        $name = ChatText::forUrl($url, $user->name);

        try {
            Http::timeout(5)->post($url, [
                // 'text' renders in Slack and Teams incoming webhooks alike.
                'text' => "*{$name}* — {$total} risk ".str('item')->plural($total).($overdue ? ", {$overdue} overdue" : '')
                    ."\n{$lines}\n".route('my.index'),
            ]);
        } catch (\Throwable $e) {
            // A chat outage must not stop the mail run; the log is enough. The
            // exception class only: a connection error's message carries the
            // webhook URL, which is a credential.
            logger()->warning('my:digest chat card failed', ['exception' => $e::class]);
        }
    }
}
