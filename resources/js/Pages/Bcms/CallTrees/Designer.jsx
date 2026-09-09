import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import CascadeTree from '@/Components/Bcms/CascadeTree';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * The call tree designer.
 *
 * AN APPROVED TREE IS READ-ONLY AND SAYS SO RATHER THAN DISABLING SILENTLY. The
 * button that would edit it becomes the button that creates the next version,
 * because "why can't I change this" is a support call and "create version 2" is
 * an answer.
 *
 * THE PROBLEMS PANEL IS BESIDE THE TREE, NOT ON A TAB. An unreachable node is
 * only meaningful next to what is below it, and a separate tab is where a
 * warning goes to be ignored.
 */
export default function Designer({
    tree = {}, nodes = [], orphaned = [], versions = [], tests = [], modes = [], channels = [],
    tier_labels = {}, can = {},
}) {
    const [selected, setSelected] = useState(null);
    const [adding, setAdding] = useState(false);
    const [testing, setTesting] = useState(false);
    const [candidates, setCandidates] = useState([]);
    const [search, setSearch] = useState('');

    const add = useForm({ contact_id: '', parent_node_id: '', role_label: '', is_must_reach: false, expected_response_minutes: 15 });
    const test = useForm({ mode: 'hybrid', announced: true, initiate: true });
    const deputy = useForm({ deputy_contact_id: '' });
    const reparent = useForm({ parent_node_id: '' });

    useEffect(() => {
        if (!adding && !selected) return;
        const handle = setTimeout(() => {
            fetch(`${tryRoute('bcms.call-trees.candidates', tree.uuid)}?q=${encodeURIComponent(search)}`, {
                headers: { Accept: 'application/json' },
            })
                .then((r) => (r.ok ? r.json() : { contacts: [] }))
                .then((d) => setCandidates(d.contacts ?? []))
                .catch(() => setCandidates([]));
        }, 200);
        return () => clearTimeout(handle);
    }, [search, adding, selected, tree.uuid]);

    const submitAdd = (e) => {
        e.preventDefault();
        add.post(tryRoute('bcms.call-trees.nodes.store', tree.uuid), {
            preserveScroll: true,
            onSuccess: () => { setAdding(false); add.reset(); },
        });
    };

    const submitTest = (e) => {
        e.preventDefault();
        test.post(tryRoute('bcms.call-trees.tests.store', tree.uuid));
    };

    const flat = [];
    const walk = (rows) => rows.forEach((r) => { flat.push(r); walk(r.children ?? []); });
    walk(nodes);

    return (
        <AppLayout>
            <Head title={tree.name ?? 'Call tree'} />

            <PageHeader
                title={tree.name}
                subtitle={`${tree.type_label} · version ${tree.version} · ${tree.status_label}${tree.business_unit ? ` · ${tree.business_unit}` : ''}`}
                actions={(
                    <div className="flex flex-wrap gap-2">
                        <Link href={tryRoute('bcms.call-trees.index')}
                            className="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">
                            All trees
                        </Link>
                        {can.manage && tree.editable && (
                            <>
                                <button type="button" onClick={() => setAdding(true)}
                                    className="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">
                                    Add person
                                </button>
                                <button type="button"
                                    onClick={() => router.post(tryRoute('bcms.call-trees.approve', tree.uuid), {}, { preserveScroll: true })}
                                    className="rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">
                                    Approve
                                </button>
                            </>
                        )}
                        {can.manage && !tree.editable && (
                            <button type="button"
                                onClick={() => router.post(tryRoute('bcms.call-trees.supersede', tree.uuid), {}, { preserveScroll: true })}
                                className="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">
                                Create next version
                            </button>
                        )}
                        {can.test && tree.testable && (
                            <button type="button" onClick={() => setTesting(true)}
                                className="rounded bg-emerald-700 px-3 py-1.5 text-sm text-white hover:bg-emerald-600">
                                Test this tree
                            </button>
                        )}
                    </div>
                )}
            />

            {!tree.editable && (
                <div className="mb-4 rounded border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                    Version {tree.version} was approved{tree.approved_at ? ` on ${tree.approved_at}` : ''}
                    {tree.approved_by ? ` by ${tree.approved_by}` : ''} and cannot be edited. Create the next
                    version to change it — the roster that was cascaded stays the roster that was cascaded.
                </div>
            )}

            {tree.is_stale && (
                <div className="mb-4 rounded border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">
                    This tree is {tree.days_overdue} days past its {tree.review_frequency_days}-day review cycle.
                    {can.manage && (
                        <button type="button"
                            onClick={() => router.post(tryRoute('bcms.call-trees.review', tree.uuid), {}, { preserveScroll: true })}
                            className="ml-2 underline">
                            Record a review
                        </button>
                    )}
                </div>
            )}

            <div className="grid gap-4 lg:grid-cols-3">
                <section className="rounded border border-slate-200 bg-white lg:col-span-2">
                    <header className="flex items-center justify-between border-b border-slate-100 px-4 py-2">
                        <h2 className="text-sm font-semibold text-slate-700">
                            {tree.node_count} {tree.node_count === 1 ? 'person' : 'people'}
                        </h2>
                        <div className="flex gap-3 text-[11px] text-slate-500">
                            {Object.entries(tier_labels).map(([tier, label]) => (
                                <span key={tier}>T{tier} {label}</span>
                            ))}
                        </div>
                    </header>
                    <CascadeTree
                        nodes={nodes}
                        onSelect={setSelected}
                        selectedId={selected?.id}
                        empty="Nobody on this tree yet. Add the activation authority at tier 0 first."
                    />
                </section>

                <div className="space-y-4">
                    <section className="rounded border border-slate-200 bg-white p-4">
                        <h2 className="mb-2 text-sm font-semibold text-slate-700">Problems</h2>
                        {orphaned.length === 0 && tree.missing_deputy_count === 0 ? (
                            <p className="text-sm text-slate-500">Nothing outstanding on this tree.</p>
                        ) : (
                            <ul className="space-y-2 text-sm">
                                {orphaned.map((row) => (
                                    <li key={row.id} className="rounded bg-rose-50 p-2">
                                        <div className="font-medium text-rose-800">
                                            {row.name ?? row.role_label ?? 'A node'}
                                            {row.downstream_count > 0 && ` → ${row.downstream_count} isolated`}
                                        </div>
                                        <div className="text-[11px] text-rose-700">{row.reason}</div>
                                    </li>
                                ))}
                                {tree.missing_deputy_count > 0 && (
                                    <li className="rounded bg-amber-50 p-2 text-[11px] text-amber-800">
                                        {tree.missing_deputy_count} must-reach {tree.missing_deputy_count === 1 ? 'node has' : 'nodes have'} nobody to escalate to.
                                    </li>
                                )}
                            </ul>
                        )}
                    </section>

                    {selected && (
                        <section className="rounded border border-slate-200 bg-white p-4">
                            <h2 className="mb-1 text-sm font-semibold text-slate-700">{selected.name ?? 'Node'}</h2>
                            <p className="mb-3 text-[11px] text-slate-500">
                                Tier {selected.tier} · {selected.downstream_count} below · responds within {selected.expected_response_minutes} minutes
                            </p>

                            <dl className="mb-3 space-y-1 text-xs text-slate-600">
                                <div className="flex justify-between"><dt>Deputy</dt><dd>{selected.deputy_name ?? <span className="text-amber-600">none</span>}</dd></div>
                                <div className="flex justify-between"><dt>Must reach</dt><dd>{selected.is_must_reach ? 'yes' : 'no'}</dd></div>
                                {selected.consent_withdrawn && (
                                    <div className="rounded bg-violet-50 p-2 text-violet-700">
                                        Consent withdrawn for personal channels. Excluded from cascade tests; still
                                        reachable on a work channel in a life-safety activation.
                                    </div>
                                )}
                            </dl>

                            {can.manage && tree.editable && (
                                <div className="space-y-3 border-t border-slate-100 pt-3">
                                    <form onSubmit={(e) => { e.preventDefault(); deputy.post(tryRoute('bcms.call-trees.nodes.deputy', [tree.uuid, selected.id]), { preserveScroll: true }); }}>
                                        <label className="mb-1 block text-xs font-medium text-slate-600">Assign a deputy</label>
                                        <div className="flex gap-2">
                                            <select value={deputy.data.deputy_contact_id}
                                                onChange={(e) => deputy.setData('deputy_contact_id', e.target.value)}
                                                className="flex-1 rounded border-slate-300 text-xs">
                                                <option value="">Choose…</option>
                                                {candidates.filter((c) => c.id !== selected.contact_id).map((c) => (
                                                    <option key={c.id} value={c.id}>{c.name}</option>
                                                ))}
                                            </select>
                                            <button type="submit" className="rounded bg-slate-800 px-3 text-xs text-white">Set</button>
                                        </div>
                                    </form>

                                    <form onSubmit={(e) => { e.preventDefault(); reparent.post(tryRoute('bcms.call-trees.nodes.reparent', [tree.uuid, selected.id]), { preserveScroll: true }); }}>
                                        <label className="mb-1 block text-xs font-medium text-slate-600">Move under</label>
                                        <div className="flex gap-2">
                                            <select value={reparent.data.parent_node_id}
                                                onChange={(e) => reparent.setData('parent_node_id', e.target.value)}
                                                className="flex-1 rounded border-slate-300 text-xs">
                                                <option value="">Top of the tree</option>
                                                {flat.filter((n) => n.id !== selected.id).map((n) => (
                                                    <option key={n.id} value={n.id}>T{n.tier} · {n.name}</option>
                                                ))}
                                            </select>
                                            <button type="submit" className="rounded bg-slate-800 px-3 text-xs text-white">Move</button>
                                        </div>
                                    </form>
                                </div>
                            )}
                        </section>
                    )}

                    <section className="rounded border border-slate-200 bg-white p-4">
                        <h2 className="mb-2 text-sm font-semibold text-slate-700">Versions</h2>
                        <ul className="space-y-1 text-xs">
                            {versions.map((v) => (
                                <li key={v.uuid} className="flex items-center justify-between">
                                    <Link href={tryRoute('bcms.call-trees.show', v.uuid)}
                                        className={v.uuid === tree.uuid ? 'font-semibold text-slate-800' : 'text-slate-600 hover:underline'}>
                                        v{v.version} · {v.status_label}
                                    </Link>
                                    <span className="text-slate-400">{v.approved_at ?? '—'} · {v.nodes} nodes</span>
                                </li>
                            ))}
                        </ul>
                    </section>

                    {tests.length > 0 && (
                        <section className="rounded border border-slate-200 bg-white p-4">
                            <h2 className="mb-2 text-sm font-semibold text-slate-700">Cascade history</h2>
                            <ul className="space-y-1 text-xs">
                                {tests.map((t) => (
                                    <li key={t.uuid} className="flex items-center justify-between">
                                        <Link href={tryRoute(t.completed_at ? 'bcms.call-tree-tests.show' : 'bcms.call-tree-tests.live', t.uuid)}
                                            className="text-slate-700 hover:underline">
                                            {t.initiated_at?.slice(0, 10) ?? 'not started'} · {t.mode_label}
                                        </Link>
                                        <span className="text-slate-500">
                                            {t.completion_rate != null ? `${t.completion_rate}%` : '—'}
                                            {t.blocked > 0 && <span className="text-rose-600"> · {t.blocked} isolated</span>}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}
                </div>
            </div>

            {adding && (
                <Modal title="Add somebody to the tree" onClose={() => setAdding(false)}>
                    <form onSubmit={submitAdd} className="space-y-3">
                        <label className="block">
                            <span className="mb-1 block text-xs font-medium text-slate-600">Search the roster</span>
                            <input value={search} onChange={(e) => setSearch(e.target.value)}
                                className="w-full rounded border-slate-300 text-sm" placeholder="Name or job title" />
                        </label>
                        <label className="block">
                            <span className="mb-1 block text-xs font-medium text-slate-600">Person</span>
                            <select value={add.data.contact_id} onChange={(e) => add.setData('contact_id', e.target.value)}
                                className="w-full rounded border-slate-300 text-sm" required size={6}>
                                {candidates.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.name}{c.title ? ` — ${c.title}` : ''}{c.has_mobile ? '' : ' (no mobile)'}
                                    </option>
                                ))}
                            </select>
                            {add.errors.contact_id && <span className="mt-1 block text-xs text-rose-600">{add.errors.contact_id}</span>}
                        </label>
                        <label className="block">
                            <span className="mb-1 block text-xs font-medium text-slate-600">Reports to (in the cascade)</span>
                            <select value={add.data.parent_node_id} onChange={(e) => add.setData('parent_node_id', e.target.value)}
                                className="w-full rounded border-slate-300 text-sm">
                                <option value="">Top of the tree (tier 0)</option>
                                {flat.map((n) => <option key={n.id} value={n.id}>T{n.tier} · {n.name}</option>)}
                            </select>
                        </label>
                        <label className="flex items-center gap-2 text-xs text-slate-600">
                            <input type="checkbox" checked={add.data.is_must_reach}
                                onChange={(e) => add.setData('is_must_reach', e.target.checked)} className="rounded" />
                            Must be reached for the cascade to score complete
                        </label>
                        <button type="submit" disabled={add.processing}
                            className="w-full rounded bg-slate-800 py-2 text-sm text-white hover:bg-slate-700">
                            Add to tree
                        </button>
                    </form>
                </Modal>
            )}

            {testing && (
                <Modal title="Test this tree" onClose={() => setTesting(false)}>
                    <form onSubmit={submitTest} className="space-y-3">
                        <label className="block">
                            <span className="mb-1 block text-xs font-medium text-slate-600">Mode</span>
                            <select value={test.data.mode} onChange={(e) => test.setData('mode', e.target.value)}
                                className="w-full rounded border-slate-300 text-sm">
                                {modes.map((m) => (
                                    <option key={m.value} value={m.value}>
                                        {m.label}
                                        {m.automated_from_tier === null ? ' — every tier confirmed by a person'
                                            : m.automated_from_tier === 0 ? ' — every tier dispatched by the system'
                                                : ` — tiers 0–${m.automated_from_tier - 1} by a person, ${m.automated_from_tier} down by the system`}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="flex items-center gap-2 text-xs text-slate-600">
                            <input type="checkbox" checked={test.data.announced}
                                onChange={(e) => test.setData('announced', e.target.checked)} className="rounded" />
                            Announced — participants get the countdown ladder
                        </label>
                        {!test.data.announced && (
                            <p className="rounded bg-amber-50 p-2 text-[11px] text-amber-800">
                                Unannounced: no reminders are sent, the calendar entry is hidden from participants,
                                and the suppression is written to the audit log.
                            </p>
                        )}
                        <p className="rounded bg-slate-50 p-2 text-[11px] text-slate-600">
                            Every message carries the mandatory “THIS IS AN EXERCISE” prefix.
                        </p>
                        <button type="submit" disabled={test.processing}
                            className="w-full rounded bg-emerald-700 py-2 text-sm text-white hover:bg-emerald-600">
                            Start cascade
                        </button>
                    </form>
                </Modal>
            )}
        </AppLayout>
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
