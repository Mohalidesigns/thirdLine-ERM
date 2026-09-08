import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import Pagination from '@thirdline/ui/Components/Pagination';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * The findings and corrective-action register — ISO 22301 clause 10.1.
 *
 * OVERDUE IS SHOWN, NOT COUNTED. A summary tile saying "7 overdue" is a number
 * somebody nods at; the row highlighted in red with a name and a date beside it
 * is the one that gets closed. The register's only job is to make an action
 * somebody has to take today findable by the person who has to take it.
 *
 * "COMPLETE" AND "VERIFY" ARE TWO BUTTONS AND THE SECOND IS NOT SHOWN TO THE
 * OWNER. Clause 10.1 asks whether the action worked, which the person who did it
 * cannot answer about themselves. The server refuses it too — this is the half
 * that stops somebody trying.
 */
export default function Index({ findings, filters = {}, summary = {}, options = {}, can = {} }) {
    const [raising, setRaising] = useState(false);
    const rows = findings?.data ?? [];

    const filter = (key, value) => router.get(
        tryRoute('bcms.findings.index'),
        { ...filters, [key]: value || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );

    const raise = useForm({
        source: 'gap_analysis', classification: 'observation', description: '',
        severity: 'medium', iso_clause_ref: '', affected_process_id: '',
    });

    const tiles = [
        { label: 'Open findings', value: summary.open },
        { label: 'Open nonconformities', value: summary.nonconformities_open },
        { label: 'Open actions', value: summary.actions_open },
        { label: 'Overdue actions', value: summary.actions_overdue, alarm: true },
        { label: 'Awaiting verification', value: summary.awaiting_verification },
    ];

    return (
        <AppLayout title="Findings & actions">
            <Head title="Findings & actions" />

            <PageHeader
                title="Findings and corrective actions"
                subtitle="Everything the programme has turned up, and what is being done about it."
                actions={can.manage && (
                    <button type="button" className="btn-primary text-sm" onClick={() => setRaising((v) => !v)}>
                        Raise a finding
                    </button>
                )}
            />

            <div className="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-5">
                {tiles.map((t) => (
                    <div key={t.label} className={`rounded-lg border p-4 ${t.alarm && t.value > 0 ? 'border-red-300 bg-red-50' : 'border-gray-200 bg-white'}`}>
                        <p className="text-xs font-medium uppercase tracking-wide text-gray-500">{t.label}</p>
                        <p className="mt-1 font-mono text-2xl text-gray-900">{t.value ?? 0}</p>
                    </div>
                ))}
            </div>

            {raising && can.manage && (
                <form
                    onSubmit={(e) => { e.preventDefault(); raise.post(tryRoute('bcms.findings.store'), { preserveScroll: true, onSuccess: () => raise.reset() }); }}
                    className="mb-6 space-y-4 rounded-lg border border-gray-200 bg-white p-6"
                >
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <label className="block text-sm">
                            <span className="text-gray-700">Source</span>
                            <select className="mt-1 w-full rounded border-gray-300 text-sm" value={raise.data.source}
                                onChange={(e) => raise.setData('source', e.target.value)}>
                                {(options.sources ?? []).map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
                            </select>
                        </label>
                        <label className="block text-sm">
                            <span className="text-gray-700">Classification</span>
                            <select className="mt-1 w-full rounded border-gray-300 text-sm" value={raise.data.classification}
                                onChange={(e) => raise.setData('classification', e.target.value)}>
                                {(options.classifications ?? []).map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
                            </select>
                        </label>
                        <label className="block text-sm">
                            <span className="text-gray-700">Severity</span>
                            <select className="mt-1 w-full rounded border-gray-300 text-sm" value={raise.data.severity}
                                onChange={(e) => raise.setData('severity', e.target.value)}>
                                {['low', 'medium', 'high', 'critical'].map((s) => <option key={s} value={s}>{s}</option>)}
                            </select>
                        </label>
                    </div>

                    <label className="block text-sm">
                        <span className="text-gray-700">What was found</span>
                        <textarea rows={3} className="mt-1 w-full rounded border-gray-300 text-sm" value={raise.data.description}
                            onChange={(e) => raise.setData('description', e.target.value)} />
                        {raise.errors.description && <span className="text-xs text-red-600">{raise.errors.description}</span>}
                    </label>

                    <label className="block text-sm">
                        <span className="text-gray-700">Clause it failed</span>
                        <select className="mt-1 w-full rounded border-gray-300 text-sm" value={raise.data.iso_clause_ref}
                            onChange={(e) => raise.setData('iso_clause_ref', e.target.value)}>
                            <option value="">Take it from the source</option>
                            {(options.clause_refs ?? []).map((c) => (
                                <option key={c.value} value={c.value}>{c.standard} — {c.value}</option>
                            ))}
                        </select>
                        <span className="mt-1 block text-xs text-gray-500">
                            Required for a nonconformity: clause 10.1 defines one as a failure to meet a stated
                            requirement, so it has to name the requirement.
                        </span>
                    </label>

                    <button type="submit" className="btn-primary text-sm" disabled={raise.processing}>Raise</button>
                </form>
            )}

            <div className="mb-4 flex flex-wrap gap-3">
                <select className="rounded border-gray-300 text-sm" value={filters.status ?? ''} onChange={(e) => filter('status', e.target.value)}>
                    <option value="">Any status</option>
                    {['open', 'in_progress', 'closed', 'accepted_risk'].map((s) => <option key={s} value={s}>{s}</option>)}
                </select>
                <select className="rounded border-gray-300 text-sm" value={filters.classification ?? ''} onChange={(e) => filter('classification', e.target.value)}>
                    <option value="">Any classification</option>
                    {(options.classifications ?? []).map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
                </select>
                <select className="rounded border-gray-300 text-sm" value={filters.source ?? ''} onChange={(e) => filter('source', e.target.value)}>
                    <option value="">Any source</option>
                    {(options.sources ?? []).map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
                </select>
                <select className="rounded border-gray-300 text-sm" value={filters.owner ?? ''} onChange={(e) => filter('owner', e.target.value)}>
                    <option value="">Any owner</option>
                    {(options.users ?? []).map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                </select>
                <select className="rounded border-gray-300 text-sm" value={filters.due ?? ''} onChange={(e) => filter('due', e.target.value)}>
                    <option value="">Any due date</option>
                    <option value="overdue">Overdue only</option>
                </select>
            </div>

            <div className="space-y-4">
                {rows.length === 0 && (
                    <p className="rounded-lg border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500">
                        No findings match. An empty register is not automatically good news — it usually means
                        nothing has been exercised yet.
                    </p>
                )}

                {rows.map((f) => (
                    <article key={f.id} className="rounded-lg border border-gray-200 bg-white p-5">
                        <header className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p className="font-mono text-xs text-gray-500">{f.reference} · {f.source_label}</p>
                                <p className="mt-1 text-sm text-gray-900">{f.description}</p>
                                <p className="mt-1 text-xs text-gray-500">
                                    {f.classification} · {f.severity ?? 'unrated'} · {f.iso_clause_ref}
                                    {f.process ? ` · ${f.process}` : ''}
                                    {f.erm_issue_id ? ' · mirrored to the issue register' : ''}
                                </p>
                            </div>
                            <span className={`rounded px-2 py-1 text-xs ${f.status === 'open' ? 'bg-amber-50 text-amber-800' : 'bg-gray-100 text-gray-700'}`}>
                                {f.status}
                            </span>
                        </header>

                        <div className="mt-4 space-y-2">
                            {f.actions.length === 0 && f.classification === 'nonconformity' && (
                                <p className="rounded bg-red-50 p-2 text-xs text-red-800">
                                    A nonconformity needs a corrective action. It cannot be closed without one
                                    (clause 10.1).
                                </p>
                            )}

                            {f.actions.map((a) => (
                                <div key={a.id}
                                    className={`flex flex-wrap items-center justify-between gap-3 rounded border p-3 text-sm ${a.is_overdue ? 'border-red-300 bg-red-50' : 'border-gray-200'}`}>
                                    <div>
                                        <span className="font-mono text-xs text-gray-500">{a.reference}</span>
                                        <span className="ml-2 text-gray-900">{a.title}</span>
                                        <span className="block text-xs text-gray-500">
                                            {a.owner ?? 'unassigned'} · due {a.due_date ?? 'not set'} · {a.status}
                                            {a.carried_to_occurrence_id ? ' · carried to a later exercise' : ''}
                                        </span>
                                    </div>
                                    <div className="flex gap-2">
                                        {can.manage && (a.status === 'open' || a.status === 'in_progress' || a.status === 'overdue') && (
                                            <button type="button" className="btn-secondary text-xs"
                                                onClick={() => router.post(tryRoute('bcms.actions.complete', a.id), {}, { preserveScroll: true })}>
                                                Mark complete
                                            </button>
                                        )}
                                        {can.verify && a.status === 'completed' && (
                                            <button type="button" className="btn-primary text-xs"
                                                onClick={() => router.post(tryRoute('bcms.actions.verify', a.id), {}, { preserveScroll: true })}>
                                                Verify
                                            </button>
                                        )}
                                    </div>
                                </div>
                            ))}

                            {can.manage && f.status === 'open' && (
                                <AddAction findingId={f.id} users={options.users ?? []} />
                            )}
                        </div>

                        {can.manage && f.status === 'open' && f.actions.length > 0 && (
                            <footer className="mt-3">
                                <button type="button" className="text-xs text-gray-600 underline"
                                    onClick={() => router.post(tryRoute('bcms.findings.close', f.id), {}, { preserveScroll: true })}>
                                    Close this finding
                                </button>
                            </footer>
                        )}
                    </article>
                ))}
            </div>

            {findings?.links && <Pagination links={findings.links} className="mt-6" />}
        </AppLayout>
    );
}

function AddAction({ findingId, users }) {
    const form = useForm({ title: '', owner_id: '', due_date: '', priority: 'medium' });

    return (
        <form
            onSubmit={(e) => { e.preventDefault(); form.post(tryRoute('bcms.actions.store', findingId), { preserveScroll: true, onSuccess: () => form.reset() }); }}
            className="flex flex-wrap items-end gap-2 rounded border border-dashed border-gray-300 p-3"
        >
            <input className="min-w-48 flex-1 rounded border-gray-300 text-sm" placeholder="Corrective action"
                value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} />
            <select className="rounded border-gray-300 text-sm" value={form.data.owner_id}
                onChange={(e) => form.setData('owner_id', e.target.value)}>
                <option value="">Owner</option>
                {users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
            </select>
            <input type="date" className="rounded border-gray-300 text-sm" value={form.data.due_date}
                onChange={(e) => form.setData('due_date', e.target.value)} />
            <button type="submit" className="btn-secondary text-sm" disabled={form.processing}>Add</button>
        </form>
    );
}
