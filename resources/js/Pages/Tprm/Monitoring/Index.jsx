import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';

/**
 * The monitoring console — FR-MON-04.
 *
 * THE SOURCE HEALTH PANEL LEADS WITH WHAT IS *NOT* WATCHED. Three green feeds
 * is reassuring and misleading if the client bought three of a possible seven;
 * the uncovered signal types are the gap an examiner asks about, so they are on
 * the panel rather than inferable from its absence.
 *
 * INTERNAL DERIVATION IS SHOWN AS A SOURCE. It produces nine signal types with
 * no subscription and cannot go down, and a panel that omitted it would tell a
 * client with no data budget that they are monitoring nothing.
 */
export default function Index({
    summary = {}, signals = [], alerts = [], rules = [], sourceHealth = {}, muteRegister = [],
    signalTypes = [], actions = [], filters = {}, can = {},
}) {
    const [tab, setTab] = useState('signals');
    const [addingRule, setAddingRule] = useState(false);
    const [muting, setMuting] = useState(null);

    const tabs = [
        ['signals', `Signals (${signals.length})`],
        ['alerts', `Alerts (${summary.open_alerts ?? 0} open)`],
        ['rules', `Rules (${rules.length})`],
        ['sources', 'Source health'],
        ['mutes', `Mute register (${muteRegister.length})`],
    ];

    return (
        <AppLayout title="Monitoring">
            <Head title="Monitoring" />

            <PageHeader
                title="Monitoring"
                subtitle="What has been observed about the portfolio, and what the rules did about it. Most of this is derived from the register itself and needs no external subscription."
                actions={can.manage ? (
                    <button
                        type="button"
                        className="btn btn-secondary"
                        onClick={() => router.post(route('tprm.monitoring.run'))}
                    >
                        Run now
                    </button>
                ) : null}
            />

            <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <Tile label="Signals (30 days)" value={summary.signals_30d ?? 0}
                    hint={`${summary.internal_30d ?? 0} derived internally`} />
                <Tile label="Open alerts" value={summary.open_alerts ?? 0}
                    tone={summary.open_alerts ? 'warn' : null} hint="awaiting acknowledgement" />
                <Tile label="Critical" value={summary.critical_alerts ?? 0}
                    tone={summary.critical_alerts ? 'critical' : null} hint="open and critical" />
                <Tile label="Muted" value={summary.muted ?? 0} hint="silenced, with an expiry" />
            </div>

            <div className="mb-4 flex flex-wrap gap-1 border-b border-gray-200">
                {tabs.map(([key, label]) => (
                    <button key={key} type="button" onClick={() => setTab(key)}
                        className={`px-3 py-2 text-sm font-medium ${
                            tab === key ? 'border-b-2 border-blue-600 text-blue-700' : 'text-gray-500 hover:text-gray-700'
                        }`}>
                        {label}
                    </button>
                ))}
            </div>

            {tab === 'signals' && <SignalStream signals={signals} signalTypes={signalTypes} filters={filters} />}
            {tab === 'alerts' && (
                <AlertStream alerts={alerts} can={can} onMute={(alert) => setMuting(alert)} />
            )}
            {tab === 'rules' && (
                <RulePanel rules={rules} can={can} onAdd={() => setAddingRule(true)} />
            )}
            {tab === 'sources' && <SourceHealth health={sourceHealth} />}
            {tab === 'mutes' && <MuteRegister rows={muteRegister} />}

            {addingRule && (
                <RuleDialog signalTypes={signalTypes} actions={actions} onClose={() => setAddingRule(false)} />
            )}
            {muting && <MuteDialog alert={muting} onClose={() => setMuting(null)} />}
        </AppLayout>
    );
}

function Tile({ label, value, hint, tone }) {
    return (
        <div className="card p-4">
            <p className="text-xs font-medium uppercase tracking-wide text-gray-500">{label}</p>
            <p className={`mt-1 text-2xl font-semibold ${
                tone === 'critical' ? 'text-red-700' : tone === 'warn' ? 'text-amber-700' : 'text-gray-900'
            }`}>{value}</p>
            <p className="mt-0.5 text-xs text-gray-500">{hint}</p>
        </div>
    );
}

function SignalStream({ signals, signalTypes, filters }) {
    if (signals.length === 0) {
        return (
            <div className="card p-8 text-center text-sm text-gray-500">
                No signals yet. They are derived nightly from the register — expiring evidence, overdue
                assessments, breached service levels — and from any external feed that is configured.
            </div>
        );
    }

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap gap-2">
                {signalTypes.filter((type) => signals.some((signal) => signal.type === type.value))
                    .map((type) => (
                        <button
                            key={type.value}
                            type="button"
                            onClick={() => router.get(route('tprm.monitoring.index'), {
                                ...filters, type: filters.type === type.value ? null : type.value,
                            }, { preserveState: true })}
                            className={`rounded-full border px-3 py-1 text-xs font-medium ${
                                filters.type === type.value
                                    ? 'border-blue-300 bg-blue-50 text-blue-800'
                                    : 'border-gray-200 bg-white text-gray-600'
                            }`}
                        >
                            {type.label}
                            {type.internal && <span className="ml-1 text-gray-400">·internal</span>}
                        </button>
                    ))}
            </div>

            <div className="card divide-y divide-gray-100">
                {signals.map((signal) => (
                    <div key={signal.id} className="p-4">
                        <div className="flex flex-wrap items-start justify-between gap-2">
                            <div className="min-w-0">
                                <p className="text-sm font-medium text-gray-900">{signal.title}</p>
                                <p className="mt-0.5 text-xs text-gray-500">
                                    {signal.type_label}
                                    {' · '}
                                    {signal.third_party ?? 'no third party'}
                                    {signal.engagement ? ` · ${signal.engagement}` : ''}
                                    {' · '}
                                    {signal.observed_at}
                                </p>
                            </div>
                            <div className="flex shrink-0 items-center gap-2">
                                <SeverityChip severity={signal.severity} />
                                <span className="text-[11px] text-gray-400">
                                    {signal.internal ? 'derived here' : signal.source}
                                </span>
                            </div>
                        </div>

                        {signal.ai_materiality && (
                            <p className="mt-1 text-xs text-gray-600">
                                Triage: {signal.ai_materiality}
                                {signal.ai_relevance !== null && ` (relevance ${signal.ai_relevance})`}
                            </p>
                        )}
                    </div>
                ))}
            </div>
        </div>
    );
}

function AlertStream({ alerts, can, onMute }) {
    if (alerts.length === 0) {
        return <div className="card p-8 text-center text-sm text-gray-500">No alerts have fired.</div>;
    }

    return (
        <div className="card divide-y divide-gray-100">
            {alerts.map((alert) => (
                <div key={alert.id} className="p-4">
                    <div className="flex flex-wrap items-start justify-between gap-2">
                        <div className="min-w-0">
                            <p className="text-sm font-medium text-gray-900">{alert.rule}</p>
                            <p className="mt-0.5 text-xs text-gray-600">{alert.signal}</p>
                            <p className="mt-0.5 text-xs text-gray-500">
                                {alert.third_party} · {alert.created_at}
                            </p>
                        </div>
                        <div className="flex shrink-0 items-center gap-2">
                            <SeverityChip severity={alert.severity} />
                            <span className="text-xs text-gray-500">{alert.status_label}</span>
                        </div>
                    </div>

                    {/* What actually happened, per action — not what the rule
                        asked for. A green tick over a half-completed response
                        is worse than a list that says which half failed. */}
                    {alert.actions_taken.length > 0 && (
                        <ul className="mt-2 space-y-0.5 text-xs">
                            {alert.actions_taken.map((taken, index) => (
                                <li key={index} className={taken.done ? 'text-gray-600' : 'text-amber-700'}>
                                    {taken.done ? '✓' : '·'} {taken.action.replace(/_/g, ' ')}
                                    {taken.note ? ` — ${taken.note}` : ''}
                                </li>
                            ))}
                        </ul>
                    )}

                    {alert.muted_until && (
                        <p className="mt-2 text-xs text-gray-500">
                            Muted until {alert.muted_until}: {alert.mute_reason}
                        </p>
                    )}

                    {can.manage && !alert.muted_until && (
                        <div className="mt-3 flex gap-2">
                            <button type="button" className="btn btn-secondary text-xs"
                                onClick={() => router.post(route('tprm.monitoring.alerts.acknowledge', alert.id))}>
                                Acknowledge
                            </button>
                            <button type="button" className="btn btn-secondary text-xs" onClick={() => onMute(alert)}>
                                Mute
                            </button>
                        </div>
                    )}
                </div>
            ))}
        </div>
    );
}

function RulePanel({ rules, can, onAdd }) {
    return (
        <div className="space-y-4">
            {can.manage && (
                <button type="button" className="btn btn-primary text-sm" onClick={onAdd}>Add a rule</button>
            )}

            {rules.length === 0 ? (
                <div className="card p-8 text-center text-sm text-gray-500">
                    No rules yet. A rule decides what a signal means — notify somebody, raise a finding, build a
                    targeted mini-assessment, or stop the engagement.
                </div>
            ) : (
                <div className="card divide-y divide-gray-100">
                    {rules.map((rule) => (
                        <div key={rule.id} className="p-4">
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <p className="text-sm font-medium text-gray-900">
                                        {rule.name}
                                        {!rule.enabled && <span className="ml-2 text-xs text-gray-500">(disabled)</span>}
                                    </p>
                                    <p className="mt-0.5 text-xs text-gray-500">
                                        {rule.watches_everything
                                            ? 'Watches every signal type'
                                            : `Watches ${rule.signal_types.length} signal type(s)`}
                                        {' · '}
                                        {rule.actions.map((action) => action.replace(/_/g, ' ')).join(', ')}
                                        {' · '}
                                        cooldown {rule.cooldown_hours}h
                                    </p>
                                </div>
                                <span className="text-xs text-gray-500">fired {rule.fired}×</span>
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

function SourceHealth({ health }) {
    const uncovered = health.uncovered_signal_types ?? [];

    return (
        <div className="space-y-4">
            {uncovered.length > 0 && (
                <div className="rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    <p className="font-medium">
                        Nothing is currently producing {uncovered.length} signal type(s).
                    </p>
                    <p className="mt-1">
                        These need an external feed. Everything else is derived from the register itself and
                        needs no subscription.
                    </p>
                    <p className="mt-1 text-xs">{uncovered.map((type) => type.label).join(', ')}</p>
                </div>
            )}

            <div className="card p-5">
                <h3 className="text-sm font-semibold text-gray-900">Internal derivation</h3>
                <p className="mt-0.5 text-xs text-gray-600">{health.internal?.note}</p>
                <p className="mt-2 text-xs text-gray-500">
                    {(health.internal?.signal_types ?? []).length} signal type(s) · last derived{' '}
                    {health.internal?.last_run_at ?? 'never'}
                </p>
            </div>

            <div className="card p-5">
                <h3 className="text-sm font-semibold text-gray-900">Sanctions lists</h3>
                <ul className="mt-3 space-y-2">
                    {(health.sanctions_lists ?? []).map((list) => (
                        <li key={list.code} className="text-sm">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <span className="text-gray-900">{list.name}</span>
                                <span className={
                                    list.empty ? 'text-red-700' : list.stale ? 'text-amber-700' : 'text-gray-600'
                                }>
                                    {list.entries} entries
                                    {list.last_refreshed_at ? ` · ${list.last_refreshed_at}` : ' · never refreshed'}
                                </span>
                            </div>
                            {list.note && <p className="mt-0.5 text-xs text-amber-800">{list.note}</p>}
                        </li>
                    ))}
                </ul>
            </div>

            {(health.external ?? []).length > 0 && (
                <div className="card p-5">
                    <h3 className="text-sm font-semibold text-gray-900">External feeds</h3>
                    <ul className="mt-3 space-y-2">
                        {health.external.map((source) => (
                            <li key={source.id} className="flex flex-wrap items-center justify-between gap-2 text-sm">
                                <span className="text-gray-900">{source.name}</span>
                                <span className={source.stale ? 'text-amber-700' : 'text-gray-600'}>
                                    {source.enabled ? (source.last_status ?? 'never run') : 'disabled'}
                                    {source.credentials_set ? '' : ' · no credentials'}
                                    {source.last_run_at ? ` · ${source.last_run_at}` : ''}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}

function MuteRegister({ rows }) {
    if (rows.length === 0) {
        return <div className="card p-8 text-center text-sm text-gray-500">Nothing is muted.</div>;
    }

    return (
        <div className="card p-5">
            <p className="mb-3 text-xs text-gray-600">
                Every silenced alert, with why and until when. A mute with no expiry is how a monitoring
                programme quietly stops covering what it claims to, so there is no way to make one.
            </p>
            <ul className="divide-y divide-gray-100">
                {rows.map((row) => (
                    <li key={row.id} className="py-2.5 text-sm">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <span className="text-gray-900">{row.rule} — {row.third_party}</span>
                            <span className="text-xs text-gray-500">
                                until {row.until} ({row.days_remaining} days)
                            </span>
                        </div>
                        <p className="mt-0.5 text-xs text-gray-600">{row.reason}</p>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function SeverityChip({ severity }) {
    const tone = {
        critical: 'bg-red-100 text-red-800',
        high: 'bg-orange-100 text-orange-800',
        medium: 'bg-amber-100 text-amber-800',
        low: 'bg-gray-100 text-gray-700',
        info: 'bg-blue-100 text-blue-800',
    }[severity] ?? 'bg-gray-100 text-gray-600';

    return <span className={`rounded-full px-2 py-0.5 text-xs font-medium capitalize ${tone}`}>{severity}</span>;
}

function RuleDialog({ signalTypes, actions, onClose }) {
    const form = useForm({
        name: '',
        signal_types: [],
        actions: ['notify'],
        severity: 'medium',
        cooldown_hours: 24,
    });

    const toggle = (list, value) => list.includes(value)
        ? list.filter((entry) => entry !== value)
        : [...list, value];

    const submit = (event) => {
        event.preventDefault();
        form.post(route('tprm.monitoring.rules.store'), { onSuccess: onClose });
    };

    return (
        <div className="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-black/30 p-6">
            <form onSubmit={submit} className="card w-full max-w-2xl p-6">
                <h2 className="text-base font-semibold text-gray-900">Add a monitoring rule</h2>

                <label className="mt-4 block">
                    <span className="text-sm font-medium text-gray-700">Name</span>
                    <input type="text" className="input mt-1" value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)} />
                </label>

                <div className="mt-4">
                    <p className="text-sm font-medium text-gray-700">Signal types</p>
                    <p className="mt-0.5 text-xs text-gray-500">
                        Leave empty to watch every type — a rule that matched nothing would look configured and
                        do nothing.
                    </p>
                    <div className="mt-2 flex flex-wrap gap-1.5">
                        {signalTypes.map((type) => (
                            <button key={type.value} type="button"
                                onClick={() => form.setData('signal_types', toggle(form.data.signal_types, type.value))}
                                className={`rounded-full border px-2.5 py-1 text-xs ${
                                    form.data.signal_types.includes(type.value)
                                        ? 'border-blue-300 bg-blue-50 text-blue-800'
                                        : 'border-gray-200 text-gray-600'
                                }`}>
                                {type.label}
                            </button>
                        ))}
                    </div>
                </div>

                <div className="mt-4">
                    <p className="text-sm font-medium text-gray-700">Actions</p>
                    <div className="mt-2 flex flex-wrap gap-1.5">
                        {actions.map((action) => (
                            <button key={action} type="button"
                                onClick={() => form.setData('actions', toggle(form.data.actions, action))}
                                className={`rounded-full border px-2.5 py-1 text-xs ${
                                    form.data.actions.includes(action)
                                        ? 'border-blue-300 bg-blue-50 text-blue-800'
                                        : 'border-gray-200 text-gray-600'
                                }`}>
                                {action.replace(/_/g, ' ')}
                            </button>
                        ))}
                    </div>
                </div>

                <div className="mt-4 grid grid-cols-2 gap-4">
                    <label className="block">
                        <span className="text-sm font-medium text-gray-700">Severity</span>
                        <select className="input mt-1" value={form.data.severity}
                            onChange={(event) => form.setData('severity', event.target.value)}>
                            <option value="critical">Critical</option>
                            <option value="high">High</option>
                            <option value="medium">Medium</option>
                            <option value="low">Low</option>
                            <option value="info">Informational</option>
                        </select>
                    </label>

                    <label className="block">
                        <span className="text-sm font-medium text-gray-700">Cooldown (hours)</span>
                        <input type="number" className="input mt-1" value={form.data.cooldown_hours}
                            onChange={(event) => form.setData('cooldown_hours', event.target.value)} />
                        <p className="mt-1 text-xs text-gray-500">
                            Per rule per vendor. A console full of yesterday&rsquo;s alert is one nobody opens.
                        </p>
                    </label>
                </div>

                <div className="mt-6 flex justify-end gap-2">
                    <button type="button" className="btn btn-secondary" onClick={onClose}>Cancel</button>
                    <button type="submit" className="btn btn-primary" disabled={form.processing}>Create</button>
                </div>
            </form>
        </div>
    );
}

function MuteDialog({ alert, onClose }) {
    const form = useForm({ mute_reason: '', muted_until: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('tprm.monitoring.alerts.mute', alert.id), { onSuccess: onClose });
    };

    return (
        <div className="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-black/30 p-6">
            <form onSubmit={submit} className="card w-full max-w-xl p-6">
                <h2 className="text-base font-semibold text-gray-900">Mute this alert</h2>
                <p className="mt-1 text-sm text-gray-600">
                    Both a reason and an expiry are required. A mute with neither is how a monitoring programme
                    quietly stops covering what it claims to — somebody silences a noisy rule during an
                    incident, and two years later nobody knows why that vendor produces no alerts.
                </p>

                <label className="mt-4 block">
                    <span className="text-sm font-medium text-gray-700">Why?</span>
                    <textarea rows={3} className="input mt-1" value={form.data.mute_reason}
                        onChange={(event) => form.setData('mute_reason', event.target.value)} />
                    {form.errors.mute_reason && <p className="mt-1 text-xs text-red-600">{form.errors.mute_reason}</p>}
                </label>

                <label className="mt-4 block">
                    <span className="text-sm font-medium text-gray-700">Until</span>
                    <input type="date" className="input mt-1" value={form.data.muted_until}
                        onChange={(event) => form.setData('muted_until', event.target.value)} />
                    {form.errors.muted_until && <p className="mt-1 text-xs text-red-600">{form.errors.muted_until}</p>}
                </label>

                <div className="mt-6 flex justify-end gap-2">
                    <button type="button" className="btn btn-secondary" onClick={onClose}>Cancel</button>
                    <button type="submit" className="btn btn-primary" disabled={form.processing}>Mute</button>
                </div>
            </form>
        </div>
    );
}
