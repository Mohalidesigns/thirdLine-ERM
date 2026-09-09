<?php

namespace App\Enums\Bcms;

/**
 * The notification channels the `NotificationChannel` interface is implemented
 * for (ADR 0004). Frozen at G0 with a mock adapter for every case, so Tracks
 * B, C and D build against the full set from Week 2 while the Nigerian
 * sender-ID, WhatsApp template and USSD short-code paperwork runs.
 *
 * `Ussd` is in the frozen set even though it is a Phase 12 capability. Adding
 * a channel later would be a structural change to `channel_set` JSON already
 * written into customers' reminder schedules.
 */
enum ChannelKey: string
{
    case Sms = 'sms';
    case WhatsApp = 'whatsapp';
    case Voice = 'voice';
    case Email = 'email';
    case Teams = 'teams';
    case Slack = 'slack';
    case Push = 'push';
    case Ussd = 'ussd';

    /**
     * Channels that reach a person when the data network is down — the
     * African-infrastructure differentiator in Blueprint §7.2. A life-safety
     * dispatch must include at least one.
     *
     * @return list<self>
     */
    public static function offlineCapable(): array
    {
        return [self::Sms, self::Voice, self::Ussd];
    }

    public function isOfflineCapable(): bool
    {
        return in_array($this, self::offlineCapable(), true);
    }

    /** Channels that carry a two-way acknowledgement without an app installed. */
    public function supportsInboundAck(): bool
    {
        return in_array($this, [self::Sms, self::WhatsApp, self::Voice, self::Ussd, self::Push], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Sms => 'SMS',
            self::WhatsApp => 'WhatsApp',
            self::Voice => 'Voice call',
            self::Email => 'Email',
            self::Teams => 'Microsoft Teams',
            self::Slack => 'Slack',
            self::Push => 'Mobile push',
            self::Ussd => 'USSD',
        };
    }
}
