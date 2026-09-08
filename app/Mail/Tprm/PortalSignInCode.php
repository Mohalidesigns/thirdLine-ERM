<?php

namespace App\Mail\Tprm;

use App\Models\Tprm\PortalUser;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The vendor's sign-in code.
 *
 * NOT QUEUED, and that is deliberate for a message whose whole value is
 * arriving in the next few seconds. A queued code sits behind whatever else is
 * on the worker; the vendor stares at a challenge screen, presses resend, and
 * now two codes are in flight and the first one they try is the invalidated
 * one. Sending inline makes the failure loud — the sign-in errors — rather
 * than silent.
 *
 * IT CARRIES NO LINK. Every credential-phishing template in existence is a
 * "click here to sign in" mail, and teaching a bank's vendors that such a mail
 * is normal makes the real phishing easier. The code is read and typed into a
 * page the vendor already has open.
 */
class PortalSignInCode extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly PortalUser $portalUser,
        public readonly string $code,
        public readonly string $clientName,
        public readonly int $expiresInMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            // The client's name, not ours. The vendor is doing business with
            // the bank; a subject line naming the platform tells every vendor
            // of every client which system that client runs.
            subject: sprintf('%s: your sign-in code is %s', $this->clientName, $this->code),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.tprm.portal-sign-in-code',
            with: [
                'name' => $this->portalUser->name,
                'code' => $this->code,
                'clientName' => $this->clientName,
                'expiresInMinutes' => $this->expiresInMinutes,
            ],
        );
    }
}
