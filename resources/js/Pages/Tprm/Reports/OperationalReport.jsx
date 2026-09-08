import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';

/**
 * One operational report — FR-RPT-07.
 *
 * THE PREVIEW IS CAPPED AND THE CAP IS STATED. A screening log can run to tens
 * of thousands of rows; shipping them all to a browser so somebody can glance
 * at the first screen would make the page unusable on exactly the estates that
 * need it most. The export holds everything, and the caption says how many
 * rows are not on screen rather than letting the table end quietly.
 */
export default function OperationalReport({ report = {}, provenance = {}, can = {} }) {
    const rows = report.rows ?? [];
    const hidden = (report.row_count ?? 0) - rows.length;

    const exportUrl = (format) =>
        `${route('tprm.reports.operational.export', report.key)}?format=${format}`;

    return (
        <AppLayout title={report.title}>
            <Head title={report.title} />

            <PageHeader title={report.title} subtitle={report.description} />

            <div className="mb-4 flex flex-wrap items-center gap-3">
                <Link className="btn-secondary" href={route('tprm.reports.operational')}>
                    All reports
                </Link>
                {can.export && (
                    <>
                        <a className="btn-primary" href={exportUrl('xlsx')}>XLSX</a>
                        <a className="btn-secondary" href={exportUrl('csv')}>CSV</a>
                        <a className="btn-secondary" href={exportUrl('pdf')}>Branded PDF</a>
                    </>
                )}
                <span className="text-xs text-gray-500">
                    {report.row_count} {report.row_count === 1 ? 'row' : 'rows'}, as at {provenance.as_at_label}.
                </span>
            </div>

            <div className="card mb-4 p-4 text-xs text-gray-600">
                <dl className="grid grid-cols-1 gap-x-6 gap-y-1 md:grid-cols-2">
                    {Object.entries(provenance.filters ?? {}).map(([label, value]) => (
                        <div key={label} className="flex gap-2">
                            <dt className="text-gray-500">{label}:</dt>
                            <dd className="font-medium text-gray-700">{value}</dd>
                        </div>
                    ))}
                </dl>
            </div>

            <div className="card overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200 text-sm">
                    <caption className="px-4 py-2 text-left text-xs text-gray-500">
                        {hidden > 0
                            ? `Showing the first ${rows.length} of ${report.row_count} rows. The export holds all of them.`
                            : `All ${report.row_count} rows.`}
                    </caption>
                    <thead className="bg-gray-50">
                        <tr>
                            {(report.headers ?? []).map((header) => (
                                <th
                                    key={header}
                                    scope="col"
                                    className="whitespace-nowrap px-4 py-2 text-left font-medium text-gray-600"
                                >
                                    {header}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {rows.map((row, index) => (
                            <tr key={index}>
                                {row.map((cell, cellIndex) => (
                                    <td key={cellIndex} className="whitespace-nowrap px-4 py-2 align-top">
                                        {cell === null || cell === undefined || cell === '' ? (
                                            <span className="text-gray-400">—</span>
                                        ) : (
                                            String(cell)
                                        )}
                                    </td>
                                ))}
                            </tr>
                        ))}
                        {rows.length === 0 && (
                            <tr>
                                <td
                                    colSpan={(report.headers ?? []).length}
                                    className="px-4 py-10 text-center text-sm text-gray-500"
                                >
                                    No rows. Read that against the scope above before treating it as a statement
                                    that there is nothing outstanding.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
