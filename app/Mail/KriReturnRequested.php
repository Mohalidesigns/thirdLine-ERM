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
        $view = $this->kriMailData();

        return new Envelope(
            // Spec 09 section 3: the department first, because it is what the reader filters on.
            subject: "{$view['department']}: KRI readings for {$view['period']} due {$view['due_short']}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.kri-return-requested',
            text: 'emails.kri-return-requested-text',
            with: $this->kriMailData(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function kriMailData(): array
    {
        return KriReturnMailView::for($this->recipient);
    }
}
