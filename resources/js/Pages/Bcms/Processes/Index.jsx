import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import Pagination from '@thirdline/ui/Components/Pagination';
import tryRoute from '@thirdline/ui/lib/tryRoute';

const TIER_STYLE = {
    1: 'bg-red-50 text-red-800',
    2: 'bg-amber-50 text-amber-800',
    3: 'bg-sky-50 text-sky-800',
    4: 'bg-gray-100 text-gray-700',
};

/**
 * The process catalogue (Blueprint §15, screen 2).
 *
 * THE ACCOUNTABLE COLUMN IS THE POINT OF THE TABLE. `owner` is who runs a
 * process day to day; `A` is who answers for it, and they are frequently not the
 * same person. A row with no A is highlighted, and one whose A has left is
 * highlighted differently — that second gap is the one that hides, because the
 * matrix still looks complete.
 *
 * THE IMPORT IS DRY-RUN FIRST AND SAYS SO. An import that half-succeeds leaves a
 * customer reconciling by hand; this one validates every row, reports what is
 * wrong with a spreadsheet row number they can find in Excel, and writes nothing
 * until the file is clean.
 */
export default function Index({
    processes, filters = {}, gaps, business_units: units = [], source_processes: sources = [],
    users = [], raci_roles: raciRoles = [], columns = [], can = {},
}) {
    const { props } = usePage();
    const preview = props.flash?.bcms_import_preview ?? null;

    const [showImport, setShowImport] = useState(false);
    const rows = processes?.data ?? [];

    const filter = (key, value) => router.get(
        tryRoute('bcms.processes.index'),
        { ...filters, [key]: value || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );

    const importForm = useForm({ file: null });

    const accountableOf = (process) => process.raci.find((r) => r.role === 'A');

    return (
        <AppLayout title="Process catalogue">
            <Head title="Process catalogue" />

            <PageHeader
                title="Process catalogue"
                subtitle="The prioritised activities the BCMS protects. Everything downstream — the BIA, the calendar, the plans — binds to this list."
                actions={
                    <div className="flex gap-2">
                        <a href={tryRoute('bcms.processes.export')} className="btn-secondary text-sm">Export</a>
                        {can.manage && (
                            <button type="button" className="btn-secondary text-sm" onClick={() => setShowImport((v) => !v)}>
                                Import
                            </button>
                        )}
                    </div>
                }
            />

            {showImport && can.manage && (
                <div className="mb-6 rounded-lg border border-gray-200 bg-white p-6">
                    <h2 className="text-sm font-semibold text-gray-900">Import from a spreadsheet</h2>
                    <p className="form-hint">
                        Columns, in this order: {columns.join(' · ')}. Check the file first — nothing is written
                        until every row passes.
                    </p>
                    <input
                        type="file"
                        aria-label="Import file"
                        className="mt-3 block text-sm"
                        onChange={(e) => importForm.setData('file', e.target.files[0])}
                    />
                    <div className="mt-3 flex gap-2">
                        <button type="button" className="btn-secondary text-sm"
                            onClick={() => importForm.post(tryRoute('bcms.processes.import.dry-run'), { forceFormData: true, preserveScroll: true })}>
                            Check the file
                        </button>
                        <button type="button" className="btn-primary text-sm"
                            onClick={() => importForm.post(tryRoute('bcms.processes.import'), { forceFormData: true, preserveScroll: true })}>
                            Import
                        </button>
                    </div>

                    {preview && (
                        <div className="mt-4 rounded border border-gray-200 p-3 text-sm">
                            <p className="font-medium text-gray-900">
                                {preview.valid} row(s) would import; {preview.invalid} would fail.
                            </p>
                            {preview.errors.length > 0 && (
                                <ul className="mt-2 max-h-64 space-y-1 overflow-y-auto text-xs text-red-700">
                                    {preview.errors.map((err, i) => (
                                        <li key={i}>Row {err.row}, {err.column}: {err.message}</li>
                                    ))}
                                </ul>
                            )}
                            {preview.total_errors > preview.errors.length && (
                                <p className="mt-2 text-xs text-gray-500">
                                    Showing the first {preview.errors.length} of {preview.total_errors}.
                                </p>
                            )}
                        </div>
                    )}
                </div>
            )}

            <div className="filter-bar">
                <div className="filter-bar-inner">
                    <div className="filter-group flex-1 min-w-[220px]">
                        <label className="filter-label">Search</label>
                        <input aria-label="Search"
                            className="filter-input"
                            placeholder="Search name, code or description"
                            defaultValue={filters.search ?? ''}
                            onKeyDown={(e) => { if (e.key === 'Enter') filter('search', e.target.value); }}
                        />
                    </div>
                    <div className="filter-group min-w-[120px]">
                        <label className="filter-label">Tier</label>
                        <select aria-label="Tier" className="filter-select" value={filters.tier ?? ''} onChange={(e) => filter('tier', e.target.value)}>
                            <option value="">Any tier</option>
                            {[1, 2, 3, 4].map((t) => <option key={t} value={t}>Tier {t}</option>)}
                        </select>
                    </div>
                    <div className="filter-group min-w-[170px]">
                        <label className="filter-label">Business unit</label>
                        <select aria-label="Business unit" className="filter-select" value={filters.unit ?? ''} onChange={(e) => filter('unit', e.target.value)}>
                            <option value="">Any business unit</option>
                            {units.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                        </select>
                    </div>
                    <div className="filter-group min-w-[170px]">
                        <label className="filter-label">Critical services</label>
                        <select aria-label="Critical services" className="filter-select" value={filters.critical ?? ''} onChange={(e) => filter('critical', e.target.value)}>
                            <option value="">All processes</option>
                            <option value="yes">Critical services only</option>
                        </select>
                    </div>
                    <div className="filter-group min-w-[170px]">
                        <label className="filter-label">Gaps</label>
                        <select aria-label="Gaps" className="filter-select" value={filters.gap ?? ''} onChange={(e) => filter('gap', e.target.value)}>
                            <option value="">No gap filter</option>
                            <option value="no_accountable">Nobody accountable</option>
                        </select>
                    </div>
                </div>
            </div>

            {gaps && (
                <p className="mb-4 text-sm text-gray-600">
                    {gaps.total} active process(es) · {gaps.without_accountable.length} with nobody accountable ·
                    {' '}{gaps.without_responsible.length} with nobody responsible ·
                    {' '}{gaps.inactive_accountable.length} accountable to somebody who has left
                </p>
            )}

            <div className="card">
                <div className="overflow-x-auto">
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Process</th>
                                <th>Business unit</th>
                                <th>Tier</th>
                                <th>Owner</th>
                                <th>Accountable</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 && (
                                <tr><td colSpan={6} className="text-center py-12">
                                    <div className="text-gray-400">
                                        <p className="text-sm font-medium">No processes match</p>
                                        <p className="text-xs mt-1">
                                            The catalogue is where every later phase binds, so it is the first thing to
                                            populate — by hand or from a spreadsheet.
                                        </p>
                                    </div>
                                </td></tr>
                            )}
                            {rows.map((p) => {
                                const accountable = accountableOf(p);

                                return (
                                    <tr key={p.id}>
                                        <td className="cell-id">{p.code}</td>
                                        <td>
                                            <span className="cell-title">{p.name}</span>
                                            {p.is_critical_service && (
                                                <span className="ml-2 rounded bg-purple-50 px-1.5 py-0.5 text-[11px] text-purple-800"
                                                    title={p.critical_service_justification ?? undefined}>
                                                    critical service
                                                </span>
                                            )}
                                            {p.parent && <p className="cell-subtitle">under {p.parent}</p>}
                                        </td>
                                        <td className="cell-muted">{p.unit ?? '—'}</td>
                                        <td>
                                            {p.tier ? (
                                                <span className={`rounded px-2 py-0.5 text-xs ${TIER_STYLE[p.tier] ?? ''}`}>Tier {p.tier}</span>
                                            ) : <span className="text-xs text-gray-400">not tiered</span>}
                                        </td>
                                        <td className="cell-muted">{p.owner ?? '—'}</td>
                                        <td>
                                            {!accountable && <span className="rounded bg-amber-50 px-2 py-0.5 text-xs text-amber-800">nobody</span>}
                                            {accountable && !accountable.active && (
                                                <span className="rounded bg-red-50 px-2 py-0.5 text-xs text-red-800">
                                                    {accountable.name} — has left
                                                </span>
                                            )}
                                            {accountable && accountable.active && <span className="text-gray-700">{accountable.name}</span>}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
                {processes?.links && processes.links.length > 3 && (
                    <div className="px-4 py-3 border-t border-gray-100">
                        <Pagination links={processes.links} />
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
