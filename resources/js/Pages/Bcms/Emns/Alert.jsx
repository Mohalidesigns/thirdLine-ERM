import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * One alert: the dispatch decision before it goes, the live roll-call after.
 *
 * THE UNACCOUNTED-FOR NUMBER IS THE LARGEST THING ON THE SCREEN once dispatch
 * has happened. During an evacuation there is exactly one question — who is
 * still inside — and delivery funnels are for the after-action report.
 *
 * "SILENT" AND "UNREACHABLE" ARE NEVER MERGED. Somebody whose phone rang and
 * who has not answered may be carrying a colleague down a stairwell; somebody
 * whose number was wrong was never called. Both are unaccounted for, they need
 * different people to act, and one bucket sends the fire warden to the wrong
 * floor.
 *
 * THE DISPATCH BUTTON IS BIG, RED AND HONEST about what it will do — including
 * refusing to be pressed when a second authoriser is still needed, rather than
 * being hidden.
 */
export default function Alert({ alert = {}, roll_call = null, channels = [], mocked_channels = [], can = {} }) {
    const [state, setState] = useState({ alert, roll_call });
    const [estimate, setEstimate] = useState(null);
    const [estimating, setEstimating] = useState(false);

    const running = state.alert.dispatched_at && (state.roll_call?.unaccounted_for ?? 0) > 0;

    useEffect(() => {
        if (!state.alert.dispatched_at) return undefined;

        const id = setInterval(() => {
            fetch(tryRoute('bcms.alerts.live', alert.uuid), { headers: { Accept: 'application/json' } })
                .then((r) => (r.ok ? r.json() : null))
                .then((d) => d && setState(d))
                .catch(() => {});
        }, 5000);

        return () => clearInterval(id);
    }, [state.alert.dispatched_at, alert.uuid]);

    // A plain fetch rather than an Inertia visit: the estimate is a number to
    // read, not a page to navigate to, and re-rendering the console under
    // somebody who is mid-decision is exactly what this screen must not do.
    const runEstimate = () => {
        setEstimating(true);
        fetch(tryRoute('bcms.alerts.estimate', alert.uuid), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
        })
            .then((r) => (r.ok ? r.json() : null))
            .then((d) => d && setEstimate(d))
            .catch(() => {})
            .finally(() => setEstimating(false));
    };

    const a = state.alert;
    const rc = state.roll_call;

    return (
        <AppLayout>
            <Head title={a.title} />

            <PageHeader
                title={a.title}
                subtitle={`${a.severity?.replace(/_/g, ' ')}${a.is_simulation ? ' · SIMULATION' : ''}${
                    a.dispatched_at ? ` · dispatched ${a.dispatched_at.slice(0, 16).replace('T', ' ')}` : ' · not yet dispatched'
                }`}
                actions={(
                    <div className="flex gap-2">
                        <Link href={tryRoute('bcms.emns.index')}
                            className="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">
                            Console
                        </Link>
                        {can.export && a.dispatched_at && (
                            <a href={tryRoute('bcms.alerts.evidence', a.uuid)}
                                className="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">
                                Evidence export
                            </a>
                        )}
                    </div>
                )}
            />

            {a.is_simulation && (
                <div className="mb-4 rounded border-2 border-violet-300 bg-violet-50 p-3 text-sm text-violet-900">
                    <strong>Simulation.</strong> Every message carries the “THIS IS AN EXERCISE” prefix and
                    nothing is dispatched externally. The per-recipient record below is real, so you see
                    exactly what a live dispatch would produce.
                </div>
            )}

            {mocked_channels.length > 0 && !a.is_simulation && (
                <div className="mb-4 rounded border-2 border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                    <strong>{mocked_channels.join(', ')}</strong> {mocked_channels.length === 1 ? 'is' : 'are'} not
                    live yet — those messages will be recorded and not sent.
                </div>
            )}

            {!a.dispatched_at ? (
                <div className="grid gap-4 lg:grid-cols-3">
                    <section className="rounded border border-slate-200 bg-white p-4 lg:col-span-2">
                        <h2 className="mb-2 text-sm font-semibold text-slate-700">Before you send</h2>
                        <p className="mb-3 whitespace-pre-wrap rounded bg-slate-50 p-3 text-sm text-slate-700">
                            {a.message}
                        </p>

                        <button type="button" onClick={runEstimate} disabled={estimating}
                            className="mb-3 rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">
                            {estimating ? 'Working out who this reaches…' : 'Estimate reach and cost'}
                        </button>

                        {estimate && (
                            <dl className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                                <Figure label="Recipients" value={estimate.recipients} />
                                <Figure label="Reachable" value={estimate.reachable} />
                                <Figure label="No usable channel" value={estimate.unreachable}
                                    tone={estimate.unreachable > 0 ? 'text-rose-600' : ''} />
                                <Figure label="Estimated cost"
                                    value={estimate.estimated_cost_minor === null
                                        ? 'not priced'
                                        : `${estimate.currency} ${(estimate.estimated_cost_minor / 100).toFixed(2)}`} />
                            </dl>
                        )}

                        {estimate && !estimate.offline_capable && (
                            <p className="mt-3 rounded bg-amber-50 p-2 text-xs text-amber-800">
                                None of the selected channels works without a data connection. In a real
                                emergency the network is usually the first thing to fail — consider adding
                                SMS, voice or USSD.
                            </p>
                        )}
                    </section>

                    <section className="rounded border border-slate-200 bg-white p-4">
                        <h2 className="mb-2 text-sm font-semibold text-slate-700">Dispatch</h2>

                        {a.held_by_quiet_hours && (
                            <p className="mb-3 rounded bg-amber-50 p-2 text-xs text-amber-800">
                                It is currently quiet hours and this severity respects them. Life-safety and
                                critical traffic is never held.
                            </p>
                        )}

                        {a.requires_dual_approval && (
                            <div className="mb-3 rounded bg-slate-50 p-2 text-xs text-slate-700">
                                <p className="font-medium">This alert needs two authorisers.</p>
                                <p className="mt-1">
                                    First: {a.approved_by ?? <span className="text-rose-600">outstanding</span>}
                                    {' · '}
                                    Second: {a.second_approved_at ? 'recorded' : <span className="text-rose-600">outstanding</span>}
                                </p>
                                {can.approve && (
                                    <button type="button"
                                        onClick={() => router.post(tryRoute('bcms.alerts.approve', a.uuid), {}, { preserveScroll: true })}
                                        className="mt-2 w-full rounded border border-slate-300 py-1.5 hover:bg-white">
                                        Record my approval
                                    </button>
                                )}
                            </div>
                        )}

                        {can.dispatch ? (
                            <button type="button"
                                disabled={!a.is_dispatchable || a.held_by_quiet_hours}
                                onClick={() => {
                                    if (window.confirm(
                                        `Send "${a.title}" to ${a.recipient_count || 'the resolved'} recipients?`
                                        + (a.is_simulation ? '\n\nThis is a SIMULATION — nothing leaves the building.' : '\n\nThis is LIVE.')
                                    )) {
                                        router.post(tryRoute('bcms.alerts.dispatch', a.uuid));
                                    }
                                }}
                                className={`w-full rounded py-4 text-base font-semibold text-white ${
                                    a.is_dispatchable && !a.held_by_quiet_hours
                                        ? (a.is_simulation ? 'bg-violet-700 hover:bg-violet-600' : 'bg-rose-700 hover:bg-rose-600')
                                        : 'cursor-not-allowed bg-slate-300'
                                }`}>
                                {a.is_simulation ? 'Release simulation' : 'DISPATCH'}
                            </button>
                        ) : (
                            <p className="text-sm text-slate-500">You do not hold the dispatch permission.</p>
                        )}

                        {!a.is_dispatchable && (
                            <p className="mt-2 text-[11px] text-slate-500">
                                Blocked until the second authoriser has signed.
                            </p>
                        )}
                    </section>
                </div>
            ) : (
                <>
                    <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-5">
                        <Big label="Unaccounted for" value={rc?.unaccounted_for ?? 0}
                            tone={(rc?.unaccounted_for ?? 0) > 0 ? 'text-rose-700' : 'text-emerald-700'} />
                        <Big label="Safe" value={rc?.safe ?? 0} tone="text-emerald-700" />
                        <Big label="Need help" value={rc?.needs_help ?? 0} tone="text-rose-700" />
                        <Big label="Not on site" value={rc?.not_on_site ?? 0} tone="text-slate-600" />
                        <Big label="Responded" value={`${rc?.response_rate ?? 0}%`} tone="text-slate-700"
                            detail={`${rc?.acknowledged ?? 0} of ${rc?.total ?? 0}`} />
                    </div>

                    {(rc?.help_needed ?? []).length > 0 && (
                        <section className="mb-4 rounded border-2 border-rose-300 bg-rose-50 p-4">
                            <h2 className="mb-2 text-sm font-semibold uppercase tracking-wide text-rose-800">
                                Asked for help — send somebody now
                            </h2>
                            <ul className="space-y-1 text-sm">
                                {rc.help_needed.map((p) => (
                                    <li key={p.recipient_id} className="flex flex-wrap justify-between gap-2 rounded bg-white p-2">
                                        <span className="font-medium text-slate-900">{p.name}</span>
                                        <span className="text-xs text-slate-600">
                                            {[p.department, p.site, p.mobile].filter(Boolean).join(' · ')}
                                        </span>
                                        {p.response_text && (
                                            <span className="w-full text-xs italic text-rose-800">“{p.response_text}”</span>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}

                    <div className="grid gap-4 lg:grid-cols-3">
                        <section className="rounded border border-slate-200 bg-white p-4 lg:col-span-2">
                            <h2 className="mb-2 text-sm font-semibold text-slate-700">
                                Unaccounted for ({rc?.unaccounted?.length ?? 0})
                            </h2>
                            {(rc?.unaccounted ?? []).length === 0 ? (
                                <p className="text-sm text-emerald-700">Everybody has been accounted for.</p>
                            ) : (
                                <ul className="divide-y divide-slate-100 text-sm">
                                    {rc.unaccounted.map((p) => (
                                        <li key={p.recipient_id} className="flex items-center justify-between py-2">
                                            <div className="min-w-0">
                                                <span className="font-medium text-slate-800">{p.name}</span>
                                                <div className="text-[11px] text-slate-500">
                                                    {[p.department, p.site].filter(Boolean).join(' · ')} — {p.reason}
                                                </div>
                                            </div>
                                            <span className={`shrink-0 rounded px-2 py-0.5 text-[11px] ${
                                                p.state === 'unreachable'
                                                    ? 'bg-slate-200 text-slate-700'
                                                    : 'bg-amber-100 text-amber-800'
                                            }`}>
                                                {p.state === 'unreachable' ? 'never reached' : 'no response'}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>

                        <div className="space-y-4">
                            <section className="rounded border border-slate-200 bg-white p-4">
                                <h2 className="mb-2 text-sm font-semibold text-slate-700">Delivery</h2>
                                <dl className="space-y-1 text-xs text-slate-600">
                                    {['messages', 'sent', 'delivered', 'read', 'failed'].map((k) => (
                                        <div key={k} className="flex justify-between">
                                            <dt className="capitalize">{k}</dt>
                                            <dd className="tabular-nums">{rc?.funnel?.[k] ?? 0}</dd>
                                        </div>
                                    ))}
                                </dl>
                                {rc?.funnel?.note && (
                                    <p className="mt-2 text-[11px] text-slate-500">{rc.funnel.note}</p>
                                )}
                            </section>

                            <section className="rounded border border-slate-200 bg-white p-4">
                                <h2 className="mb-2 text-sm font-semibold text-slate-700">By department</h2>
                                <ul className="space-y-1 text-xs">
                                    {(rc?.by_department ?? []).map((d) => (
                                        <li key={d.name} className="flex justify-between">
                                            <span className="text-slate-600">{d.name}</span>
                                            <span className={d.unaccounted_for > 0 ? 'text-rose-600' : 'text-emerald-700'}>
                                                {d.unaccounted_for > 0 ? `${d.unaccounted_for} missing` : 'all in'}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </section>

                            {rc?.headcount_minutes != null && (
                                <section className="rounded border border-slate-200 bg-white p-4">
                                    <h2 className="text-sm font-semibold text-slate-700">Headcount time</h2>
                                    <p className="text-2xl font-semibold text-slate-800">{rc.headcount_minutes} min</p>
                                    <p className="text-[11px] text-slate-500">
                                        Dispatch to the last response. This is the number an evacuation drill is
                                        scored on.
                                    </p>
                                </section>
                            )}
                        </div>
                    </div>
                </>
            )}
        </AppLayout>
    );
}

function Figure({ label, value, tone = '' }) {
    return (
        <div>
            <dt className="text-[11px] text-slate-500">{label}</dt>
            <dd className={`text-lg font-semibold ${tone || 'text-slate-800'}`}>{value}</dd>
        </div>
    );
}

function Big({ label, value, tone, detail }) {
    return (
        <div className="rounded border border-slate-200 bg-white p-4">
            <div className={`text-3xl font-semibold ${tone}`}>{value}</div>
            <div className="text-xs text-slate-500">{label}</div>
            {detail && <div className="mt-0.5 text-[11px] text-slate-400">{detail}</div>}
        </div>
    );
}
