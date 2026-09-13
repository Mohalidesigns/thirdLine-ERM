<?php

namespace App\Http\Requests\Bcms;

use App\Enums\Bcms\ChannelKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The BCMS tenant settings form.
 *
 * `authorize()` ASSERTS WHAT ACTUALLY GUARDS THE ROUTE (development standard
 * §3). The route carries `permission:bcms.admin`; this says the same thing, so
 * that a route rewritten without the middleware fails here rather than opening
 * quietly.
 *
 * THE CHANNEL SETS ARE VALIDATED AGAINST THE ENUM, not against a free list. A
 * channel name that is not a `ChannelKey` would be stored, resolved to nothing
 * by `ChannelRegistry`, and silently drop a channel from every dispatch that
 * used the default set.
 *
 * QUIET HOURS ARE ALL-OR-NOTHING. A start with no end is a window with no
 * close, which the deferral logic would read as "quiet from 22:00 until
 * 22:00" — never, or always, depending on which comparison somebody wrote.
 */
class UpdateBcmsSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bcms.admin') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $channels = ChannelKey::cases();
        $channelValues = array_map(fn (ChannelKey $c) => $c->value, $channels);

        return [
            'timezone' => ['required', 'string', 'max:60', Rule::in(timezone_identifiers_list())],
            'default_lead_time_days' => ['required', 'integer', 'min:1', 'max:60'],
            'reminder_send_time' => ['required', 'date_format:H:i'],
            'default_reminder_mode' => ['required', Rule::in(['discrete', 'digest'])],

            'quiet_hours_start' => ['nullable', 'date_format:H:i', 'required_with:quiet_hours_end'],
            'quiet_hours_end' => ['nullable', 'date_format:H:i', 'required_with:quiet_hours_start'],

            // Negative: days before the exercise. Zero would mean "escalate on
            // the day", which is not an escalation, it is a postmortem.
            'escalation_day_offset' => ['required', 'integer', 'min:-30', 'max:-1'],

            'default_channel_set' => ['required', 'array', 'min:1'],
            'default_channel_set.*' => [Rule::in($channelValues)],

            'life_safety_channel_set' => ['required', 'array', 'min:1'],
            'life_safety_channel_set.*' => [Rule::in($channelValues)],

            'ai_enabled' => ['required', 'boolean'],
            'exercise_simulation_default' => ['required', 'boolean'],
            'require_dual_approval_for_live' => ['required', 'boolean'],
            'alert_currency' => ['required', 'string', 'size:3'],
            'contact_verification_days' => ['required', 'integer', 'min:30', 'max:730'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $lifeSafety = (array) $this->input('life_safety_channel_set', []);

            $offline = array_map(fn (ChannelKey $c) => $c->value, ChannelKey::offlineCapable());

            // Blueprint §7.2 and §14: the network is the first thing to fail,
            // and every life-safety interaction must have an SMS or USSD path.
            // A life-safety set of email and Teams alone is a set that stops
            // working in exactly the situation it exists for.
            if (array_intersect($lifeSafety, $offline) === []) {
                $validator->errors()->add(
                    'life_safety_channel_set',
                    'Life-safety alerts must include at least one channel that works without a data connection: SMS, voice or USSD.'
                );
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'escalation_day_offset.max' => 'Escalation must happen before the exercise, so this is a negative number of days.',
            'quiet_hours_start.required_with' => 'Quiet hours need both a start and an end, or neither.',
            'quiet_hours_end.required_with' => 'Quiet hours need both a start and an end, or neither.',
        ];
    }
}
