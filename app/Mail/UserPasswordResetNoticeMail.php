<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserPasswordResetNoticeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $tempPassword,
        public readonly string $resetUrl,
    ) {}

    public function envelope(): Envelope
    {
        $appName = config('app.name', 'Vantage ERM');

        return new Envelope(
            subject: "{$appName} — Password Reset Notice",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.user-password-reset',
        );
    }
}
