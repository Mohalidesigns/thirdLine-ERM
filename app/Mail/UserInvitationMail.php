<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $tempPassword,
        public readonly string $verificationUrl,
    ) {}

    public function envelope(): Envelope
    {
        $appName = config('app.name', 'Vantage ERM');

        return new Envelope(
            subject: "Welcome to {$appName} — Account Verification & Setup",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.user-invitation',
        );
    }
}
