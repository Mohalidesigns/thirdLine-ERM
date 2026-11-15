<?php

namespace App\Mail;

use App\Models\KriDepartmentContact;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to a user when they are designated as Risk Champion for a department.
 */
class RiskChampionAssigned extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly KriDepartmentContact $contact,
    ) {}

    public function envelope(): Envelope
    {
        $contact = $this->contact;
        $departmentName = $contact->businessUnit?->name ?? 'Department';

        return new Envelope(
            subject: "{$departmentName}: You have been designated as Risk Champion",
        );
    }

    public function content(): Content
    {
        $contact = $this->contact;
        $user = $contact->user;
        $unit = $contact->businessUnit;
        $assignedBy = $contact->assignedBy;

        return new Content(
            view: 'emails.risk-champion-assigned',
            text: 'emails.risk-champion-assigned-text',
            with: [
                'recipient_name' => (string) ($user?->name ?? 'Risk Champion'),
                'department' => (string) ($unit?->name ?? 'Department'),
                'assigned_by' => (string) ($assignedBy?->name ?? 'Risk Management Office'),
                'url' => route('risk.kri.my-return', ['department' => $contact->business_unit_id]),
                'app_name' => (string) config('app.name', 'Vantage ERM'),
            ],
        );
    }
}
