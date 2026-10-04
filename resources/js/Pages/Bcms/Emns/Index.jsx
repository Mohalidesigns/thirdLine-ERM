import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import FormField from '@thirdline/ui/Components/FormField';
import InputError from '@thirdline/ui/Components/InputError';
import tryRoute from '@thirdline/ui/lib/tryRoute';
import AudiencePicker from '@/Components/Bcms/AudiencePicker';

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
    audience_options: audienceOptions = {}, occurrence_options: occurrenceOptions = [],
}) {
    const [picked, setPicked] = useState(null);

    const compose = useForm({
        title: '', message: '', template_id: '', severity: 'urgent',
        channels: [], audience_rule: null, occurrence_id: null,
        ack_window_minutes: 30, template_variables: {},
    });

    const choose = (template) => {
        if (template.unavailable_reason) return;

        setPicked(template);
        compose.setData({
            ...compose.data,
            template_id: template.id,
            title: template.name,
            // A SHORT HUMAN SUMMARY, NOT THE TEMPLATE BODY. `message` is a
            // free-text operator note (and the fallback wire body for a
            // template-less alert, `TemplateRenderer::render()`); the real,
            // channel-rendered text — the one with every `{{variable}}`
            // filled in — is what the estimate `preview` below shows, never
            // this field, so prefilling it with the raw templated body would
            // put unrendered placeholders in front of the operator for no
            // reason.
            message: template.name,
            severity: template.severity ?? 'urgent',
            channels: template.default_channel_set ?? [],
            audience_rule: template.default_audience_rule ?? null,
            template_variables: Object.fromEntries(
                (template.operator_variables ?? []).map((v) => [v.name, '']),
            ),
        });
    };

    const setMessage = (value) => {
        compose.setData('message', value);
        if (compose.errors.message) compose.clearErrors('message');
    };

    const setVariable = (name, value) => {
        compose.setData('template_variables', { ...compose.data.template_variables, [name]: value });
        const key = `template_variables.${name}`;
        if (compose.errors[key]) compose.clearErrors(key);
    };

    const setOccurrence = (rawValue) => {
        const occurrenceId = rawValue === '' ? null : Number(rawValue);
        compose.setData('occurrence_id', occurrenceId);
        // An audience already set to "exercise participants" on the OLD
        // occurrence must not silently keep pointing at it once the operator
        // picks a different one, or clears the picker — `id` here is the
        // occurrence's own id (`AudienceResolver::byOccurrence()`), so it is
        // only ever meaningful for the occurrence that produced it.
        if (compose.data.audience_rule?.type === 'occurrence_participants') {
            compose.setData('audience_rule', occurrenceId ? { type: 'occurrence_participants', id: occurrenceId } : null);
        }
    };

    const submit = (e) => {
        e.preventDefault();
        // `message` is `required` server-side (`StoreBcmsAlertRequest`,
        // `errors.message`) — this guard puts the identical refusal in
        // front of the operator immediately, without a round trip.
        if (!compose.data.message.trim()) {
            compose.setError('message', 'Enter a message — this is what an alert with no template sends, and what every operator sees as the summary.');
            return;
        }

        compose.post(tryRoute('bcms.alerts.store'));
    };

    const toggleChannel = (value) => {
        const next = compose.data.channels.includes(value)
            ? compose.data.channels.filter((c) => c !== value)
            : [...compose.data.channels, value];
        compose.setData('channels', next);
    };

    const liveChannels = channels.filter((c) => !c.is_mock);
    const selectedOccurrence = compose.data.occurrence_id
        ? occurrenceOptions.find((o) => o.id === compose.data.occurrence_id)
        : null;
    const operatorVariables = picked?.operator_variables ?? [];
    const derivedVariables = picked?.derived_variables ?? [];

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
                        message and dispatch nothing.{' '}
                        {liveChannels.length === 0
                            ? 'No channel will actually reach anybody yet.'
                            : `Only ${liveChannels.map((c) => c.label).join(', ')} will actually reach anybody.`}
                    </p>
                </div>
            )}

            <div className="grid gap-4 lg:grid-cols-3">
                <section className="rounded border border-slate-200 bg-white p-4">
                    <h2 className="mb-3 text-sm font-semibold text-slate-700">Scenario</h2>
                    <InputError message={compose.errors.template_id} role="alert" className="mb-2" />
                    <ul className="space-y-1">
                        {templates.map((t) => (
                            <li key={t.id}>
                                <button type="button" onClick={() => choose(t)}
                                    disabled={!!t.unavailable_reason}
                                    aria-disabled={!!t.unavailable_reason}
                                    className={`w-full rounded border px-3 py-2 text-left text-sm transition ${
                                        t.unavailable_reason
                                            ? 'cursor-not-allowed border-slate-200 opacity-60'
                                            : picked?.id === t.id
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
                                    {t.unavailable_reason && (
                                        <span className="mt-1 block text-[11px] text-rose-700">{t.unavailable_reason}</span>
                                    )}
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
                            <FormField label="Title" required error={compose.errors.title}>
                                <input value={compose.data.title} onChange={(e) => compose.setData('title', e.target.value)}
                                    className="form-input" required />
                            </FormField>

                            <FormField label="Message" required error={compose.errors.message}
                                hint={picked ? 'A short operator summary — not what is sent. The exact wording per channel, with every variable filled in, is on the Estimate preview below.' : undefined}>
                                <textarea value={compose.data.message} rows={4}
                                    onChange={(e) => setMessage(e.target.value)}
                                    className="form-textarea" />
                            </FormField>

                            {operatorVariables.length > 0 && (
                                <div className="space-y-3 rounded border border-slate-200 bg-slate-50 p-3">
                                    <p className="text-xs font-semibold text-slate-700">
                                        {picked.name} needs the following filled in before it can be sent:
                                    </p>
                                    {operatorVariables.map((v) => {
                                        const val = compose.data.template_variables[v.name] ?? '';
                                        return (
                                            <FormField key={v.name} label={v.label} required={v.required}
                                                error={compose.errors[`template_variables.${v.name}`]}
                                                hint={`${val.length}/${v.max} characters`}>
                                                <input value={val} maxLength={v.max}
                                                    onChange={(e) => setVariable(v.name, e.target.value)}
                                                    className="form-input" />
                                            </FormField>
                                        );
                                    })}
                                </div>
                            )}

                            {derivedVariables.length > 0 && (
                                <div className="rounded border border-slate-200 bg-slate-50 p-3">
                                    <p className="text-xs font-semibold text-slate-700">Filled in automatically</p>
                                    <ul className="mt-1 list-disc space-y-0.5 pl-4 text-xs text-slate-600">
                                        {derivedVariables.map((v) => <li key={v.name}>{v.label}</li>)}
                                    </ul>
                                    <p className="mt-2 text-[11px] text-slate-500">
                                        Not form fields — these are worked out when the alert is estimated or sent, never typed in here.
                                    </p>
                                </div>
                            )}

                            <FormField label="Severity">
                                <select value={compose.data.severity} onChange={(e) => compose.setData('severity', e.target.value)}
                                    className="form-select">
                                    {severities.map((s) => (
                                        <option key={s.value} value={s.value}>
                                            {s.label}{s.respects_quiet_hours ? ' — held during quiet hours' : ' — bypasses quiet hours'}
                                        </option>
                                    ))}
                                </select>
                            </FormField>

                            <FormField label="Exercise (optional)" error={compose.errors.occurrence_id}
                                hint="Link this alert to a drill so it defaults to a simulation and reaches nobody outside the sandbox.">
                                <select value={compose.data.occurrence_id ?? ''} onChange={(e) => setOccurrence(e.target.value)}
                                    className="form-select">
                                    <option value="">Not linked to an exercise</option>
                                    {occurrenceOptions.map((o) => (
                                        <option key={o.id} value={o.id}>{o.name}{o.scheduled_date ? ` — ${o.scheduled_date}` : ''}</option>
                                    ))}
                                </select>
                            </FormField>

                            {selectedOccurrence && (
                                <p role="status" className="rounded border-2 border-violet-300 bg-violet-50 p-2 text-xs font-semibold text-violet-900">
                                    SIMULATION — nothing will be sent externally.
                                </p>
                            )}

                            <AudiencePicker
                                idPrefix="compose-audience"
                                legend="Audience"
                                options={audienceOptions}
                                value={compose.data.audience_rule}
                                onChange={(rule) => compose.setData('audience_rule', rule)}
                                error={compose.errors.audience_rule}
                                occurrence={selectedOccurrence ? { id: selectedOccurrence.id, name: selectedOccurrence.name } : null}
                            />

                            <FormField label="Channels" hint="A life-safety dispatch should include at least one channel that works with no data connection: SMS, voice or USSD.">
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
                            </FormField>

                            <p className="rounded bg-slate-50 p-2 text-[11px] text-slate-600">
                                Anything above <strong>{dual_approval.severity}</strong> severity, or reaching more
                                than <strong>{dual_approval.recipients}</strong> people, needs a second authoriser
                                before it can be dispatched.
                            </p>

                            <button type="submit" disabled={compose.processing}
                                className="w-full rounded bg-slate-800 py-2 text-sm text-white hover:bg-slate-700">
                                Create alert
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
