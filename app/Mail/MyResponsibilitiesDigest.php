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
 */
class MyResponsibilitiesDigest extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array{buckets: array, total_items: int, total_minutes: int}  $queue
     */
    public function __construct(
        public readonly User $recipient,
        public readonly array $queue,
    ) {}

    public function envelope(): Envelope
    {
        $overdue = count($this->queue['buckets']['overdue'] ?? []);

        return new Envelope(
            subject: $overdue > 0
                ? "Your risk queue: {$this->queue['total_items']} items, {$overdue} overdue"
                : "Your risk queue: {$this->queue['total_items']} ".str('item')->plural($this->queue['total_items']),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.my-responsibilities-digest');
    }
}
