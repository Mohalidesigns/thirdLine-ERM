import { Fragment, useState } from 'react';
import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';

/**
 * The Register of Information — fourteen tables and what is missing from each.
 *
 * THE COVERAGE COLUMN IS THE POINT OF THIS SCREEN. Anyone can download a
 * workbook; what a preparer needs before sending one to a supervisor is to
 * know which tables are complete, which are partial, and why — so the page
 * leads with that and puts the preview behind it.
 *
 * THE PREVIEW IS TEN ROWS. A register with thousands of supply-chain edges is
 * not shipped to a browser so somebody can check the shape of a table.
 */
export default function DoraRegister({ tables = [], provenance = {}, caveat = '', can = {} }) {
    const [open, setOpen] = useState(tables[0]?.code ?? null);
    const [reviewerId] = useState('');

    const partial = tables.filter((table) => table.coverage !== 'complete');
    const totalRows = tables.reduce((sum, table) => sum + (table.row_count ?? 0), 0);

    const exportUrl = (format, code) => {
        const params = new URLSearchParams({ format });
        if (code) params.set('table', code);
        if (reviewerId) params.set('reviewer_id', reviewerId);
        return `${route('tprm.reports.dora-register.export')}?${params.toString()}`;
    };

    return (
        <AppLayout title="Register of Information">
            <Head title="Register of Information" />

            <PageHeader
                title="Register of Information"
                subtitle="DORA Article 28(3), templates RT.01.01 to RT.07.01. Used as the internal register structure for Nigerian deployments, which exceeds the CBN minimum."
            />

            <div className="mb-4 rounded border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                <strong>Verify before an EU submission.</strong> {caveat}
            </div>

            <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <Tile label="Templates" value={tables.length} hint="RT.01.01 to RT.07.01" />
                <Tile label="Rows across the register" value={totalRows} hint="every table combined" />
                <Tile
                    label="Partial coverage"
                    value={partial.length}
                    tone={partial.length ? 'warn' : null}
                    hint="tables with a field this product does not hold"
                />
                <Tile label="Prepared by" value={provenance.prepared_by ?? '—'} hint={provenance.as_at_label} />
            </div>

            {can.export && (
                <div className="card mb-4 flex flex-wrap items-center gap-3 p-4">
                    <span className="text-sm font-medium text-gray-700">Export the whole register</span>
                    <a className="btn-primary" href={exportUrl('xlsx')}>XLSX workbook</a>
                    <a className="btn-secondary" href={exportUrl('pdf')}>Branded PDF</a>
                    <span className="text-xs text-gray-500">
                        CSV holds one table, so it is offered per template below.
                    </span>
                </div>
            )}

            <div className="card overflow-hidden">
                <table className="data-table">
                    <thead className="bg-gray-50">
                        <tr>
                            <th scope="col" className="text-left">Template</th>
                            <th scope="col" className="text-left">Title</th>
                            <th scope="col" className="text-right">Rows</th>
                            <th scope="col" className="text-left">Coverage</th>
                            <th scope="col" className="text-left">Note</th>
                            <th scope="col" />
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {tables.map((table) => (
                            <Fragment key={table.code}>
                                <tr>
                                    <td className="font-medium">
                                        <button
                                            type="button"
                                            className="text-indigo-600"
                                            onClick={() => setOpen(open === table.code ? null : table.code)}
                                        >
                                            {table.code}
                                        </button>
                                    </td>
                                    <td>{table.title}</td>
                                    <td className="text-right tabular-nums">{table.row_count}</td>
                                    <td>
                                        <span
                                            className={`rounded px-2 py-0.5 text-xs font-medium ${
                                                table.coverage === 'complete'
                                                    ? 'bg-emerald-50 text-emerald-700'
                                                    : 'bg-amber-50 text-amber-700'
                                            }`}
                                        >
                                            {table.coverage === 'complete' ? 'Complete' : 'Partial'}
                                        </span>
                                    </td>
                                    <td className="text-xs text-gray-600">{table.note ?? '—'}</td>
                                    <td className="text-right">
                                        {can.export && (
                                            <a className="text-xs text-indigo-600" href={exportUrl('csv', table.code)}>
                                                CSV
                                            </a>
                                        )}
                                    </td>
                                </tr>
                                {open === table.code && (
                                    <tr>
                                        <td colSpan={6} className="bg-gray-50">
                                            {table.row_count === 0 ? (
                                                <p className="text-sm text-gray-600">
                                                    No rows. Read that against the coverage note before treating it
                                                    as a statement that there is nothing to declare.
                                                </p>
                                            ) : (
                                                <div className="overflow-x-auto">
                                                    <p className="mb-2 text-xs text-gray-500">
                                                        First {Math.min(10, table.row_count)} of {table.row_count} rows.
                                                        The export holds all of them.
                                                    </p>
                                                    <table className="data-table text-xs">
                                                        <thead>
                                                            <tr>
                                                                {table.headers.map((header) => (
                                                                    <th
                                                                        key={header}
                                                                        scope="col"
                                                                        className="whitespace-nowrap text-left"
                                                                    >
                                                                        {header}
                                                                    </th>
                                                                ))}
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            {table.preview.map((row, index) => (
                                                                <tr key={index}>
                                                                    {row.map((value, cell) => (
                                                                        <td key={cell} className="whitespace-nowrap">
                                                                            {value === null || value === '' ? '—' : String(value)}
                                                                        </td>
                                                                    ))}
                                                                </tr>
                                                            ))}
                                                        </tbody>
                                                    </table>
                                                </div>
                                            )}
                                        </td>
                                    </tr>
                                )}
                            </Fragment>
                        ))}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}

function Tile({ label, value, hint, tone }) {
    const toneClass = tone === 'warn' ? 'text-amber-600' : 'text-gray-900';

    return (
        <div className="card p-4">
            <div className="text-xs font-medium uppercase tracking-wide text-gray-500">{label}</div>
            <div className={`mt-1 text-2xl font-bold ${toneClass}`}>{value}</div>
            {hint && <div className="form-hint">{hint}</div>}
        </div>
    );
}
