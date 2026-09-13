import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import CascadeTree, { STATES } from '@/Components/Bcms/CascadeTree';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * The live cascade map.
 *
 * IT POLLS. The blueprint asks for SSE or a websocket; this product broadcasts
 * over the log driver and ships into on-prem estates where a websocket through
 * the proxy is a project of its own. A cascade is minutes long and hundreds of
 * nodes wide, so five seconds of one small JSON document is both enough and the
 * only thing that works behind a bank's firewall on the day of the demo.
 *
 * POLLING STOPS WHEN THE CASCADE STOPS. A tab left open overnight on a finished
 * test must not keep a request every five seconds for twelve hours.
 */
export default function Live({ test = {}, tree = [], counts = {}, tiers = [], total_blocked = 0, running = false, channels_are_mocked = false, can = {} }) {
    const [state, setState] = useState({ test, tree, counts, tiers, total_blocked, running });
    const [selected, setSelected] = useState(null);

    useEffect(() => {
        if (!state.running) return undefined;

        const id = setInterval(() => {
            fetch(tryRoute('bcms.call-tree-tests.live.json', test.uuid), { headers: { Accept: 'application/json' } })
                .then((r) => (r.ok ? r.json() : null))
                .then((data) => data && setState(data))
                .catch(() => {});
        }, 5000);

        return () => clearInterval(id);
    }, [state.running, test.uuid]);

    const total = state.test.nodes_total || 0;
    const reached = state.counts.reached ?? 0;

    return (
        <AppLayout>
            <Head title="Cascade in progress" />

            <PageHeader
                title={`${state.test.tree_name} cascade`}
                subtitle={`${state.test.mode_label} · ${state.test.announced ? 'announced' : 'unannounced'} · started ${state.test.initiated_at?.slice(11, 16) ?? '—'}`}
                actions={(
                    <div className="flex gap-2">
                        <Link href={tryRoute('bcms.call-trees.show', state.test.tree_uuid)}
                            className="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">
                            The tree
                        </Link>
                        {can.test && state.running && (
                            <>
                                <button type="button"
                                    onClick={() => router.post(tryRoute('bcms.call-tree-tests.complete', test.uuid))}
                                    className="rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">
                                    Close and score
                                </button>
                                <button type="button"
                                    onClick={() => {
                                        const reason = window.prompt('Why is this cascade being aborted?');
                                        if (reason) router.post(tryRoute('bcms.call-tree-tests.abort', test.uuid), { reason });
                                    }}
                                    className="rounded border border-rose-300 px-3 py-1.5 text-sm text-rose-700 hover:bg-rose-50">
                                    Abort
                                </button>
                            </>
                        )}
                        {!state.running && (
                            <Link href={tryRoute('bcms.call-tree-tests.show', test.uuid)}
                                className="rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">
                                Results
                            </Link>
                        )}
                    </div>
                )}
            />

            {channels_are_mocked && (
                <div className="mb-4 rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                    Notification channels are still recording mocks. Nothing has left the building — every
                    “reached” below is a simulated dispatch. Real gateways arrive in Phase 7.
                </div>
            )}

            <div className="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-5">
                <Stat label="Reached" value={reached} of={total} tone="text-emerald-600" />
                <Stat label="Waiting" value={state.counts.pending ?? 0} tone="text-amber-600" />
                <Stat label="Failed" value={state.counts.failed ?? 0} tone="text-rose-600" />
                <Stat label="Isolated by a failure" value={state.total_blocked} tone="text-rose-700" />
                <Stat label="Not yet called" value={state.counts.waiting ?? 0} tone="text-slate-500" />
            </div>

            <section className="mb-6 rounded border border-slate-200 bg-white p-4">
                <h2 className="mb-3 text-sm font-semibold text-slate-700">Tier progress</h2>
                <div className="space-y-2">
                    {state.tiers.map((tier) => (
                        <div key={tier.tier}>
                            <div className="mb-1 flex justify-between text-xs text-slate-600">
                                <span>Tier {tier.tier}</span>
                                <span>{tier.reached} of {tier.total} reached</span>
                            </div>
                            <div className="h-2 overflow-hidden rounded bg-slate-100">
                                <div className="h-full bg-emerald-500 transition-all"
                                    style={{ width: `${tier.percent ?? 0}%` }} />
                            </div>
                        </div>
                    ))}
                </div>
            </section>

            <div className="grid gap-4 lg:grid-cols-3">
                <section className="rounded border border-slate-200 bg-white lg:col-span-2">
                    <header className="flex flex-wrap items-center gap-3 border-b border-slate-100 px-4 py-2 text-[11px]">
                        {Object.entries(STATES).map(([key, s]) => (
                            <span key={key} className="flex items-center gap-1 text-slate-600">
                                <span className={`h-2 w-2 rounded-full ${s.dot}`} /> {s.label}
                            </span>
                        ))}
                    </header>
                    <CascadeTree nodes={state.tree} showState onSelect={setSelected} selectedId={selected?.test_node_id} />
                </section>

                <section className="rounded border border-slate-200 bg-white p-4">
                    {selected ? (
                        <>
                            <h2 className="text-sm font-semibold text-slate-800">{selected.name}</h2>
                            <p className="mb-3 text-[11px] text-slate-500">
                                Tier {selected.tier} · {selected.role_label}
                            </p>
                            <dl className="space-y-1 text-xs text-slate-600">
                                <div className="flex justify-between"><dt>State</dt><dd>{selected.outcome_label}</dd></div>
                                <div className="flex justify-between"><dt>Attempts</dt><dd>{selected.attempts}</dd></div>
                                <div className="flex justify-between"><dt>Channel</dt><dd>{selected.channel_used ?? '—'}</dd></div>
                                <div className="flex justify-between"><dt>Contacted</dt><dd>{selected.contacted_at?.slice(11, 16) ?? '—'}</dd></div>
                                <div className="flex justify-between"><dt>Answered</dt><dd>{selected.acknowledged_at?.slice(11, 16) ?? '—'}</dd></div>
                                {selected.response_minutes != null && (
                                    <div className="flex justify-between"><dt>Took</dt><dd>{selected.response_minutes} minutes</dd></div>
                                )}
                            </dl>

                            {state.running && selected.outcome === 'pending' && (
                                <div className="mt-4 space-y-2 border-t border-slate-100 pt-3">
                                    <button type="button"
                                        onClick={() => router.post(
                                            tryRoute('bcms.call-tree-tests.nodes.ack', [test.uuid, selected.test_node_id]),
                                            { correct_action: true },
                                            { preserveScroll: true, onSuccess: () => setSelected(null) },
                                        )}
                                        className="w-full rounded bg-emerald-700 py-2 text-xs text-white hover:bg-emerald-600">
                                        Record: reached and acknowledged
                                    </button>
                                    {can.test && (
                                        <button type="button"
                                            onClick={() => router.post(
                                                tryRoute('bcms.call-tree-tests.nodes.failure', [test.uuid, selected.test_node_id]),
                                                { outcome: 'wrong_number' },
                                                { preserveScroll: true, onSuccess: () => setSelected(null) },
                                            )}
                                            className="w-full rounded border border-rose-300 py-2 text-xs text-rose-700 hover:bg-rose-50">
                                            Record: number did not work
                                        </button>
                                    )}
                                </div>
                            )}
                        </>
                    ) : (
                        <p className="text-sm text-slate-500">
                            Pick a node to see its timing, or to record a response on somebody's behalf.
                        </p>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}

function Stat({ label, value, of, tone }) {
    return (
        <div className="rounded border border-slate-200 bg-white p-4">
            <div className={`text-2xl font-semibold ${tone}`}>
                {value}{of ? <span className="text-base text-slate-400"> / {of}</span> : null}
            </div>
            <div className="text-xs text-slate-500">{label}</div>
        </div>
    );
}
