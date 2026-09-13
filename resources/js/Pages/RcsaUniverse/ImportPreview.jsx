import { useEffect, useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import Pagination from '@thirdline/ui/Components/Pagination';

/**
 * The import preview (plan §7.2) — the screen that makes bulk upload
 * trustworthy enough for a bank to actually use.
 *
 * NOTHING ON THIS PAGE HAS BEEN WRITTEN TO THE UNIVERSE. The file has been
 * parsed, normalised, checked and staged; publishing is a second, deliberate
 * act taken from the footer here. The tiles, the tabs and the grid all exist to
 * answer one question before that click: what exactly is about to happen?
 */

const TABS = [
    { key: '', label: 'All rows', tone: 'text-gray-700' },
    { key: 'valid', label: 'Ready', tone: 'text-green-700' },
    { key: 'warning', label: 'Warnings', tone: 'text-amber-700' },
    { key: 'duplicate', label: 'Duplicates', tone: 'text-blue-700' },
    { key: 'error', label: 'Errors', tone: 'text-red-700' },
];

const STATUS_TONE = {
    valid: 'bg-green-100 text-green-800',
    warning: 'bg-amber-100 text-amber-800',
    duplicate: 'bg-blue-100 text-blue-800',
    error: 'bg-red-100 text-red-800',
};

// The batch is still being parsed on the queue; poll until it settles. Inertia
// reloads only the props that changed, so this is cheap.
const POLL_MS = 2500;

export default function ImportPreview({ batch, rows, filters = {}, columns = [] }) {
    const { flash } = usePage().props;

    const [editing, setEditing] = useState(null);
    const [mode, setMode] = useState('create');
    const [validOnly, setValidOnly] = useState(false);

    const inFlight = batch.status === 'queued' || batch.status === 'parsing';

    useEffect(() => {
        if (!inFlight) return undefined;

        const timer = window.setInterval(() => {
            router.reload({ only: ['batch', 'rows'] });
        }, POLL_MS);

        return () => window.clearInterval(timer);
    }, [inFlight]);

    const publish = () => {
        const warning =
            batch.error_rows > 0 && validOnly
                ? `Publish the ${batch.total_rows - batch.error_rows} rows that passed and leave the ${batch.error_rows} with errors behind?`
                : `Publish ${batch.total_rows} rows into the RCSA Universe?`;

        if (!window.confirm(warning)) return;

        router.post(
            route('rcsa.imports.publish', batch.id),
            { mode, valid_only: validOnly },
            { preserveScroll: true },
        );
    };

    const discard = () => {
        if (!window.confirm('Discard this upload? Nothing has been added to the universe, so nothing is lost.')) return;

        router.delete(route('rcsa.imports.destroy', batch.id));
    };

    const tab = (key) =>
        router.get(
            route('rcsa.imports.show', batch.id),
            key ? { status: key } : {},
            { preserveState: true, preserveScroll: true },
        );

    const countFor = (key) =>
        ({
            '': batch.total_rows,
            valid: batch.valid_rows,
            warning: batch.warning_rows,
            duplicate: batch.duplicate_rows,
            error: batch.error_rows,
        })[key] ?? 0;

    return (
        <AppLayout
            header={
                <PageHeader
                    title="Check your upload"
                    subtitle={batch.original_name}
                    breadcrumbs={[
                        { label: 'RCSA Universe', href: route('rcsa.universe.index') },
                        { label: 'Import' },
                    ]}
                    actions={
                        <div className="flex items-center gap-2">
                            <a
                                href={route('rcsa.imports.errors', batch.id)}
                                className="btn-secondary inline-flex items-center gap-2 text-sm"
                            >
                                Download annotated workbook
                            </a>
                            <button type="button" onClick={discard} className="btn-secondary text-sm">
                                Discard
                            </button>
                        </div>
                    }
                />
            }
        >
            <Head title="Check your upload" />

            {flash?.success && (
                <div className="mb-4 rounded-lg border border-green-200 bg-green-50 p-4 text-sm font-medium text-green-800">
                    {flash.success}
                </div>
            )}
            {flash?.error && (
                <div className="mb-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm font-medium text-red-800">
                    {flash.error}
                </div>
            )}

            {batch.status === 'failed' && (
                <div className="mb-4 rounded-lg border border-red-200 bg-red-50 p-4">
                    <p className="text-sm font-semibold text-red-800">This file could not be read.</p>
                    <p className="mt-1 text-sm text-red-700">{batch.failure_reason}</p>
                </div>
            )}

            {inFlight && (
                <div className="mb-4 flex items-center gap-3 rounded-lg border border-blue-200 bg-blue-50 p-4">
                    <svg className="h-5 w-5 animate-spin text-blue-600" fill="none" viewBox="0 0 24 24">
                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                        <path
                            className="opacity-75"
                            fill="currentColor"
                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                        />
                    </svg>
                    <p className="text-sm text-blue-900">
                        Checking your file. Nothing has been added to the universe.
                    </p>
                </div>
            )}

            {/* Summary tiles */}
            <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-5">
                <Tile label="Rows in file" value={batch.total_rows} />
                <Tile label="Ready to create" value={batch.valid_rows} tone="text-green-700" />
                <Tile label="With warnings" value={batch.warning_rows} tone="text-amber-700" />
                <Tile label="Already in universe" value={batch.duplicate_rows} tone="text-blue-700" />
                <Tile label="With errors" value={batch.error_rows} tone="text-red-700" />
            </div>

            {/* Tabs */}
            <div className="mb-4 flex flex-wrap gap-2">
                {TABS.map((t) => (
                    <button
                        key={t.key || 'all'}
                        type="button"
                        onClick={() => tab(t.key)}
                        className={`rounded-md px-3 py-1.5 text-sm ${
                            (filters.status ?? '') === t.key
                                ? 'bg-[var(--color-primary)] text-white'
                                : `bg-gray-100 hover:bg-gray-200 ${t.tone}`
                        }`}
                    >
                        {t.label}
                        <span className="ml-2 text-xs opacity-75">{countFor(t.key)}</span>
                    </button>
                ))}
            </div>

            <div className="card">
                <div className="overflow-x-auto">
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th className="w-16">Row</th>
                                <th className="w-24">Status</th>
                                <th>Business Unit</th>
                                <th>Potential Risk</th>
                                <th>Category</th>
                                <th>Existing Control</th>
                                <th>What is wrong</th>
                                <th className="w-16" />
                            </tr>
                        </thead>
                        <tbody>
                            {(rows?.data ?? []).length === 0 && (
                                <tr>
                                    <td colSpan={8} className="py-10 text-center text-sm text-gray-400">
                                        {inFlight ? 'Still checking…' : 'No rows in this view.'}
                                    </td>
                                </tr>
                            )}

                            {(rows?.data ?? []).map((row) => (
                                <tr key={row.id}>
                                    <td className="font-mono text-xs text-gray-500">{row.row_number}</td>
                                    <td>
                                        <span className={`badge ${STATUS_TONE[row.status] ?? ''}`}>{row.status}</span>
                                    </td>
                                    <td className="text-sm text-gray-700">{row.raw.business_unit || '—'}</td>
                                    <td>
                                        <p className="max-w-[280px] truncate text-sm text-gray-700">
                                            {row.raw.potential_risk || '—'}
                                        </p>
                                    </td>
                                    <td className="text-sm text-gray-600">{row.raw.risk_category || '—'}</td>
                                    <td>
                                        <p className="max-w-[220px] truncate text-sm text-gray-600">
                                            {row.raw.existing_control || '—'}
                                        </p>
                                    </td>
                                    <td>
                                        {row.errors.length === 0 ? (
                                            <span className="text-xs text-gray-400">Nothing</span>
                                        ) : (
                                            <ul className="space-y-0.5 text-xs">
                                                {row.errors.map((error, index) => (
                                                    <li
                                                        key={index}
                                                        className={
                                                            error.severity === 'error'
                                                                ? 'text-red-700'
                                                                : error.severity === 'duplicate'
                                                                  ? 'text-blue-700'
                                                                  : 'text-amber-700'
                                                        }
                                                    >
                                                        {error.message}
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </td>
                                    <td className="text-right">
                                        {batch.is_publishable && (
                                            <button
                                                type="button"
                                                onClick={() => setEditing(row)}
                                                className="text-xs text-[var(--color-primary)] hover:underline"
                                            >
                                                Fix
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {rows?.links?.length > 3 && (
                    <div className="border-t border-gray-100 px-4 py-3">
                        <Pagination links={rows.links} />
                    </div>
                )}
            </div>

            {/* Publish */}
            {batch.is_publishable && (
                <div className="mt-4 rounded-lg border border-gray-200 bg-white p-4">
                    <h3 className="text-sm font-semibold text-gray-800">Publish into the universe</h3>

                    <div className="mt-3 flex flex-wrap items-end gap-4">
                        <div className="filter-group min-w-[220px]">
                            <label className="filter-label">Rows already in the universe</label>
                            <select className="filter-select" value={mode} onChange={(e) => setMode(e.target.value)}>
                                <option value="create">Skip them — add only what is new</option>
                                <option value="create_update">Update them, and add what is new</option>
                                <option value="update">Update them only — add nothing</option>
                            </select>
                        </div>

                        {batch.error_rows > 0 && (
                            <label className="flex items-center gap-2 pb-2 text-sm text-gray-700">
                                <input
                                    type="checkbox"
                                    checked={validOnly}
                                    onChange={(e) => setValidOnly(e.target.checked)}
                                    className="rounded border-gray-300"
                                />
                                Publish the valid rows only, and leave the {batch.error_rows} with errors behind
                            </label>
                        )}

                        <button
                            type="button"
                            onClick={publish}
                            disabled={batch.error_rows > 0 && !validOnly}
                            className="btn-primary text-sm disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            Publish
                        </button>
                    </div>

                    {batch.error_rows > 0 && !validOnly && (
                        <p className="mt-3 text-sm text-amber-700">
                            {batch.error_rows} rows have errors, so publishing is blocked. Fix them here, download the
                            annotated workbook and re-upload, or tick the box above to publish the rest.
                        </p>
                    )}

                    <p className="mt-3 text-xs text-gray-500">
                        Imported risks arrive as <strong>drafts</strong>. They enter an assessment only once someone
                        publishes them on the universe screen.
                    </p>
                </div>
            )}

            {batch.status === 'published' && (
                <div className="mt-4 rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800">
                    Published: {batch.created_count} created, {batch.updated_count} updated, {batch.skipped_count}{' '}
                    skipped.{' '}
                    <Link href={route('rcsa.universe.index')} className="underline">
                        Go to the universe
                    </Link>
                    .
                </div>
            )}

            {editing && (
                <FixRowPanel
                    batch={batch}
                    row={editing}
                    columns={columns}
                    onClose={() => setEditing(null)}
                />
            )}
        </AppLayout>
    );
}

function Tile({ label, value, tone = 'text-gray-800' }) {
    return (
        <div className="rounded-lg border border-gray-200 bg-white p-4">
            <p className="text-xs uppercase tracking-wider text-gray-500">{label}</p>
            <p className={`mt-1 text-2xl font-semibold ${tone}`}>{value ?? 0}</p>
        </div>
    );
}

/**
 * Inline correction (§7.2).
 *
 * It edits the RAW cell values — what the user typed — and the server
 * re-normalises and re-validates from those, exactly as it would have on
 * upload. Editing the normalised values instead would let a correction produce
 * a row the file itself could never have contained.
 */
function FixRowPanel({ batch, row, columns, onClose }) {
    const form = useForm(
        Object.fromEntries(columns.map((column) => [column.field, row.raw[column.field] ?? ''])),
    );

    const failed = new Set(row.errors.map((error) => error.field));

    const submit = () => {
        form.transform((data) => ({ values: data })).patch(
            route('rcsa.imports.rows.update', [batch.id, row.id]),
            { preserveScroll: true, onSuccess: onClose },
        );
    };

    return (
        <div className="fixed inset-0 z-40 flex justify-end" role="dialog" aria-modal="true">
            <div className="absolute inset-0 bg-black/30" onClick={onClose} aria-hidden="true" />

            <div className="relative z-10 flex h-full w-full max-w-xl flex-col bg-white shadow-xl">
                <div className="border-b border-gray-200 px-6 py-4">
                    <h2 className="text-lg font-semibold text-gray-800">Row {row.row_number}</h2>
                    <p className="text-sm text-gray-500">
                        Correct the cells and the row is checked again straight away.
                    </p>
                </div>

                <div className="flex-1 space-y-4 overflow-y-auto px-6 py-5">
                    {row.errors.length > 0 && (
                        <ul className="space-y-1 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                            {row.errors.map((error, index) => (
                                <li key={index}>{error.message}</li>
                            ))}
                        </ul>
                    )}

                    {columns.map((column) => (
                        <div key={column.field}>
                            <label className="mb-1 block text-sm font-medium text-gray-700">
                                {column.label}
                                {column.required && <span className="ml-0.5 text-red-500">*</span>}
                            </label>
                            <textarea
                                rows={column.field === 'potential_risk' ? 3 : 1}
                                className={`filter-input w-full ${failed.has(column.field) ? 'border-red-400' : ''}`}
                                value={form.data[column.field] ?? ''}
                                onChange={(e) => form.setData(column.field, e.target.value)}
                            />
                        </div>
                    ))}
                </div>

                <div className="flex items-center justify-end gap-2 border-t border-gray-200 px-6 py-4">
                    <button type="button" onClick={onClose} className="btn-secondary text-sm">
                        Cancel
                    </button>
                    <button type="button" onClick={submit} disabled={form.processing} className="btn-primary text-sm">
                        Re-check row
                    </button>
                </div>
            </div>
        </div>
    );
}
