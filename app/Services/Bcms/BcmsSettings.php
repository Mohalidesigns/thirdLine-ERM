<?php

namespace App\Services\Bcms;

use App\Enums\Bcms\AlertSeverity;
use App\Enums\Bcms\ChannelKey;
use App\Models\Bcms\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The one way to read a tenant's BCMS settings.
 *
 * Gate G0 criterion 4: "tenant settings persist and are read back by a service,
 * not by direct config access". The distinction is not ceremony. `config()` is
 * process-wide and a queue worker holds it across tenants, so a reminder job
 * for a Lagos tenant that read `config('bcms.reminder_send_time')` would use
 * whichever tenant warmed the cache. Every value here is per organisation and
 * falls back to `config/bcms.php` only when the tenant has never saved any.
 *
 * WHY THE DEFAULTS ARE WHAT THEY ARE:
 *
 *   `reminder_send_time` 07:30 Africa/Lagos — before the branch opens, after
 *   people are awake. A reminder that lands at 06:00 is a reminder people learn
 *   to swipe away, and alert fatigue is Blueprint §18's single biggest product
 *   risk.
 *
 *   `default_reminder_mode` digest — standing rule 7 is one digest per user per
 *   day. Discrete is available and is the wrong default: a coordinator on four
 *   exercises would get forty messages in the ten days before a busy week.
 *
 *   `exercise_simulation_default` true and `require_dual_approval_for_live`
 *   true — standing rule 5. Defaulting either the other way means one mis-set
 *   flag sends a real evacuation order.
 */
class BcmsSettings
{
    /** @var array<int, Setting> */
    private array $cache = [];

    public function for(?int $organizationId = null): Setting
    {
        $organizationId ??= TenantContext::organizationIdOrNull();

        if ($organizationId === null) {
            // No tenant resolved — a console command outside a tenant loop.
            // An unsaved model carrying the config defaults is the honest
            // answer: it reads correctly and persists nothing.
            return $this->defaults();
        }

        if (isset($this->cache[$organizationId])) {
            return $this->cache[$organizationId];
        }

        $setting = Setting::query()->where('organization_id', $organizationId)->first();

        if ($setting === null) {
            $setting = $this->defaults();
            $setting->organization_id = $organizationId;
        }

        return $this->cache[$organizationId] = $setting;
    }

    /**
     * Persist a change. Returns the saved row.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(array $attributes, ?int $organizationId = null): Setting
    {
        $organizationId ??= TenantContext::organizationIdOrNull();

        $setting = Setting::query()->firstOrNew(['organization_id' => $organizationId]);
        $setting->fill($this->defaultAttributes());
        $setting->fill($attributes);
        $setting->organization_id = $organizationId;
        $setting->save();

        unset($this->cache[$organizationId]);

        return $setting;
    }

    /** Forget the memoised row — after a settings save inside the same request. */
    public function forget(?int $organizationId = null): void
    {
        $organizationId ??= TenantContext::organizationIdOrNull();

        if ($organizationId === null) {
            $this->cache = [];

            return;
        }

        unset($this->cache[$organizationId]);
    }

    /* ------------------------------------------------------------------ */
    /*  Derived answers — the questions callers actually ask */
    /* ------------------------------------------------------------------ */

    public function timezone(?int $organizationId = null): string
    {
        return (string) $this->for($organizationId)->timezone;
    }

    /**
     * When a reminder for a given day should go out, in UTC.
     *
     * The stored time is LOCAL to the tenant. Materialising a ladder in UTC
     * without this conversion is how a 07:30 reminder arrives at 08:30 for six
     * months of the year in a market that observes no daylight saving but whose
     * customers' other offices do.
     */
    public function sendAtOn(\DateTimeInterface $date, ?int $organizationId = null): CarbonImmutable
    {
        $settings = $this->for($organizationId);

        $time = Carbon::parse((string) $settings->reminder_send_time);

        return CarbonImmutable::parse($date)
            ->setTimezone($settings->timezone)
            ->setTime((int) $time->hour, (int) $time->minute, 0)
            ->setTimezone('UTC');
    }

    /**
     * Is this moment inside the tenant's quiet hours?
     *
     * NEVER CONSULTED FOR LIFE-SAFETY OR CRITICAL TRAFFIC — that decision is
     * `AlertSeverity::respectsQuietHours()` and it is made before this is
     * called. Standing rule 6.
     */
    public function isQuietHour(\DateTimeInterface $moment, ?int $organizationId = null): bool
    {
        $settings = $this->for($organizationId);

        if ($settings->quiet_hours_start === null || $settings->quiet_hours_end === null) {
            return false;
        }

        $local = CarbonImmutable::parse($moment)->setTimezone($settings->timezone);
        $minutes = $local->hour * 60 + $local->minute;

        $start = $this->minutesOfDay((string) $settings->quiet_hours_start);
        $end = $this->minutesOfDay((string) $settings->quiet_hours_end);

        // A window that wraps midnight — 22:00 to 06:00 — is the normal case,
        // and the naive `between` gets it exactly backwards.
        return $start <= $end
            ? ($minutes >= $start && $minutes < $end)
            : ($minutes >= $start || $minutes < $end);
    }

    public function mayDefer(AlertSeverity $severity, \DateTimeInterface $moment, ?int $organizationId = null): bool
    {
        return $severity->respectsQuietHours() && $this->isQuietHour($moment, $organizationId);
    }

    /**
     * @return list<ChannelKey>
     */
    public function defaultChannels(?int $organizationId = null): array
    {
        return $this->channelList($this->for($organizationId)->default_channel_set)
            ?: [ChannelKey::Email, ChannelKey::Sms];
    }

    /**
     * @return list<ChannelKey>
     */
    public function lifeSafetyChannels(?int $organizationId = null): array
    {
        // The fallback is the offline-capable set rather than the default set:
        // a life-safety dispatch that only reaches people with a working data
        // connection is the one case this module exists to survive.
        return $this->channelList($this->for($organizationId)->life_safety_channel_set)
            ?: ChannelKey::offlineCapable();
    }

    /* ------------------------------------------------------------------ */
    /*  Internals */
    /* ------------------------------------------------------------------ */

    /** @return list<ChannelKey> */
    private function channelList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($v) => is_string($v) ? ChannelKey::tryFrom($v) : null,
            $value
        )));
    }

    private function minutesOfDay(string $time): int
    {
        $parsed = Carbon::parse($time);

        return $parsed->hour * 60 + $parsed->minute;
    }

    private function defaults(): Setting
    {
        return new Setting($this->defaultAttributes());
    }

    /** @return array<string, mixed> */
    private function defaultAttributes(): array
    {
        return [
            'timezone' => config('bcms.defaults.timezone', 'Africa/Lagos'),
            'default_lead_time_days' => (int) config('bcms.defaults.lead_time_days', 10),
            'reminder_send_time' => config('bcms.defaults.reminder_send_time', '07:30:00'),
            'default_reminder_mode' => config('bcms.defaults.reminder_mode', 'digest'),
            'quiet_hours_start' => config('bcms.defaults.quiet_hours_start'),
            'quiet_hours_end' => config('bcms.defaults.quiet_hours_end'),
            'escalation_day_offset' => (int) config('bcms.defaults.escalation_day_offset', -2),
            'default_channel_set' => config('bcms.defaults.channel_set', ['email', 'sms']),
            'life_safety_channel_set' => config('bcms.defaults.life_safety_channel_set', ['sms', 'voice', 'ussd']),
            'ai_enabled' => false,
            'exercise_simulation_default' => true,
            'require_dual_approval_for_live' => true,
            'alert_currency' => config('bcms.defaults.currency', 'NGN'),
            'contact_verification_days' => (int) config('bcms.defaults.contact_verification_days', 180),
        ];
    }
}
