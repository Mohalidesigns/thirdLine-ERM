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
                    <p className="mt-1 text-xs text-gray-500">
                        Columns, in this order: {columns.join(' · ')}. Check the file first — nothing is written
                        until every row passes.
                    </p>
                    <input
                        type="file"
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

            <div className="mb-4 flex flex-wrap gap-3">
                <input
                    className="w-64 rounded border-gray-300 text-sm"
                    placeholder="Search name, code or description"
                    defaultValue={filters.search ?? ''}
                    onKeyDown={(e) => { if (e.key === 'Enter') filter('search', e.target.value); }}
                />
                <select className="rounded border-gray-300 text-sm" value={filters.tier ?? ''} onChange={(e) => filter('tier', e.target.value)}>
                    <option value="">Any tier</option>
                    {[1, 2, 3, 4].map((t) => <option key={t} value={t}>Tier {t}</option>)}
                </select>
                <select className="rounded border-gray-300 text-sm" value={filters.unit ?? ''} onChange={(e) => filter('unit', e.target.value)}>
                    <option value="">Any business unit</option>
                    {units.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                </select>
                <select className="rounded border-gray-300 text-sm" value={filters.critical ?? ''} onChange={(e) => filter('critical', e.target.value)}>
                    <option value="">All processes</option>
                    <option value="yes">Critical services only</option>
                </select>
                <select className="rounded border-gray-300 text-sm" value={filters.gap ?? ''} onChange={(e) => filter('gap', e.target.value)}>
                    <option value="">No gap filter</option>
                    <option value="no_accountable">Nobody accountable</option>
                </select>
            </div>

            {gaps && (
                <p className="mb-4 text-sm text-gray-600">
                    {gaps.total} active process(es) · {gaps.without_accountable.length} with nobody accountable ·
                    {' '}{gaps.without_responsible.length} with nobody responsible ·
                    {' '}{gaps.inactive_accountable.length} accountable to somebody who has left
                </p>
            )}

            <div className="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                <table className="w-full text-sm">
                    <thead className="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th className="px-4 py-3">Code</th>
                            <th className="px-4 py-3">Process</th>
                            <th className="px-4 py-3">Business unit</th>
                            <th className="px-4 py-3">Tier</th>
                            <th className="px-4 py-3">Owner</th>
                            <th className="px-4 py-3">Accountable</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {rows.length === 0 && (
                            <tr><td colSpan={6} className="px-4 py-8 text-center text-gray-500">
                                No processes match. The catalogue is where every later phase binds, so it is the first
                                thing to populate — by hand or from a spreadsheet.
                            </td></tr>
                        )}
                        {rows.map((p) => {
                            const accountable = accountableOf(p);

                            return (
                                <tr key={p.id}>
                                    <td className="px-4 py-3 font-mono text-xs text-gray-700">{p.code}</td>
                                    <td className="px-4 py-3">
                                        <span className="text-gray-900">{p.name}</span>
                                        {p.is_critical_service && (
                                            <span className="ml-2 rounded bg-purple-50 px-1.5 py-0.5 text-[11px] text-purple-800"
                                                title={p.critical_service_justification ?? undefined}>
                                                critical service
                                            </span>
                                        )}
                                        {p.parent && <span className="block text-xs text-gray-400">under {p.parent}</span>}
                                    </td>
                                    <td className="px-4 py-3 text-gray-600">{p.unit ?? '—'}</td>
                                    <td className="px-4 py-3">
                                        {p.tier ? (
                                            <span className={`rounded px-2 py-0.5 text-xs ${TIER_STYLE[p.tier] ?? ''}`}>Tier {p.tier}</span>
                                        ) : <span className="text-xs text-gray-400">not tiered</span>}
                                    </td>
                                    <td className="px-4 py-3 text-gray-600">{p.owner ?? '—'}</td>
                                    <td className="px-4 py-3">
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

            {processes?.links && <Pagination links={processes.links} className="mt-4" />}
        </AppLayout>
    );
}
