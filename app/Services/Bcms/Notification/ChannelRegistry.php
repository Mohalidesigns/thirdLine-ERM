<?php

namespace App\Services\Bcms\Notification;

use App\Contracts\Bcms\NotificationChannel;
use App\Enums\Bcms\ChannelKey;
use App\Services\Bcms\Notification\Channels\MockEmailChannel;
use App\Services\Bcms\Notification\Channels\MockPushChannel;
use App\Services\Bcms\Notification\Channels\MockSlackChannel;
use App\Services\Bcms\Notification\Channels\MockSmsChannel;
use App\Services\Bcms\Notification\Channels\MockTeamsChannel;
use App\Services\Bcms\Notification\Channels\MockUssdChannel;
use App\Services\Bcms\Notification\Channels\MockVoiceChannel;
use App\Services\Bcms\Notification\Channels\MockWhatsAppChannel;
use InvalidArgumentException;

/**
 * THE ONE SWAP POINT between the mock adapters of Phase 0 and the real ones of
 * Phase 7 (ADR 0004).
 *
 * Every dispatcher asks this registry for a channel; nothing constructs an
 * adapter directly. That is the whole reason Track B can build the reminder
 * dispatcher in weeks 5–8 against mocks and have it work unchanged in Week 11
 * when Track C lands the real gateways — the swap is a line of configuration,
 * not a refactor of Phase 5.
 *
 * THE DEFAULT MAP IS DELIBERATELY THE MOCKS. `config('bcms.channels')` overrides
 * it per channel, so Phase 7 turns on SMS the week the sender ID is registered
 * and leaves WhatsApp on the mock until Meta approves the templates — which is
 * exactly how the Nigerian paperwork will actually arrive (Orchestration §9).
 *
 * A channel that is configured but whose class does not implement the interface
 * throws HERE, at resolution, rather than later when a crisis dispatch discovers
 * it. Failing at boot is the point.
 */
class ChannelRegistry
{
    /** @var array<string, NotificationChannel> */
    private array $resolved = [];

    /**
     * The Phase 0 defaults. Every entry is replaced by Phase 7, one at a time.
     *
     * @return array<string, class-string<NotificationChannel>>
     */
    public static function defaultMap(): array
    {
        return [
            ChannelKey::Sms->value => MockSmsChannel::class,
            ChannelKey::WhatsApp->value => MockWhatsAppChannel::class,
            ChannelKey::Voice->value => MockVoiceChannel::class,
            ChannelKey::Email->value => MockEmailChannel::class,
            ChannelKey::Teams->value => MockTeamsChannel::class,
            ChannelKey::Slack->value => MockSlackChannel::class,
            ChannelKey::Push->value => MockPushChannel::class,
            ChannelKey::Ussd->value => MockUssdChannel::class,
        ];
    }

    public function for(ChannelKey $channel): NotificationChannel
    {
        if (isset($this->resolved[$channel->value])) {
            return $this->resolved[$channel->value];
        }

        $configured = config('bcms.channels.'.$channel->value);
        $class = is_string($configured) && $configured !== ''
            ? $configured
            : (self::defaultMap()[$channel->value] ?? null);

        if ($class === null || ! class_exists($class)) {
            throw new InvalidArgumentException(
                "No notification channel is registered for '{$channel->value}'."
            );
        }

        $instance = app($class);

        if (! $instance instanceof NotificationChannel) {
            throw new InvalidArgumentException(
                $class.' is configured as the '.$channel->value.' channel but does not implement NotificationChannel.'
            );
        }

        return $this->resolved[$channel->value] = $instance;
    }

    /**
     * Every channel, resolved. Used by the settings screen to show what is live
     * and what is still a mock, which is a thing an operator must be able to see
     * before a real emergency rather than during one.
     *
     * @return array<string, NotificationChannel>
     */
    public function all(): array
    {
        $out = [];

        foreach (ChannelKey::cases() as $channel) {
            $out[$channel->value] = $this->for($channel);
        }

        return $out;
    }

    /**
     * Is this channel still the Phase 0 mock?
     *
     * The EMNS console needs this to say so on screen. A crisis manager who
     * believes an SMS went out because a green tick appeared, when the adapter
     * recorded it and dispatched nothing, is the worst failure this module
     * could have.
     */
    public function isMock(ChannelKey $channel): bool
    {
        return str_starts_with($this->for($channel)->provider(), 'mock-');
    }

    /** Replace a channel at runtime. Tests only; production swaps through config. */
    public function swap(ChannelKey $channel, NotificationChannel $implementation): void
    {
        $this->resolved[$channel->value] = $implementation;
    }
}
