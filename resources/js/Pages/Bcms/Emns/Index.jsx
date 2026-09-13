import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * The EMNS console — the crisis screen.
 *
 * DESIGNED TO BE OPERATED UNDER STRESS BY SOMEBODY WHO HAS NOT USED IT IN SIX
 * MONTHS. That is the phase brief's requirement and it drives the layout: pick
 * a scenario, see who it reaches and what it costs, press one button. No tabs,
 * no wizard, no settings the operator has to remember.
 *
 * MOCKED CHANNELS ARE CALLED OUT AT THE TOP IN AMBER, not in a tooltip. A
 * crisis manager who believes an SMS went out when the adapter recorded it and
 * dispatched nothing is the worst failure this module can have.
 *
 * A LIFE-SAFETY TEMPLATE IS VISIBLY DIFFERENT from an advisory one before it is
 * selected, because the difference decides whether quiet hours are bypassed and
 * whether a second signature is needed, and finding that out after clicking is
 * a second decision under pressure.
 */
export default function Index({
    templates = [], channels = [], severities = [], saved_groups = [], recent = [], drafts = [],
    dual_approval = {}, any_channel_mocked = false, can = {},
}) {
    const [picked, setPicked] = useState(null);

    const compose = useForm({
        title: '', message: '', template_id: '', severity: 'urgent',
        channels: [], audience_rule: null, ack_window_minutes: 30,
    });

    const choose = (template) => {
        setPicked(template);
        compose.setData({
            ...compose.data,
            template_id: template.id,
            title: template.name,
            message: template.body,
            severity: template.severity ?? 'urgent',
            channels: template.default_channel_set ?? [],
            audience_rule: template.default_audience_rule ?? null,
        });
    };

    const submit = (e) => {
        e.preventDefault();
        compose.post(tryRoute('bcms.alerts.store'));
    };

    const toggleChannel = (value) => {
        const next = compose.data.channels.includes(value)
            ? compose.data.channels.filter((c) => c !== value)
            : [...compose.data.channels, value];
        compose.setData('channels', next);
    };

    const liveChannels = channels.filter((c) => !c.is_mock);

    return (
        <AppLayout>
            <Head title="Emergency notification" />

            <PageHeader
                title="Emergency notification"
                subtitle="Reach everybody who needs to know, on the channels that still work — ISO 22301 clause 8.4.3."
                actions={(
                    <div className="flex gap-2">
                        <Link href={tryRoute('bcms.alert-templates.index')}
                            className="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">
                            Templates
                        </Link>
                        <Link href={tryRoute('bcms.providers.index')}
                            className="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">
                            Providers &amp; spend
                        </Link>
                    </div>
                )}
            />

            {any_channel_mocked && (
                <div className="mb-4 rounded border-2 border-amber-300 bg-amber-50 p-3">
                    <p className="text-sm font-semibold text-amber-900">
                        Some channels are not live yet.
                    </p>
                    <p className="mt-1 text-xs text-amber-800">
                        {channels.filter((c) => c.is_mock).map((c) => c.label).join(', ')} will record a
                        message and dispatch nothing. Only {liveChannels.length === 0 ? 'no channels are' : liveChannels.map((c) => c.label).join(', ')+' will'} actually
                        reach anybody.
                    </p>
                </div>
            )}

            <div className="grid gap-4 lg:grid-cols-3">
                <section className="rounded border border-slate-200 bg-white p-4">
                    <h2 className="mb-3 text-sm font-semibold text-slate-700">Scenario</h2>
                    <ul className="space-y-1">
                        {templates.map((t) => (
                            <li key={t.id}>
                                <button type="button" onClick={() => choose(t)}
                                    className={`w-full rounded border px-3 py-2 text-left text-sm transition ${
                                        picked?.id === t.id
                                            ? 'border-slate-500 bg-slate-50'
                                            : t.is_life_safety
                                                ? 'border-rose-200 hover:border-rose-300'
                                                : 'border-slate-200 hover:border-slate-300'
                                    }`}>
                                    <span className="font-medium text-slate-800">{t.name}</span>
                                    <span className="mt-0.5 flex flex-wrap items-center gap-2 text-[11px] text-slate-500">
                                        {t.is_life_safety && (
                                            <span className="rounded bg-rose-600 px-1.5 py-0.5 font-semibold text-white">
                                                life safety
                                            </span>
                                        )}
                                        {t.requires_dual_approval && <span className="text-amber-700">needs 2 approvers</span>}
                                        <span>{t.live_locales} of 5 languages</span>
                                        <span>{t.sms?.segments} SMS</span>
                                    </span>
                                </button>
                            </li>
                        ))}
                        {templates.length === 0 && (
                            <li className="py-6 text-center text-sm text-slate-500">
                                No active templates. Author one in the template library first.
                            </li>
                        )}
                    </ul>
                </section>

                <section className="rounded border border-slate-200 bg-white p-4 lg:col-span-2">
                    <h2 className="mb-3 text-sm font-semibold text-slate-700">Compose</h2>

                    {!can.compose ? (
                        <p className="text-sm text-slate-500">
                            You can see alerts but not compose them.
                        </p>
                    ) : (
                        <form onSubmit={submit} className="space-y-3">
                            <label className="block">
                                <span className="mb-1 block text-xs font-medium text-slate-600">Title</span>
                                <input value={compose.data.title} onChange={(e) => compose.setData('title', e.target.value)}
                                    className="w-full rounded border-slate-300 text-sm" required />
                                {compose.errors.title && <span className="text-xs text-rose-600">{compose.errors.title}</span>}
                            </label>

                            <label className="block">
                                <span className="mb-1 block text-xs font-medium text-slate-600">Message</span>
                                <textarea value={compose.data.message} rows={4}
                                    onChange={(e) => compose.setData('message', e.target.value)}
                                    className="w-full rounded border-slate-300 text-sm" />
                            </label>

                            <label className="block">
                                <span className="mb-1 block text-xs font-medium text-slate-600">Severity</span>
                                <select value={compose.data.severity} onChange={(e) => compose.setData('severity', e.target.value)}
                                    className="w-full rounded border-slate-300 text-sm">
                                    {severities.map((s) => (
                                        <option key={s.value} value={s.value}>
                                            {s.label}{s.respects_quiet_hours ? ' — held during quiet hours' : ' — bypasses quiet hours'}
                                        </option>
                                    ))}
                                </select>
                            </label>

                            <div>
                                <span className="mb-1 block text-xs font-medium text-slate-600">Channels</span>
                                <div className="flex flex-wrap gap-2">
                                    {channels.map((c) => (
                                        <button key={c.channel} type="button" onClick={() => toggleChannel(c.channel)}
                                            className={`rounded border px-2 py-1 text-xs ${
                                                compose.data.channels.includes(c.channel)
                                                    ? 'border-slate-600 bg-slate-800 text-white'
                                                    : 'border-slate-300 hover:bg-slate-50'
                                            }`}>
                                            {c.label}
                                            {c.is_mock && <span className="ml-1 opacity-70">(not live)</span>}
                                        </button>
                                    ))}
                                </div>
                                <p className="mt-1 text-[11px] text-slate-500">
                                    A life-safety dispatch should include at least one channel that works with no
                                    data connection: SMS, voice or USSD.
                                </p>
                            </div>

                            <p className="rounded bg-slate-50 p-2 text-[11px] text-slate-600">
                                Anything above <strong>{dual_approval.severity}</strong> severity, or reaching more
                                than <strong>{dual_approval.recipients}</strong> people, needs a second authoriser
                                before it can be dispatched.
                            </p>

                            <button type="submit" disabled={compose.processing}
                                className="w-full rounded bg-slate-800 py-2 text-sm text-white hover:bg-slate-700">
                                Create alert and choose the audience
                            </button>
                        </form>
                    )}
                </section>
            </div>

            {(drafts.length > 0 || recent.length > 0) && (
                <div className="mt-6 grid gap-4 lg:grid-cols-2">
                    <AlertList title="Awaiting dispatch" alerts={drafts} empty="Nothing waiting." />
                    <AlertList title="Recently dispatched" alerts={recent} empty="Nothing sent yet." />
                </div>
            )}
        </AppLayout>
    );
}

function AlertList({ title, alerts, empty }) {
    return (
        <section className="rounded border border-slate-200 bg-white p-4">
            <h2 className="mb-2 text-sm font-semibold text-slate-700">{title}</h2>
            {alerts.length === 0 ? (
                <p className="text-sm text-slate-500">{empty}</p>
            ) : (
                <ul className="divide-y divide-slate-100 text-sm">
                    {alerts.map((a) => (
                        <li key={a.uuid} className="flex items-center justify-between py-2">
                            <div className="min-w-0">
                                <Link href={tryRoute('bcms.alerts.show', a.uuid)}
                                    className="font-medium text-slate-800 hover:underline">
                                    {a.title}
                                </Link>
                                <div className="text-[11px] text-slate-500">
                                    {a.severity.replace(/_/g, ' ')}
                                    {a.is_simulation && ' · simulation'}
                                    {a.dispatched_at && ` · ${a.dispatched_at.slice(0, 16).replace('T', ' ')}`}
                                </div>
                            </div>
                            <span className="shrink-0 text-[11px] text-slate-500">
                                {a.recipient_count > 0 ? `${a.recipient_count} recipients` : a.status}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
