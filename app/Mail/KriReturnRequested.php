<?php

namespace App\Mail;

use App\Models\KriReturnNoticeRecipient;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The monthly KRI return request (ADR 0026 section 17), following
 * MyResponsibilitiesDigest. Rendered from the return AT SEND TIME - the KRIs
 * due, the due date, the deep link - so it cannot disagree with the screen it
 * links to. No address is hard-coded: the recipient is the row's snapshot.
 *
 * Sent from SendKriReturnNoticeMail with Mail::to()->send(), not queued here:
 * the job is the retry unit.
 */
class KriReturnRequested extends Mailable
{
    public function __construct(public readonly KriReturnNoticeRecipient $recipient) {}

    public function envelope(): Envelope
    {
        $view = $this->view();

        return new Envelope(
            subject: "KRI return for {$view['period']}: {$view['department']} - due {$view['due_on']}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.kri-return-requested', with: $this->view());
    }

    /**
     * @return array{department: string, period: string, due_on: string, days_overdue: int|null, kris: list<array{code: string, name: string}>, url: string, is_champion: bool, recipient_name: string, tier: int}
     */
    protected function view(): array
    {
        return KriReturnMailView::for($this->recipient);
    }
}
