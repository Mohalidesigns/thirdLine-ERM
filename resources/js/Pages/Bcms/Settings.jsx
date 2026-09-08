import { Head, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * BCMS tenant settings — Gate G0 acceptance criterion 4.
 *
 * TWO THINGS ON THIS SCREEN ARE NOT PREFERENCES AND ARE LABELLED AS SUCH.
 * Quiet hours never apply to critical or life-safety traffic, and the
 * life-safety channel set must include a channel that works with no data
 * connection. Both are enforced server-side — the second by a rule in
 * UpdateBcmsSettingsRequest — and both are said here because a setting whose
 * limits are invisible is a setting somebody will believe they changed.
 *
 * THE CHANNEL LIST SHOWS WHICH ADAPTERS ARE STILL PHASE 0 MOCKS. An operator
 * has to know that before an emergency rather than during one.
 */
export default function Settings({ settings, channels = [], timezones = [] }) {
    const form = useForm({
        timezone: settings.timezone,
        default_lead_time_days: settings.default_lead_time_days,
        reminder_send_time: settings.reminder_send_time,
        default_reminder_mode: settings.default_reminder_mode,
        quiet_hours_start: settings.quiet_hours_start ?? '',
        quiet_hours_end: settings.quiet_hours_end ?? '',
        escalation_day_offset: settings.escalation_day_offset,
        default_channel_set: settings.default_channel_set ?? [],
        life_safety_channel_set: settings.life_safety_channel_set ?? [],
        ai_enabled: settings.ai_enabled,
        exercise_simulation_default: settings.exercise_simulation_default,
        require_dual_approval_for_live: settings.require_dual_approval_for_live,
        alert_currency: settings.alert_currency,
        contact_verification_days: settings.contact_verification_days,
    });

    const toggleChannel = (field, key) => {
        const current = form.data[field] ?? [];
        form.setData(field, current.includes(key) ? current.filter((c) => c !== key) : [...current, key]);
    };

    const submit = (event) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            quiet_hours_start: data.quiet_hours_start === '' ? null : data.quiet_hours_start,
            quiet_hours_end: data.quiet_hours_end === '' ? null : data.quiet_hours_end,
        })).put(tryRoute('bcms.settings.update'), { preserveScroll: true });
    };

    const error = (field) => (form.errors[field] ? <p className="mt-1 text-xs text-red-600">{form.errors[field]}</p> : null);

    return (
        <AppLayout title="BCMS settings">
            <Head title="BCMS settings" />

            <PageHeader
                title="BCMS settings"
                subtitle="How this organisation is reminded, alerted and escalated to."
            />

            <form onSubmit={submit} className="max-w-3xl space-y-8">
                <section className="rounded-lg border border-gray-200 bg-white p-6">
                    <h2 className="text-sm font-semibold text-gray-900">Reminders</h2>
                    <p className="mt-1 text-xs text-gray-500">
                        The countdown before an exercise. One digest per person per day is the default because alert
                        fatigue, not missed alerts, is what stops people reading them.
                    </p>

                    <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <label className="block text-sm">
                            <span className="text-gray-700">Time zone</span>
                            <select
                                className="mt-1 w-full rounded border-gray-300 text-sm"
                                value={form.data.timezone}
                                onChange={(e) => form.setData('timezone', e.target.value)}
                            >
                                {timezones.map((tz) => (
                                    <option key={tz} value={tz}>{tz}</option>
                                ))}
                            </select>
                            {error('timezone')}
                        </label>

                        <label className="block text-sm">
                            <span className="text-gray-700">Countdown length (days before an exercise)</span>
                            <input
                                type="number"
                                className="mt-1 w-full rounded border-gray-300 text-sm"
                                value={form.data.default_lead_time_days}
                                onChange={(e) => form.setData('default_lead_time_days', e.target.value)}
                            />
                            {error('default_lead_time_days')}
                        </label>

                        <label className="block text-sm">
                            <span className="text-gray-700">Reminder send time (local)</span>
                            <input
                                type="time"
                                className="mt-1 w-full rounded border-gray-300 text-sm"
                                value={form.data.reminder_send_time}
                                onChange={(e) => form.setData('reminder_send_time', e.target.value)}
                            />
                            {error('reminder_send_time')}
                        </label>

                        <label className="block text-sm">
                            <span className="text-gray-700">Reminder style</span>
                            <select
                                className="mt-1 w-full rounded border-gray-300 text-sm"
                                value={form.data.default_reminder_mode}
                                onChange={(e) => form.setData('default_reminder_mode', e.target.value)}
                            >
                                <option value="digest">One digest per person per day</option>
                                <option value="discrete">A separate message per exercise</option>
                            </select>
                            {error('default_reminder_mode')}
                        </label>

                        <label className="block text-sm">
                            <span className="text-gray-700">Escalate blocking tasks (days before)</span>
                            <input
                                type="number"
                                className="mt-1 w-full rounded border-gray-300 text-sm"
                                value={form.data.escalation_day_offset}
                                onChange={(e) => form.setData('escalation_day_offset', e.target.value)}
                            />
                            <span className="mt-1 block text-xs text-gray-500">Negative — two days before means −2.</span>
                            {error('escalation_day_offset')}
                        </label>
                    </div>
                </section>

                <section className="rounded-lg border border-gray-200 bg-white p-6">
                    <h2 className="text-sm font-semibold text-gray-900">Quiet hours</h2>
                    <p className="mt-1 text-xs text-gray-500">
                        Routine reminders are held until the window closes. Quiet hours never apply to critical or
                        life-safety alerts — those go out immediately, whatever is set here. Leave both blank for none.
                    </p>

                    <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <label className="block text-sm">
                            <span className="text-gray-700">From</span>
                            <input
                                type="time"
                                className="mt-1 w-full rounded border-gray-300 text-sm"
                                value={form.data.quiet_hours_start}
                                onChange={(e) => form.setData('quiet_hours_start', e.target.value)}
                            />
                            {error('quiet_hours_start')}
                        </label>
                        <label className="block text-sm">
                            <span className="text-gray-700">Until</span>
                            <input
                                type="time"
                                className="mt-1 w-full rounded border-gray-300 text-sm"
                                value={form.data.quiet_hours_end}
                                onChange={(e) => form.setData('quiet_hours_end', e.target.value)}
                            />
                            {error('quiet_hours_end')}
                        </label>
                    </div>
                </section>

                <section className="rounded-lg border border-gray-200 bg-white p-6">
                    <h2 className="text-sm font-semibold text-gray-900">Channels</h2>
                    <p className="mt-1 text-xs text-gray-500">
                        Life-safety alerts must include at least one channel that works with no data connection: SMS,
                        voice or USSD. The form will refuse a set without one.
                    </p>

                    <div className="mt-4 grid grid-cols-1 gap-6 sm:grid-cols-2">
                        {[
                            ['default_channel_set', 'Routine alerts and reminders'],
                            ['life_safety_channel_set', 'Life-safety alerts'],
                        ].map(([field, label]) => (
                            <fieldset key={field}>
                                <legend className="text-sm text-gray-700">{label}</legend>
                                <div className="mt-2 space-y-1">
                                    {channels.map((channel) => (
                                        <label key={channel.key} className="flex items-center gap-2 text-sm">
                                            <input
                                                type="checkbox"
                                                checked={(form.data[field] ?? []).includes(channel.key)}
                                                onChange={() => toggleChannel(field, channel.key)}
                                            />
                                            <span className="text-gray-800">{channel.label}</span>
                                            {channel.offline_capable && (
                                                <span className="rounded bg-emerald-50 px-1.5 py-0.5 text-[11px] text-emerald-700">
                                                    works offline
                                                </span>
                                            )}
                                            {channel.is_mock && (
                                                <span className="rounded bg-amber-50 px-1.5 py-0.5 text-[11px] text-amber-800">
                                                    test mode
                                                </span>
                                            )}
                                        </label>
                                    ))}
                                </div>
                                {error(field)}
                            </fieldset>
                        ))}
                    </div>
                </section>

                <section className="rounded-lg border border-gray-200 bg-white p-6">
                    <h2 className="text-sm font-semibold text-gray-900">Safeguards</h2>

                    <div className="mt-4 space-y-3 text-sm">
                        <label className="flex items-start gap-2">
                            <input
                                type="checkbox"
                                className="mt-1"
                                checked={form.data.exercise_simulation_default}
                                onChange={(e) => form.setData('exercise_simulation_default', e.target.checked)}
                            />
                            <span>
                                <span className="text-gray-800">Alerts from an exercise are simulations by default</span>
                                <span className="block text-xs text-gray-500">
                                    Every message carries a &ldquo;THIS IS AN EXERCISE&rdquo; prefix. Turning this off
                                    means a drill can send a real evacuation order.
                                </span>
                            </span>
                        </label>

                        <label className="flex items-start gap-2">
                            <input
                                type="checkbox"
                                className="mt-1"
                                checked={form.data.require_dual_approval_for_live}
                                onChange={(e) => form.setData('require_dual_approval_for_live', e.target.checked)}
                            />
                            <span>
                                <span className="text-gray-800">A live send from an exercise needs two approvers</span>
                                <span className="block text-xs text-gray-500">
                                    The second person is a different person. This is the last control before ten
                                    thousand handsets.
                                </span>
                            </span>
                        </label>

                        <label className="flex items-start gap-2">
                            <input
                                type="checkbox"
                                className="mt-1"
                                checked={form.data.ai_enabled}
                                onChange={(e) => form.setData('ai_enabled', e.target.checked)}
                            />
                            <span>
                                <span className="text-gray-800">Allow AI drafting in this organisation</span>
                                <span className="block text-xs text-gray-500">
                                    Every AI output lands as an editable draft marked as machine-generated. Nothing is
                                    dispatched, approved or submitted to a regulator without a recorded human action.
                                </span>
                            </span>
                        </label>
                    </div>

                    <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <label className="block text-sm">
                            <span className="text-gray-700">Currency for alert costs</span>
                            <input
                                type="text"
                                maxLength={3}
                                className="mt-1 w-full rounded border-gray-300 text-sm uppercase"
                                value={form.data.alert_currency}
                                onChange={(e) => form.setData('alert_currency', e.target.value.toUpperCase())}
                            />
                            {error('alert_currency')}
                        </label>

                        <label className="block text-sm">
                            <span className="text-gray-700">Ask people to confirm their contact details every (days)</span>
                            <input
                                type="number"
                                className="mt-1 w-full rounded border-gray-300 text-sm"
                                value={form.data.contact_verification_days}
                                onChange={(e) => form.setData('contact_verification_days', e.target.value)}
                            />
                            {error('contact_verification_days')}
                        </label>
                    </div>
                </section>

                <div className="flex items-center gap-3">
                    <button type="submit" className="btn-primary text-sm" disabled={form.processing}>
                        {form.processing ? 'Saving…' : 'Save settings'}
                    </button>
                    {form.recentlySuccessful && <span className="text-sm text-emerald-700">Saved.</span>}
                </div>
            </form>
        </AppLayout>
    );
}
