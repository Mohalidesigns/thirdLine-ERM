<?php

namespace App\Services\Bcms\Notification;

use App\Contracts\Bcms\Configurable;
use App\Contracts\Bcms\NotificationChannel;
use App\Enums\Bcms\ChannelKey;
use App\Services\Bcms\Notification\Channels\FailoverSmsChannel;
use App\Services\Bcms\Notification\Channels\MockEmailChannel;
use App\Services\Bcms\Notification\Channels\MockPushChannel;
use App\Services\Bcms\Notification\Channels\MockSlackChannel;
use App\Services\Bcms\Notification\Channels\MockSmsChannel;
use App\Services\Bcms\Notification\Channels\MockTeamsChannel;
use App\Services\Bcms\Notification\Channels\MockUssdChannel;
use App\Services\Bcms\Notification\Channels\MockVoiceChannel;
use App\Services\Bcms\Notification\Channels\MockWhatsAppChannel;
use App\Services\Bcms\Notification\Channels\SmsGatewayChannel;
use App\Services\Bcms\Notification\Channels\SmtpEmailChannel;
use App\Services\Bcms\Notification\Channels\UssdChannel;
use App\Services\Bcms\Notification\Channels\VoiceTtsChannel;
use App\Services\Bcms\Notification\Channels\WebhookChannel;
use App\Services\Bcms\Notification\Channels\WebPushChannel;
use App\Services\Bcms\Notification\Channels\WhatsAppCloudChannel;
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

        $instance = $this->build($class, $channel);

        if (! $instance instanceof NotificationChannel) {
            throw new InvalidArgumentException(
                $class.' is configured as the '.$channel->value.' channel but does not implement NotificationChannel.'
            );
        }

        /*
         * A REAL ADAPTER WITH NO CREDENTIALS FALLS BACK TO THE MOCK, and this
         * is the most important line in the file. Phase 7's adapters are
         * enabled per channel by naming the class in `bcms.channels`, but the
         * credentials arrive weeks later and on different days per provider
         * (Orchestration §9). Without this, naming the class the day the
         * contract is signed would break every dispatch until the sender ID
         * clears — so an operator would name it late, and the switchover would
         * be a big-bang change during a live quarter.
         *
         * Falling back means "configured but not yet credentialed" behaves
         * exactly as it did the day before, and the EMNS console reports the
         * channel as still mocked. Silence is not an option here; a green tick
         * on a channel that dispatched nothing is the worst failure this module
         * could have, which is what `isMock()` is asked before every dispatch.
         */
        if ($instance instanceof Configurable && ! $instance->isConfigured()) {
            $instance = $this->mockFor($channel);
        }

        return $this->resolved[$channel->value] = $instance;
    }

    /**
     * Build an adapter, handing it the gateway facts it needs.
     *
     * The real adapters take configuration; the Phase 0 mocks take nothing.
     * Rather than making every adapter resolve its own config out of the
     * container — which is how one of them ends up reading a different key from
     * the rest — the registry is the one place that knows where a channel's
     * settings live.
     */
    private function build(string $class, ChannelKey $channel): object
    {
        return match ($class) {
            FailoverSmsChannel::class => new FailoverSmsChannel(
                app(ProviderHealth::class),
                (array) config('bcms-gateways.sms', []),
            ),
            SmsGatewayChannel::class => new SmsGatewayChannel(
                (array) (config('bcms-gateways.sms.0') ?? []),
            ),
            WhatsAppCloudChannel::class => new WhatsAppCloudChannel((array) config('bcms-gateways.whatsapp', [])),
            VoiceTtsChannel::class => new VoiceTtsChannel((array) config('bcms-gateways.voice', [])),
            SmtpEmailChannel::class => new SmtpEmailChannel,
            WebPushChannel::class => new WebPushChannel((array) config('bcms-gateways.push', [])),
            UssdChannel::class => new UssdChannel((array) config('bcms-gateways.ussd', [])),
            WebhookChannel::class => new WebhookChannel(
                $channel,
                (array) config('bcms-gateways.'.$channel->value, []),
            ),
            default => app($class),
        };
    }

    private function mockFor(ChannelKey $channel): NotificationChannel
    {
        $mock = app(self::defaultMap()[$channel->value]);

        /** @var NotificationChannel $mock */
        return $mock;
    }

    /**
     * Every channel's state, for the EMNS console and the provider-health
     * screen: what it is, whether it would really send, and why not.
     *
     * @return list<array<string, mixed>>
     */
    public function states(): array
    {
        $out = [];

        foreach (ChannelKey::cases() as $channel) {
            $adapter = $this->for($channel);
            $configured = config('bcms.channels.'.$channel->value);

            $out[] = [
                'channel' => $channel->value,
                'label' => $channel->label(),
                'provider' => $adapter->provider(),
                'is_mock' => $this->isMock($channel),
                'offline_capable' => $channel->isOfflineCapable(),
                'supports_inbound_ack' => $channel->supportsInboundAck(),
                // The honest reason, so an operator can act on it rather than
                // raise a ticket asking why SMS is not live.
                'status' => match (true) {
                    ! $this->isMock($channel) => 'live',
                    is_string($configured) && $configured !== '' => 'awaiting_credentials',
                    default => 'not_enabled',
                },
            ];
        }

        return $out;
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
