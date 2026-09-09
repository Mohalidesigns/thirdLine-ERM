import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * The tree health dashboard — the module's landing screen.
 *
 * IT LEADS WITH WHAT WOULD FAIL, NOT WITH AN INVENTORY. A department head
 * opening this does not want a list of trees; they want to know which cascades
 * would break today. Stale, orphaned and deputy-less are the three answers and
 * they are above the fold; the inventory is underneath, where a list belongs.
 *
 * A KRI WITH NO VALUE SAYS SO IN WORDS. Null is not zero: "no cascade has been
 * completed in the last twelve months" and "every cascade failed" are opposite
 * facts and a dash printed as 0% conflates them.
 */
export default function Index({ dashboard = {}, units = [], types = [], scope_note = null, can = {} }) {
    const { trees = [], summary = {}, kris = [], data_confidence = {}, recent_tests = [] } = dashboard;
    const [creating, setCreating] = useState(false);
    const [generating, setGenerating] = useState(false);
    const [filter, setFilter] = useState('all');

    const create = useForm({ name: '', tree_type: 'department', business_unit_id: '' });
    const generate = useForm({ business_unit_id: '', tree_type: 'department', name: '' });

    const submitCreate = (e) => {
        e.preventDefault();
        create.post(tryRoute('bcms.call-trees.store'), { onSuccess: () => setCreating(false) });
    };

    const submitGenerate = (e) => {
        e.preventDefault();
        generate.post(tryRoute('bcms.call-trees.generate'), { onSuccess: () => setGenerating(false) });
    };

    const visible = trees.filter((t) => {
        if (filter === 'stale') return t.is_stale;
        if (filter === 'orphans') return t.orphans > 0;
        if (filter === 'untested') return !t.last_test;
        if (filter === 'draft') return t.status === 'draft';
        return true;
    });

    const cards = [
        ['all', 'Trees', summary.total ?? 0, 'text-slate-800'],
        ['stale', 'Overdue for review', summary.stale ?? 0, (summary.stale ?? 0) > 0 ? 'text-rose-600' : 'text-slate-800'],
        ['orphans', 'Orphaned nodes', summary.orphaned_nodes ?? 0, (summary.orphaned_nodes ?? 0) > 0 ? 'text-rose-600' : 'text-slate-800'],
        ['untested', 'Never tested', summary.never_tested ?? 0, (summary.never_tested ?? 0) > 0 ? 'text-amber-600' : 'text-slate-800'],
    ];

    return (
        <AppLayout>
            <Head title="Call trees" />

            <PageHeader
                title="Call trees"
                subtitle="Whether the cascades would work if they were needed today — ISO 22301 clause 8.4.3."
                actions={can.manage && (
                    <div className="flex gap-2">
                        <button type="button" onClick={() => setGenerating(true)}
                            className="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">
                            Generate from directory
                        </button>
                        <button type="button" onClick={() => setCreating(true)}
                            className="rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">
                            New call tree
                        </button>
                    </div>
                )}
            />

            {scope_note && <p className="mb-4 text-xs text-slate-500">{scope_note}</p>}

            <div className="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
                {cards.map(([key, label, value, tone]) => (
                    <button key={key} type="button" onClick={() => setFilter(key)}
                        className={`rounded border p-4 text-left transition ${filter === key ? 'border-slate-400 bg-slate-50' : 'border-slate-200 bg-white hover:border-slate-300'}`}>
                        <div className={`text-2xl font-semibold ${tone}`}>{value}</div>
                        <div className="text-xs text-slate-500">{label}</div>
                    </button>
                ))}
            </div>

            <div className="mb-6 grid gap-4 lg:grid-cols-3">
                <section className="rounded border border-slate-200 bg-white p-4 lg:col-span-2">
                    <h2 className="mb-3 text-sm font-semibold text-slate-700">Cascade indicators</h2>
                    <div className="grid gap-3 sm:grid-cols-2">
                        {kris.map((kri) => (
                            <div key={kri.code} className="rounded bg-slate-50 p-3">
                                <div className="text-xs text-slate-500">{kri.name}</div>
                                <div className="text-xl font-semibold text-slate-800">
                                    {kri.value === null ? <span className="text-slate-400">Not measured</span> : `${kri.value}${kri.unit === '%' ? '%' : ` ${kri.unit}`}`}
                                </div>
                                <div className="mt-1 text-[11px] text-slate-500">{kri.basis}</div>
                            </div>
                        ))}
                    </div>
                </section>

                <section className="rounded border border-slate-200 bg-white p-4">
                    <h2 className="mb-1 text-sm font-semibold text-slate-700">Contact data confidence</h2>
                    <p className="mb-3 text-[11px] text-slate-500">
                        Verified inside the last {data_confidence.window_days} days. The quarterly verification
                        campaign that moves this is Phase 2C's.
                    </p>
                    {data_confidence.confidence === null ? (
                        <p className="text-sm text-slate-500">{data_confidence.note}</p>
                    ) : (
                        <>
                            <div className="text-3xl font-semibold text-slate-800">{data_confidence.confidence}%</div>
                            <dl className="mt-3 space-y-1 text-xs text-slate-600">
                                <div className="flex justify-between"><dt>Verified</dt><dd>{data_confidence.verified} of {data_confidence.contacts}</dd></div>
                                <div className="flex justify-between"><dt>Failing repeatedly</dt><dd>{data_confidence.failing}</dd></div>
                                <div className="flex justify-between"><dt>Consent withdrawn</dt><dd>{data_confidence.consent_withdrawn}</dd></div>
                            </dl>
                        </>
                    )}
                </section>
            </div>

            <section className="rounded border border-slate-200 bg-white">
                <div className="overflow-x-auto">
                    <table className="min-w-full text-sm">
                        <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-4 py-2">Tree</th>
                                <th className="px-4 py-2">Type</th>
                                <th className="px-4 py-2">Status</th>
                                <th className="px-4 py-2 text-right">Nodes</th>
                                <th className="px-4 py-2 text-right">Problems</th>
                                <th className="px-4 py-2">Reviewed</th>
                                <th className="px-4 py-2">Last test</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {visible.map((tree) => (
                                <tr key={tree.uuid} className="hover:bg-slate-50">
                                    <td className="px-4 py-2">
                                        <Link href={tryRoute('bcms.call-trees.show', tree.uuid)}
                                            className="font-medium text-slate-800 hover:underline">
                                            {tree.name}
                                        </Link>
                                        <div className="text-[11px] text-slate-500">
                                            v{tree.version} · {tree.business_unit ?? 'Organisation-wide'}
                                            {tree.never_human_checked && ' · generated, never checked by a person'}
                                        </div>
                                    </td>
                                    <td className="px-4 py-2 text-slate-600">{tree.type_label}</td>
                                    <td className="px-4 py-2">
                                        <span className="rounded bg-slate-100 px-2 py-0.5 text-[11px] text-slate-700">
                                            {tree.status_label}
                                        </span>
                                        {tree.is_stale && (
                                            <span className="ml-1 rounded bg-rose-100 px-2 py-0.5 text-[11px] font-medium text-rose-700">
                                                stale
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-2 text-right tabular-nums text-slate-700">{tree.nodes}</td>
                                    <td className="px-4 py-2 text-right text-[11px]">
                                        {tree.orphans > 0 && <div className="text-rose-600">{tree.orphans} unreachable</div>}
                                        {tree.missing_deputies > 0 && <div className="text-amber-600">{tree.missing_deputies} without a deputy</div>}
                                        {tree.orphans === 0 && tree.missing_deputies === 0 && <span className="text-slate-400">—</span>}
                                    </td>
                                    <td className="px-4 py-2 text-[11px] text-slate-600">
                                        {tree.last_reviewed_at ?? <span className="text-slate-400">never</span>}
                                        {tree.days_overdue != null && (
                                            <div className="text-rose-600">{tree.days_overdue} days overdue</div>
                                        )}
                                    </td>
                                    <td className="px-4 py-2 text-[11px]">
                                        {tree.last_test ? (
                                            <Link href={tryRoute('bcms.call-tree-tests.show', tree.last_test.uuid)}
                                                className="text-slate-700 hover:underline">
                                                {tree.last_test.initiated_at}
                                                {tree.last_test.completion_rate != null && ` · ${tree.last_test.completion_rate}%`}
                                            </Link>
                                        ) : <span className="text-amber-600">never tested</span>}
                                    </td>
                                </tr>
                            ))}
                            {visible.length === 0 && (
                                <tr><td colSpan={7} className="px-4 py-8 text-center text-sm text-slate-500">
                                    Nothing matches this filter.
                                </td></tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </section>

            {recent_tests.length > 0 && (
                <section className="mt-6 rounded border border-slate-200 bg-white p-4">
                    <h2 className="mb-3 text-sm font-semibold text-slate-700">Recent cascades</h2>
                    <ul className="divide-y divide-slate-100 text-sm">
                        {recent_tests.map((test) => (
                            <li key={test.uuid} className="flex items-center justify-between py-2">
                                <div>
                                    <Link href={tryRoute(test.completed ? 'bcms.call-tree-tests.show' : 'bcms.call-tree-tests.live', test.uuid)}
                                        className="font-medium text-slate-800 hover:underline">
                                        {test.tree}
                                    </Link>
                                    <div className="text-[11px] text-slate-500">
                                        {test.mode} · {test.announced ? 'announced' : 'unannounced'} · {test.initiated_at?.slice(0, 10)}
                                    </div>
                                </div>
                                <div className="text-right text-[11px]">
                                    {test.completion_rate != null && <div className="text-slate-700">{test.completion_rate}% reached</div>}
                                    {test.blocked > 0 && <div className="text-rose-600">{test.blocked} isolated</div>}
                                    {!test.completed && <div className="text-amber-600">running</div>}
                                </div>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {creating && (
                <Modal title="New call tree" onClose={() => setCreating(false)}>
                    <form onSubmit={submitCreate} className="space-y-3">
                        <Field label="Name" error={create.errors.name}>
                            <input value={create.data.name} onChange={(e) => create.setData('name', e.target.value)}
                                className="w-full rounded border-slate-300 text-sm" required />
                        </Field>
                        <Field label="Type" error={create.errors.tree_type}>
                            <select value={create.data.tree_type} onChange={(e) => create.setData('tree_type', e.target.value)}
                                className="w-full rounded border-slate-300 text-sm">
                                {types.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                            </select>
                        </Field>
                        <Field label="Business unit" error={create.errors.business_unit_id}>
                            <select value={create.data.business_unit_id} onChange={(e) => create.setData('business_unit_id', e.target.value)}
                                className="w-full rounded border-slate-300 text-sm">
                                <option value="">Organisation-wide</option>
                                {units.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                            </select>
                        </Field>
                        <button type="submit" disabled={create.processing}
                            className="w-full rounded bg-slate-800 py-2 text-sm text-white hover:bg-slate-700">
                            Create
                        </button>
                    </form>
                </Modal>
            )}

            {generating && (
                <Modal title="Generate from the reporting chain" onClose={() => setGenerating(false)}>
                    <p className="mb-3 text-xs text-slate-500">
                        The directory says who reports to whom. It does not say who rings whom at three in the
                        morning — this produces a draft for the department head to correct.
                    </p>
                    <form onSubmit={submitGenerate} className="space-y-3">
                        <Field label="Business unit" error={generate.errors.business_unit_id}>
                            <select value={generate.data.business_unit_id} onChange={(e) => generate.setData('business_unit_id', e.target.value)}
                                className="w-full rounded border-slate-300 text-sm" required>
                                <option value="">Choose…</option>
                                {units.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                            </select>
                        </Field>
                        <Field label="Type" error={generate.errors.tree_type}>
                            <select value={generate.data.tree_type} onChange={(e) => generate.setData('tree_type', e.target.value)}
                                className="w-full rounded border-slate-300 text-sm">
                                {types.filter((t) => t.generatable).map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                            </select>
                        </Field>
                        <button type="submit" disabled={generate.processing}
                            className="w-full rounded bg-slate-800 py-2 text-sm text-white hover:bg-slate-700">
                            Generate draft
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

function Field({ label, error, children }) {
    return (
        <label className="block">
            <span className="mb-1 block text-xs font-medium text-slate-600">{label}</span>
            {children}
            {error && <span className="mt-1 block text-xs text-rose-600">{error}</span>}
        </label>
    );
}
