import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import CascadeTree from '@/Components/Bcms/CascadeTree';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * Cascade results — the broken-branch screen. This is the demo (Gate G2).
 *
 * THE HEADLINE IS A SENTENCE, NOT A CHART. "Ibrahim Sani unreachable → 34 staff
 * isolated" is the thing a room understands in one second; a bar chart of
 * completion by tier is the thing they nod at politely. The chart is underneath.
 *
 * EVERY BRANCH CARRIES ITS FIX. The four actions sit on the row with the
 * failure, because the reason a call tree stays broken for two years is that
 * repairing it meant visiting three other screens.
 *
 * A NUMBER THAT WAS NOT MEASURED IS BLANK, NOT ZERO. `median_response_minutes`
 * with nobody reached is not "0 minutes", and a scorecard that says it is is a
 * scorecard nobody should trust with the other seven figures.
 */
export default function Results({
    test = {}, scorecard = {}, scorecard_is_stored = false, branches = [], total_blocked = 0,
    tree = [], outcomes = [], channels_are_mocked = false, can = {},
}) {
    const [acting, setActing] = useState(null);
    const brokenIds = new Set(branches.map((b) => b.test_node_id));

    const fix = useForm({ mobile_primary: '', email: '' });
    const finding = useForm({ description: '', classification: 'observation' });

    const pct = (v) => (v == null ? <span className="text-slate-400">not measured</span> : `${v}%`);
    const num = (v, unit = '') => (v == null ? <span className="text-slate-400">not measured</span> : `${v}${unit}`);

    return (
        <AppLayout>
            <Head title="Cascade results" />

            <PageHeader
                title={`${test.tree_name} — cascade results`}
                subtitle={`${test.mode_label} · ${test.announced ? 'announced' : 'unannounced'} · ${test.initiated_at?.slice(0, 10) ?? '—'} · tree v${test.tree_version} · ${test.iso_clause_ref}`}
                actions={(
                    <Link href={tryRoute('bcms.call-trees.show', test.tree_uuid)}
                        className="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">
                        The tree
                    </Link>
                )}
            />

            {channels_are_mocked && (
                <div className="mb-4 rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                    Channels were recording mocks: no message left the building. The timings are real, the
                    dispatches were simulated.
                </div>
            )}

            {branches.length > 0 && (
                <section className="mb-6 rounded border-2 border-rose-200 bg-rose-50 p-4">
                    <h2 className="mb-1 text-sm font-semibold uppercase tracking-wide text-rose-700">
                        Broken branches
                    </h2>
                    <p className="mb-4 text-2xl font-semibold text-rose-900">
                        {branches[0].headline}
                    </p>
                    {total_blocked > 0 && (
                        <p className="mb-4 text-sm text-rose-800">
                            {total_blocked} {total_blocked === 1 ? 'person was' : 'staff were'} never contacted
                            because somebody above them could not be reached.
                        </p>
                    )}

                    <ul className="space-y-3">
                        {branches.map((branch) => (
                            <li key={branch.test_node_id} className="rounded border border-rose-200 bg-white p-3">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <div className="font-medium text-slate-800">
                                            {branch.name ?? 'Unassigned node'}
                                            <span className="ml-2 text-xs font-normal text-slate-500">
                                                tier {branch.tier} · {branch.role_label ?? 'no role recorded'}
                                            </span>
                                        </div>
                                        <div className="text-xs text-rose-700">
                                            {branch.outcome_label}
                                            {branch.attempts > 1 && ` after ${branch.attempts} attempts`}
                                            {branch.channel_used && ` on ${branch.channel_used}`}
                                            {!branch.has_deputy && ' · no deputy assigned'}
                                        </div>
                                    </div>
                                    <div className="shrink-0 text-right">
                                        <div className="text-2xl font-semibold text-rose-700">
                                            {branch.downstream_blocked_count}
                                        </div>
                                        <div className="text-[11px] text-rose-600">isolated</div>
                                    </div>
                                </div>

                                {branch.blocked.length > 0 && (
                                    <details className="mt-2">
                                        <summary className="cursor-pointer text-xs text-slate-600">
                                            Who was never contacted
                                        </summary>
                                        <ul className="mt-1 grid gap-1 text-[11px] text-slate-600 sm:grid-cols-2 lg:grid-cols-3">
                                            {branch.blocked.map((b) => (
                                                <li key={b.test_node_id}>{b.name} <span className="text-slate-400">T{b.tier}</span></li>
                                            ))}
                                        </ul>
                                    </details>
                                )}

                                <div className="mt-3 flex flex-wrap gap-2">
                                    {branch.remedies.map((remedy) => {
                                        const enabled =
                                            (remedy.action === 'fix_contact' && can.contacts && branch.contact_id) ||
                                            (remedy.action === 'assign_deputy' && can.manage) ||
                                            (remedy.action === 'reparent' && can.manage) ||
                                            (remedy.action === 'raise_finding' && can.findings);

                                        if (!enabled) return null;

                                        if (remedy.action === 'assign_deputy' || remedy.action === 'reparent') {
                                            return (
                                                <Link key={remedy.action}
                                                    href={tryRoute('bcms.call-trees.show', test.tree_uuid)}
                                                    title={remedy.why}
                                                    className="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-50">
                                                    {remedy.label}
                                                </Link>
                                            );
                                        }

                                        return (
                                            <button key={remedy.action} type="button" title={remedy.why}
                                                onClick={() => setActing({ branch, action: remedy.action })}
                                                className="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-50">
                                                {remedy.label}
                                            </button>
                                        );
                                    })}
                                </div>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            <div className="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <Stat label="Reached" value={pct(scorecard.completion_rate)}
                    detail={`${scorecard.nodes_reached ?? 0} of ${scorecard.nodes_total ?? 0}`} />
                <Stat label="First attempt" value={pct(scorecard.first_attempt_rate)}
                    detail="answered without the deputy" />
                <Stat label="Deputy activated" value={pct(scorecard.deputy_activation_rate)}
                    detail="primary unreachable" />
                <Stat label="Total cascade" value={num(scorecard.total_cascade_minutes, ' min')}
                    detail={`median response ${scorecard.median_response_minutes ?? '—'} min`} />
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                <section className="rounded border border-slate-200 bg-white p-4">
                    <h2 className="mb-3 text-sm font-semibold text-slate-700">Per-tier timing against target</h2>
                    <table className="w-full text-sm">
                        <thead className="text-left text-xs uppercase text-slate-500">
                            <tr>
                                <th className="pb-2">Tier</th>
                                <th className="pb-2 text-right">Reached</th>
                                <th className="pb-2 text-right">Elapsed</th>
                                <th className="pb-2 text-right">Target</th>
                                <th className="pb-2 text-right">Blocked</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {(scorecard.by_tier ?? []).map((row) => (
                                <tr key={row.tier}>
                                    <td className="py-2">Tier {row.tier}</td>
                                    <td className="py-2 text-right tabular-nums">
                                        {row.reached}/{row.nodes}
                                        <span className="ml-1 text-xs text-slate-500">
                                            {row.completion_rate == null ? '' : `${row.completion_rate}%`}
                                        </span>
                                    </td>
                                    <td className={`py-2 text-right tabular-nums ${row.on_target === false ? 'text-rose-600' : ''}`}>
                                        {row.elapsed_minutes == null ? '—' : `${row.elapsed_minutes}m`}
                                    </td>
                                    <td className="py-2 text-right tabular-nums text-slate-500">
                                        {row.target_minutes == null ? '—' : `${row.target_minutes}m`}
                                    </td>
                                    <td className="py-2 text-right tabular-nums text-rose-600">
                                        {row.blocked || ''}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </section>

                <section className="rounded border border-slate-200 bg-white p-4">
                    <h2 className="mb-3 text-sm font-semibold text-slate-700">Data quality and accuracy</h2>
                    <dl className="space-y-2 text-sm">
                        <Line label="Data quality failures" value={scorecard.data_quality_failures ?? 0}
                            hint="wrong number, dead line, mailbox full, unrecognised" />
                        <Line label="Response accuracy" value={pct(scorecard.response_accuracy)}
                            hint={`${scorecard.wrong_action ?? 0} acknowledged and then did the wrong thing`} />
                        <Line label="Must-reach nodes missed" value={`${scorecard.must_reach_missed ?? 0} of ${scorecard.must_reach_total ?? 0}`}
                            hint={(scorecard.must_reach_missed_names ?? []).join(', ') || 'every must-reach node answered'}
                            tone={(scorecard.must_reach_missed ?? 0) > 0 ? 'text-rose-600' : ''} />
                        <Line label="Excluded — consent withdrawn" value={scorecard.consent_excluded ?? 0}
                            hint={(scorecard.consent_excluded_names ?? []).join(', ')
                                || 'nobody was excluded; consent is not counted as a data failure'} />
                    </dl>

                    {scorecard.by_confirmation && Object.keys(scorecard.by_confirmation).length > 1 && (
                        <div className="mt-4 border-t border-slate-100 pt-3">
                            <h3 className="mb-2 text-xs font-semibold uppercase text-slate-500">
                                Hybrid: the two halves reported separately
                            </h3>
                            <dl className="space-y-1 text-xs text-slate-600">
                                {Object.entries(scorecard.by_confirmation).map(([key, half]) => (
                                    <div key={key} className="flex justify-between">
                                        <dt>{key === 'human_confirmed' ? 'Confirmed by a person' : 'Dispatched by the system'}
                                            <span className="text-slate-400"> · tiers {half.tiers.join(', ')}</span></dt>
                                        <dd>{half.reached}/{half.nodes} {half.completion_rate == null ? '' : `(${half.completion_rate}%)`}</dd>
                                    </div>
                                ))}
                            </dl>
                        </div>
                    )}
                </section>
            </div>

            <section className="mt-6 rounded border border-slate-200 bg-white">
                <header className="border-b border-slate-100 px-4 py-2">
                    <h2 className="text-sm font-semibold text-slate-700">The cascade as it ran</h2>
                    <p className="text-[11px] text-slate-500">
                        The roster as it was on the day — a tree repaired since does not rewrite this.
                    </p>
                </header>
                <CascadeTree nodes={tree} showState brokenIds={brokenIds} />
            </section>

            <p className="mt-4 text-[11px] text-slate-400">
                {scorecard_is_stored
                    ? 'These figures were stored when the cascade closed and will not change.'
                    : 'This cascade has not been closed, so the figures are computed live and will move.'}
            </p>

            {acting?.action === 'fix_contact' && (
                <Modal title={`Correct ${acting.branch.name}'s contact record`} onClose={() => setActing(null)}>
                    <p className="mb-3 text-xs text-slate-500">
                        Currently {acting.branch.contact_mobile ?? 'no mobile'} / {acting.branch.contact_email ?? 'no email'}.
                        Saving marks the record unverified until somebody confirms it works.
                    </p>
                    <form onSubmit={(e) => {
                        e.preventDefault();
                        fix.post(tryRoute('bcms.call-tree-tests.nodes.fix-contact', [test.uuid, acting.branch.test_node_id]), {
                            preserveScroll: true, onSuccess: () => { setActing(null); fix.reset(); },
                        });
                    }} className="space-y-3">
                        <label className="block">
                            <span className="mb-1 block text-xs font-medium text-slate-600">Mobile</span>
                            <input value={fix.data.mobile_primary} onChange={(e) => fix.setData('mobile_primary', e.target.value)}
                                className="w-full rounded border-slate-300 text-sm" placeholder="+234…" />
                            {fix.errors.mobile_primary && <span className="text-xs text-rose-600">{fix.errors.mobile_primary}</span>}
                        </label>
                        <label className="block">
                            <span className="mb-1 block text-xs font-medium text-slate-600">Email</span>
                            <input type="email" value={fix.data.email} onChange={(e) => fix.setData('email', e.target.value)}
                                className="w-full rounded border-slate-300 text-sm" />
                        </label>
                        <button type="submit" disabled={fix.processing}
                            className="w-full rounded bg-slate-800 py-2 text-sm text-white hover:bg-slate-700">
                            Save correction
                        </button>
                    </form>
                </Modal>
            )}

            {acting?.action === 'raise_finding' && (
                <Modal title="Raise a corrective action" onClose={() => setActing(null)}>
                    <p className="mb-3 text-xs text-slate-500">
                        This goes into the same register as exercise and incident findings, with source
                        “call tree test”.
                    </p>
                    <form onSubmit={(e) => {
                        e.preventDefault();
                        finding.post(tryRoute('bcms.call-tree-tests.nodes.finding', [test.uuid, acting.branch.test_node_id]), {
                            preserveScroll: true, onSuccess: () => { setActing(null); finding.reset(); },
                        });
                    }} className="space-y-3">
                        <label className="block">
                            <span className="mb-1 block text-xs font-medium text-slate-600">Classification</span>
                            <select value={finding.data.classification} onChange={(e) => finding.setData('classification', e.target.value)}
                                className="w-full rounded border-slate-300 text-sm">
                                <option value="observation">Observation</option>
                                <option value="improvement">Opportunity for improvement</option>
                                <option value="nonconformity">Nonconformity</option>
                            </select>
                        </label>
                        <label className="block">
                            <span className="mb-1 block text-xs font-medium text-slate-600">
                                Description <span className="font-normal text-slate-400">(leave blank to use the cascade's own wording)</span>
                            </span>
                            <textarea value={finding.data.description} onChange={(e) => finding.setData('description', e.target.value)}
                                rows={4} className="w-full rounded border-slate-300 text-sm"
                                placeholder={acting.branch.headline} />
                        </label>
                        <button type="submit" disabled={finding.processing}
                            className="w-full rounded bg-slate-800 py-2 text-sm text-white hover:bg-slate-700">
                            Raise it
                        </button>
                    </form>
                </Modal>
            )}
        </AppLayout>
    );
}

function Stat({ label, value, detail }) {
    return (
        <div className="rounded border border-slate-200 bg-white p-4">
            <div className="text-2xl font-semibold text-slate-800">{value}</div>
            <div className="text-xs text-slate-500">{label}</div>
            {detail && <div className="mt-1 text-[11px] text-slate-400">{detail}</div>}
        </div>
    );
}

function Line({ label, value, hint, tone = '' }) {
    return (
        <div>
            <div className="flex justify-between">
                <dt className="text-slate-600">{label}</dt>
                <dd className={`font-medium ${tone || 'text-slate-800'}`}>{value}</dd>
            </div>
            {hint && <p className="text-[11px] text-slate-400">{hint}</p>}
        </div>
    );
}

function Modal({ title, children, onClose }) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4" onClick={onClose}>
            <div className="w-full max-w-md rounded bg-white p-5 shadow-lg" onClick={(e) => e.stopPropagation()}>
                <h3 className="mb-4 text-sm font-semibold text-slate-800">{title}</h3>
                {children}
            </div>
        </div>
    );
}
