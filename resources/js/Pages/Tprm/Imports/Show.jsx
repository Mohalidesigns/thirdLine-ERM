import { useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * The column-mapping wizard and the dry-run results — FR-TPR-09.
 *
 * THE DRY RUN IS THE FEATURE. A bank arrives with several hundred rows in
 * whatever shape its procurement system emits, and whether the migration
 * happens at all depends on whether the first attempt says precisely what is
 * wrong with row 214 or merely fails. So every problem carries its row number,
 * its column and its reason, and the row number is the one in the user's own
 * spreadsheet.
 *
 * Three severities, and the difference is what happens on commit: an `error`
 * blocks the row, a `warning` imports it with less than the user intended, and
 * a `duplicate` imports it flagged — FR-TPR-02 refuses to block on a
 * near-match, and a 400-row file that fails because three vendors already
 * exist is a file the user abandons.
 */
export default function Show({ batch, inspection, columns = [] }) {
    const [mapping, setMapping] = useState(batch.mapping ?? inspection?.suggestion ?? {});
    const { post, processing } = useForm({});

    const headers = inspection?.headers ?? [];
    const problems = batch.errors ?? [];

    const bySeverity = (severity) => problems.filter((p) => p.severity === severity);

    const runDryRun = () => {
        router.post(tryRoute('tprm.imports.dry-run', batch.id), { mapping }, { preserveScroll: true });
    };

    return (
        <AppLayout title={batch.filename}>
            <Head title={`Import — ${batch.filename}`} />

            <PageHeader
                title={batch.filename}
                subtitle="Map the columns, validate, then commit. Nothing is written until you commit."
                actions={
                    <div className="flex items-center gap-2">
                        {batch.can_commit && (
                            <button type="button" disabled={processing}
                                onClick={() => router.post(tryRoute('tprm.imports.commit', batch.id), {}, { preserveScroll: true })}
                                className="btn-primary text-sm">
                                Import {batch.rows_valid} rows
                            </button>
                        )}
                        {batch.can_roll_back && (
                            <button type="button"
                                onClick={() => router.post(tryRoute('tprm.imports.roll-back', batch.id), {}, { preserveScroll: true })}
                                className="btn-secondary text-sm">
                                Roll back
                            </button>
                        )}
                    </div>
                }
            />

            {inspection?.error && (
                <div className="card mb-6 border-l-4 border-red-500 p-5 text-sm text-red-800">
                    <p className="font-semibold">The file could not be read</p>
                    <p className="mt-1 text-xs">{inspection.error}</p>
                    <p className="mt-2 text-xs">Saving it as CSV and uploading again usually resolves this.</p>
                </div>
            )}

            {batch.status === 'committed' && (
                <div className="card mb-6 border-l-4 border-green-500 p-4 text-sm text-green-900">
                    {batch.created_count} third parties were imported on {batch.committed_at}.
                    {batch.can_roll_back && ' This can still be rolled back.'}
                </div>
            )}

            {headers.length > 0 && batch.status !== 'committed' && batch.status !== 'rolled_back' && (
                <div className="card mb-6 p-5">
                    <h3 className="text-sm font-semibold text-gray-900">Map the columns</h3>
                    <p className="mt-1 text-xs text-gray-500">
                        A suggestion is filled in where the header was recognisable. Check it — a column headed
                        “Name” could be the company or the contact, and guessing wrong writes a person into the
                        register as a vendor.
                    </p>

                    <div className="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                        {columns.map((column) => (
                            <div key={column.key}>
                                <label className="block text-sm font-medium text-gray-700">
                                    {column.label}
                                    {column.required && <span className="ml-0.5 text-red-600">*</span>}
                                </label>
                                {column.hint && <p className="text-xs text-gray-500">{column.hint}</p>}
                                <select
                                    value={mapping[column.key] ?? ''}
                                    onChange={(e) => setMapping({ ...mapping, [column.key]: e.target.value || null })}
                                    className="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm"
                                >
                                    <option value="">Not mapped</option>
                                    {headers.map((header) => <option key={header} value={header}>{header}</option>)}
                                </select>
                            </div>
                        ))}
                    </div>

                    <button type="button" onClick={runDryRun} className="btn-secondary mt-4 text-sm">
                        Validate without importing
                    </button>
                </div>
            )}

            {batch.status === 'validated' && (
                <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <Tile label="Rows in file" value={batch.rows_total} />
                    <Tile label="Will import" value={batch.rows_valid} tone="good" />
                    <Tile label="Blocked" value={batch.rows_failed} tone={batch.rows_failed ? 'bad' : null} />
                    <Tile label="Possible duplicates" value={bySeverity('duplicate').length} tone={bySeverity('duplicate').length ? 'warn' : null} />
                </div>
            )}

            {problems.length > 0 && (
                <div className="card overflow-hidden">
                    <div className="border-b border-gray-100 px-5 py-3">
                        <h3 className="text-sm font-semibold text-gray-900">
                            {problems.length} thing{problems.length === 1 ? '' : 's'} to look at
                        </h3>
                    </div>
                    <table className="w-full text-sm">
                        <thead className="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th className="px-4 py-2 font-medium">Row</th>
                                <th className="px-4 py-2 font-medium">Column</th>
                                <th className="px-4 py-2 font-medium">Severity</th>
                                <th className="px-4 py-2 font-medium">What is wrong</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {problems.map((problem, i) => (
                                <tr key={i}>
                                    {/* The row number in the user's own spreadsheet. */}
                                    <td className="px-4 py-2 font-mono text-xs">{problem.row}</td>
                                    <td className="px-4 py-2 text-xs text-gray-600">{problem.column}</td>
                                    <td className="px-4 py-2">
                                        <SeverityChip severity={problem.severity} />
                                    </td>
                                    <td className="px-4 py-2 text-xs text-gray-700">{problem.message}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {inspection?.sample?.length > 0 && batch.status === 'draft' && (
                <div className="card mt-6 overflow-x-auto p-5">
                    <h3 className="mb-3 text-sm font-semibold text-gray-900">First rows of the file</h3>
                    <table className="w-full text-xs">
                        <thead className="text-left text-gray-500">
                            <tr>{headers.map((h) => <th key={h} className="whitespace-nowrap px-2 py-1 font-medium">{h}</th>)}</tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {inspection.sample.map((row, i) => (
                                <tr key={i}>
                                    {row.map((cell, j) => <td key={j} className="whitespace-nowrap px-2 py-1 text-gray-700">{cell}</td>)}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </AppLayout>
    );
}

function SeverityChip({ severity }) {
    const map = {
        error: ['Blocks the row', 'bg-red-100 text-red-800'],
        warning: ['Imports with less', 'bg-amber-100 text-amber-800'],
        duplicate: ['Possible duplicate', 'bg-blue-100 text-blue-800'],
    };
    const [label, classes] = map[severity] ?? [severity, 'bg-gray-100 text-gray-700'];

    return <span className={`whitespace-nowrap rounded px-1.5 py-0.5 text-xs font-medium ${classes}`}>{label}</span>;
}

function Tile({ label, value, tone }) {
    const colour = tone === 'good' ? 'text-green-700' : tone === 'bad' ? 'text-red-700' : tone === 'warn' ? 'text-amber-700' : 'text-gray-900';

    return (
        <div className="card p-4">
            <p className="text-xs font-medium uppercase tracking-wide text-gray-500">{label}</p>
            <p className={`mt-1 text-2xl font-semibold ${colour}`}>{value ?? 0}</p>
        </div>
    );
}
