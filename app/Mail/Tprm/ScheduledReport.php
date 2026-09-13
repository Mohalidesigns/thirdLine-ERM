<?php

namespace App\Mail\Tprm;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A scheduled operational report, as an attachment — FR-RPT-09.
 *
 * THE FIGURES ARE IN THE ATTACHMENT AND NOT IN THE BODY. A distribution list
 * for a third-party report routinely includes a procurement mailbox and an
 * outsourced company secretary; putting counts of overdue assessments or
 * undecided sanctions matches in the body of an unencrypted email publishes
 * them to whatever forwards that mailbox. The body says what the file is, who
 * scheduled it and as at when.
 *
 * IT NAMES THE SCHEDULE OWNER. A recipient who does not recognise a report
 * arriving in their inbox needs to know who to ask, and "the system" is not an
 * answer. The owner is also whose permissions the render was authorised
 * against, so naming them is the accountability as well as the courtesy.
 */
class ScheduledReport extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, string>  $provenance
     */
    public function __construct(
        public readonly string $reportTitle,
        public readonly string $scheduleName,
        public readonly string $organizationName,
        public readonly string $ownerName,
        public readonly string $asAt,
        public readonly int $rowCount,
        public readonly array $provenance,
        private readonly string $fileContents,
        private readonly string $fileName,
        private readonly string $mimeType,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            // The client's name leads, not the platform's: a subject line
            // naming the system tells every recipient which product the bank
            // runs.
            subject: sprintf('%s: %s as at %s', $this->organizationName, $this->reportTitle, $this->asAt),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.tprm.scheduled-report',
            with: [
                'reportTitle' => $this->reportTitle,
                'scheduleName' => $this->scheduleName,
                'organizationName' => $this->organizationName,
                'ownerName' => $this->ownerName,
                'asAt' => $this->asAt,
                'rowCount' => $this->rowCount,
                'provenance' => $this->provenance,
                'fileName' => $this->fileName,
            ],
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->fileContents, $this->fileName)
                ->withMime($this->mimeType),
        ];
    }
}
