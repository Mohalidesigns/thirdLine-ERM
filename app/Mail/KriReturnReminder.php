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
        $days = $view['days_overdue'];

        // Spec 10 section 3: the first matching state. "Overdue" is the only strong word.
        $subject = $days !== null && $days > 0
            ? "Overdue: {$view['department']} KRI return for {$view['period']} ({$days} ".($days === 1 ? 'day' : 'days').')'
            : "Reminder: {$view['department']} KRI return for {$view['period']} is due {$view['due_short']}";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.kri-return-reminder',
            text: 'emails.kri-return-reminder-text',
            with: $this->view(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function view(): array
    {
        return KriReturnMailView::for($this->recipient);
    }
}
