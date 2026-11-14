<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class GenericNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $recipient,
        public readonly string $notificationSubject,
        public readonly string $notificationBody,
        public readonly ?string $actionUrl = null,
        public readonly string $priority = 'medium',
        public readonly string $category = 'workflow',
    ) {}

    public function envelope(): Envelope
    {
        $appName = config('app.name', 'Vantage ERM');

        return new Envelope(
            subject: "[{$appName}] {$this->notificationSubject}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.generic-notification',
        );
    }
}
