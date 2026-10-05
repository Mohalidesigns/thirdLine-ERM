<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * WP-08 TASK 5 — the morning digest: the /my queue, in an inbox.
 *
 * Same data, same buckets, same deep links as the page — the digest is a
 * projection of MyResponsibilitiesService::for(), never its own query, so
 * the email can never disagree with what the person sees on arrival.
 *
 * THE PAYLOAD PROPERTY IS $digest, NOT $queue. Illuminate\Bus\Queueable
 * declares an untyped, non-readonly `public $queue` (the queue NAME), and a
 * class that promotes `public readonly array $queue` over it is an
 * incompatible redeclaration: PHP raises an uncatchable fatal at class load,
 * which killed the 07:30 my:digest run the first day a queue was non-empty.
 * MyResponsibilitiesDigestTest and MailAndNotificationClassesLoadTest guard it.
 */
class MyResponsibilitiesDigest extends Mailable
{
    use Queueable, SerializesModels;

    /** The buckets the digest carries. `later` is on the screen, not in the inbox. */
    public const DUE_SOON_BUCKETS = ['overdue', 'today', 'this_week'];

    /**
     * Narrow a My Responsibilities queue to what is due soon.
     *
     * The page shows everything, including `later` and undated items. The
     * morning mail is a nudge, so it counts, lists and decides to send from the
     * three due-soon buckets only; the headline figures are RECOMPUTED here
     * from those buckets rather than read from the service's totals, which
     * include `later`.
     *
     * @param  array{buckets: array, total_items: int, total_minutes: int}  $queue
     * @return array{buckets: array, total_items: int, total_minutes: int}
     */
    public static function dueSoon(array $queue): array
    {
        $buckets = [];
        $items = 0;
        $minutes = 0;

        foreach (self::DUE_SOON_BUCKETS as $bucket) {
            $buckets[$bucket] = $queue['buckets'][$bucket] ?? [];
            $items += count($buckets[$bucket]);
            $minutes += (int) array_sum(array_column($buckets[$bucket], 'minutes'));
        }

        return ['buckets' => $buckets, 'total_items' => $items, 'total_minutes' => $minutes];
    }

    /**
     * @param  array{buckets: array, total_items: int, total_minutes: int}  $digest  the queue as narrowed by dueSoon()
     */
    public function __construct(
        public readonly User $recipient,
        public readonly array $digest,
    ) {}

    public function envelope(): Envelope
    {
        $overdue = count($this->digest['buckets']['overdue'] ?? []);
        $total = $this->digest['total_items'];
        $subject = "Your risk queue: {$total} ".str('item')->plural($total);

        return new Envelope(
            subject: $overdue > 0 ? "{$subject}, {$overdue} overdue" : $subject,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.my-responsibilities-digest');
    }
}
