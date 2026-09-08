<?php

namespace App\Http\Controllers\Bcms;

use App\Enums\Bcms\ChannelKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bcms\UpdateBcmsSettingsRequest;
use App\Services\Bcms\BcmsSettings;
use App\Services\Bcms\Notification\ChannelRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The BCMS tenant settings screen — Gate G0 acceptance criteria 4 and 5.
 *
 * READ AND WRITTEN THROUGH `BcmsSettings`, never through `config()`. The
 * criterion is stated as "settings persist and are read back by a service, not
 * by direct config access", and it is not ceremony: config is process-wide and
 * a queue worker holds it across tenants, so a reminder job that read
 * `config('bcms.defaults.reminder_send_time')` would use whichever tenant
 * warmed the cache.
 *
 * THE SCREEN SHOWS WHICH CHANNELS ARE STILL PHASE 0 MOCKS. An operator has to
 * be able to see that before an emergency rather than discover it during one.
 */
class SettingsController extends Controller
{
    public function __construct(
        private BcmsSettings $settings,
        private ChannelRegistry $channels,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('bcms.admin');

        $settings = $this->settings->for();

        return Inertia::render('Bcms/Settings', [
            'settings' => [
                'timezone' => $settings->timezone,
                'default_lead_time_days' => (int) $settings->default_lead_time_days,
                'reminder_send_time' => substr((string) $settings->reminder_send_time, 0, 5),
                'default_reminder_mode' => $settings->default_reminder_mode,
                'quiet_hours_start' => $settings->quiet_hours_start === null ? null : substr((string) $settings->quiet_hours_start, 0, 5),
                'quiet_hours_end' => $settings->quiet_hours_end === null ? null : substr((string) $settings->quiet_hours_end, 0, 5),
                'escalation_day_offset' => (int) $settings->escalation_day_offset,
                'default_channel_set' => array_map(fn (ChannelKey $c) => $c->value, $this->settings->defaultChannels()),
                'life_safety_channel_set' => array_map(fn (ChannelKey $c) => $c->value, $this->settings->lifeSafetyChannels()),
                'ai_enabled' => (bool) $settings->ai_enabled,
                'exercise_simulation_default' => (bool) $settings->exercise_simulation_default,
                'require_dual_approval_for_live' => (bool) $settings->require_dual_approval_for_live,
                'alert_currency' => $settings->alert_currency,
                'contact_verification_days' => (int) $settings->contact_verification_days,
            ],
            'channels' => array_map(
                fn (ChannelKey $channel) => [
                    'key' => $channel->value,
                    'label' => $channel->label(),
                    'offline_capable' => $channel->isOfflineCapable(),
                    'supports_inbound_ack' => $channel->supportsInboundAck(),
                    'is_mock' => $this->channels->isMock($channel),
                ],
                ChannelKey::cases()
            ),
            'timezones' => timezone_identifiers_list(),
        ]);
    }

    public function update(UpdateBcmsSettingsRequest $request): RedirectResponse
    {
        $this->settings->update($request->validated());

        return back()->with('success', 'BCMS settings saved.');
    }
}
