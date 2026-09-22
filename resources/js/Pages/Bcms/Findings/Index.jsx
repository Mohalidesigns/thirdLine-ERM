import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import Pagination from '@thirdline/ui/Components/Pagination';
import FormField from '@thirdline/ui/Components/FormField';
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
                    className="card mb-6"
                >
                    <div className="card-body space-y-4">
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <FormField label="Source">
                                <select className="form-select" value={raise.data.source}
                                    onChange={(e) => raise.setData('source', e.target.value)}>
                                    {(options.sources ?? []).map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
                                </select>
                            </FormField>
                            <FormField label="Classification">
                                <select className="form-select" value={raise.data.classification}
                                    onChange={(e) => raise.setData('classification', e.target.value)}>
                                    {(options.classifications ?? []).map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
                                </select>
                            </FormField>
                            <FormField label="Severity">
                                <select className="form-select" value={raise.data.severity}
                                    onChange={(e) => raise.setData('severity', e.target.value)}>
                                    {['low', 'medium', 'high', 'critical'].map((s) => <option key={s} value={s}>{s}</option>)}
                                </select>
                            </FormField>
                        </div>

                        <FormField label="What was found" error={raise.errors.description}>
                            <textarea rows={3} className="form-textarea" value={raise.data.description}
                                onChange={(e) => raise.setData('description', e.target.value)} />
                        </FormField>

                        <FormField label="Clause it failed"
                            hint="Required for a nonconformity: clause 10.1 defines one as a failure to meet a stated requirement, so it has to name the requirement.">
                            <select className="form-select" value={raise.data.iso_clause_ref}
                                onChange={(e) => raise.setData('iso_clause_ref', e.target.value)}>
                                <option value="">Take it from the source</option>
                                {(options.clause_refs ?? []).map((c) => (
                                    <option key={c.value} value={c.value}>{c.standard} — {c.value}</option>
                                ))}
                            </select>
                        </FormField>

                        <button type="submit" className="btn-primary text-sm" disabled={raise.processing}>Raise</button>
                    </div>
                </form>
            )}

            <div className="filter-bar">
                <div className="filter-bar-inner">
                    <div className="filter-group min-w-[140px]">
                        <label className="filter-label">Status</label>
                        <select aria-label="Status" className="filter-select" value={filters.status ?? ''} onChange={(e) => filter('status', e.target.value)}>
                            <option value="">Any status</option>
                            {['open', 'in_progress', 'closed', 'accepted_risk'].map((s) => <option key={s} value={s}>{s}</option>)}
                        </select>
                    </div>
                    <div className="filter-group min-w-[160px]">
                        <label className="filter-label">Classification</label>
                        <select aria-label="Classification" className="filter-select" value={filters.classification ?? ''} onChange={(e) => filter('classification', e.target.value)}>
                            <option value="">Any classification</option>
                            {(options.classifications ?? []).map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
                        </select>
                    </div>
                    <div className="filter-group min-w-[150px]">
                        <label className="filter-label">Source</label>
                        <select aria-label="Source" className="filter-select" value={filters.source ?? ''} onChange={(e) => filter('source', e.target.value)}>
                            <option value="">Any source</option>
                            {(options.sources ?? []).map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
                        </select>
                    </div>
                    <div className="filter-group min-w-[150px]">
                        <label className="filter-label">Owner</label>
                        <select aria-label="Owner" className="filter-select" value={filters.owner ?? ''} onChange={(e) => filter('owner', e.target.value)}>
                            <option value="">Any owner</option>
                            {(options.users ?? []).map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                        </select>
                    </div>
                    <div className="filter-group min-w-[150px]">
                        <label className="filter-label">Due date</label>
                        <select aria-label="Due date" className="filter-select" value={filters.due ?? ''} onChange={(e) => filter('due', e.target.value)}>
                            <option value="">Any due date</option>
                            <option value="overdue">Overdue only</option>
                        </select>
                    </div>
                </div>
            </div>

            <div className="space-y-4">
                {rows.length === 0 && (
                    <p className="rounded-lg border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500">
                        No findings match. An empty register is not automatically good news — it usually means
                        nothing has been exercised yet.
                    </p>
                )}

                {rows.map((f) => (
                    <article key={f.id} className="card card-body">
                        <header className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p className="font-mono text-xs text-gray-500">{f.reference} · {f.source_label}</p>
                                <p className="mt-1 text-sm text-gray-900">{f.description}</p>
                                <p className="form-hint">
                                    {f.classification} · {f.severity ?? 'unrated'} · {f.iso_clause_ref}
                                    {f.process ? ` · ${f.process}` : ''}
                                    {f.erm_issue_id ? ' · mirrored to the issue register' : ''}
                                </p>
                            </div>
                            {/* Open is the only state that needs attention — amber; every other
                                state (in progress, closed, accepted risk) is the resting grey.
                                The shared StatusBadge map would paint open green and closed
                                purple, and has no entry for accepted_risk. */}
                            <span className={`badge ${f.status === 'open' ? 'badge-medium' : 'badge-status-draft'}`}>
                                {f.status.replace(/_/g, ' ')}
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
                                                onClick={() => router.post(tryRoute('bcms.actions.complete', a.uuid), {}, { preserveScroll: true })}>
                                                Mark complete
                                            </button>
                                        )}
                                        {can.verify && a.status === 'completed' && (
                                            <button type="button" className="btn-primary text-xs"
                                                onClick={() => router.post(tryRoute('bcms.actions.verify', a.uuid), {}, { preserveScroll: true })}>
                                                Verify
                                            </button>
                                        )}
                                    </div>
                                </div>
                            ))}

                            {can.manage && f.status === 'open' && (
                                <AddAction storeActionUrl={f.store_action_url} users={options.users ?? []} />
                            )}
                        </div>

                        {can.manage && f.status === 'open' && f.actions.length > 0 && (
                            <footer className="mt-3">
                                <button type="button" className="text-xs text-gray-600 underline"
                                    onClick={() => router.post(tryRoute('bcms.findings.close', f.uuid), {}, { preserveScroll: true })}>
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

function AddAction({ storeActionUrl, users }) {
    const form = useForm({ title: '', owner_id: '', due_date: '', priority: 'medium' });

    return (
        <form
            onSubmit={(e) => { e.preventDefault(); form.post(storeActionUrl, { preserveScroll: true, onSuccess: () => form.reset() }); }}
            className="flex flex-wrap items-end gap-2 rounded border border-dashed border-gray-300 p-3"
        >
            <input className="form-input min-w-48 flex-1" placeholder="Corrective action" aria-label="Corrective action"
                value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} />
            <select className="form-select" aria-label="Owner" value={form.data.owner_id}
                onChange={(e) => form.setData('owner_id', e.target.value)}>
                <option value="">Owner</option>
                {users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
            </select>
            <input type="date" className="form-input" aria-label="Due date" value={form.data.due_date}
                onChange={(e) => form.setData('due_date', e.target.value)} />
            <button type="submit" className="btn-secondary text-sm" disabled={form.processing}>Add</button>
        </form>
    );
}
