<?php

namespace App\Mail;

use App\Models\KriReturnNoticeRecipient;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A manual reminder about an unsubmitted KRI return (ADR 0026 section 17).
 * Same content as the request plus the days overdue; rendered at send time.
 */
class KriReturnReminder extends Mailable
{
    public function __construct(public readonly KriReturnNoticeRecipient $recipient) {}

    public function envelope(): Envelope
    {
        $view = $this->view();
        $late = $view['days_overdue'] === null ? '' : " ({$view['days_overdue']} days overdue)";

        return new Envelope(
            subject: "Reminder: KRI return for {$view['period']} - {$view['department']}{$late}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.kri-return-reminder', with: $this->view());
    }

    /**
     * @return array{department: string, period: string, due_on: string, days_overdue: int|null, kris: list<array{code: string, name: string}>, url: string, is_champion: bool, recipient_name: string, tier: int}
     */
    protected function view(): array
    {
        return KriReturnMailView::for($this->recipient);
    }
}
