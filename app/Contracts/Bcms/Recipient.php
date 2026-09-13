<?php

namespace App\Contracts\Bcms;

use App\Enums\Bcms\ChannelKey;
use App\Models\Bcms\Contact;

/**
 * One person, as a channel sees them. Frozen at G0 (ADR 0004).
 *
 * A VALUE OBJECT BUILT FROM A CONTACT, not the Eloquent model itself. A channel
 * adapter that received a `Contact` could lazy-load its relations inside a
 * dispatch loop of ten thousand, and could write to it. Neither is something an
 * adapter should be able to do, and "don't" is not a mechanism.
 *
 * `preferredLanguage` travels with the recipient rather than with the message
 * because the message is rendered PER RECIPIENT: Blueprint §7.2 ships Hausa,
 * Yoruba, Igbo and Nigerian Pidgin templates, and a single rendered body would
 * send all four groups the English one.
 */
final readonly class Recipient
{
    public function __construct(
        public int $contactId,
        public string $name,
        public ?string $email = null,
        public ?string $mobilePrimary = null,
        public ?string $mobileSecondary = null,
        public ?string $whatsapp = null,
        public ?string $teamsId = null,
        public ?string $slackId = null,
        public ?string $pushToken = null,
        public string $preferredLanguage = 'en',
        public ?int $organizationId = null,
    ) {}

    public static function fromContact(Contact $contact): self
    {
        return new self(
            contactId: (int) $contact->getKey(),
            name: (string) $contact->full_name,
            email: $contact->email,
            mobilePrimary: $contact->mobile_primary,
            mobileSecondary: $contact->mobile_secondary,
            whatsapp: $contact->whatsapp,
            teamsId: $contact->teams_id,
            slackId: $contact->slack_id,
            pushToken: $contact->push_token,
            preferredLanguage: $contact->preferred_language ?: 'en',
            organizationId: $contact->organization_id === null ? null : (int) $contact->organization_id,
        );
    }

    /**
     * The address this channel would use, or null if the recipient has none.
     *
     * Null is a real answer and is not an error: a contact with no WhatsApp
     * handle is not reachable on WhatsApp, and the dispatcher's job is to fall
     * through to the next channel rather than to record a failure against a
     * channel that was never attempted.
     */
    public function addressFor(ChannelKey $channel): ?string
    {
        return match ($channel) {
            ChannelKey::Email => $this->email,
            ChannelKey::Sms, ChannelKey::Voice, ChannelKey::Ussd => $this->mobilePrimary ?? $this->mobileSecondary,
            ChannelKey::WhatsApp => $this->whatsapp ?? $this->mobilePrimary,
            ChannelKey::Teams => $this->teamsId,
            ChannelKey::Slack => $this->slackId,
            ChannelKey::Push => $this->pushToken,
        };
    }
}
