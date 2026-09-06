import { Fragment, useMemo, useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import FilterBar from '@thirdline/ui/Components/FilterBar';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import Pagination from '@thirdline/ui/Components/Pagination';
import StatusBadge from '@thirdline/ui/Components/StatusBadge';
import AddRiskPanel from './AddRiskPanel';

/**
 * The RCSA Universe (plan §6) — the governed inventory a cycle is populated
 * from.
 *
 * The header, filter card and table follow the Audit Universe page in the
 * internal audit product, which is what §6.1 asks for: same three actions in
 * the same order, same filter card, same table shell.
 *
 * THE TABLE IS WRITTEN OUT RATHER THAN BUILT FROM THE SHARED DataTable, and
 * that is what the pattern source does too — its Audit Universe writes
 * `<table className="data-table">` directly. Two things here need markup
 * DataTable cannot express: a selection column driving the bulk-edit bar, and
 * an expanding detail row. It is the same shell either way: the `data-table`,
 * `card` and `filter-*` classes are the shared styling, and PageHeader,
 * FilterBar, Pagination and StatusBadge are the shared components.
 */

const CATEGORY_TONE = 'bg-gray-100 text-gray-700';

export default function Index({ risks, filters = {}, options = {}, can = {} }) {
    const { flash } = usePage().props;

    const [panelOpen, setPanelOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const [selected, setSelected] = useState([]);
    const [expanded, setExpanded] = useState(null);
    const [bulk, setBulk] = useState({ owner_id: '', risk_category: '', status: '' });
    const [duplicating, setDuplicating] = useState(null);
    const [duplicateUnit, setDuplicateUnit] = useState('');
    const [uploadOpen, setUploadOpen] = useState(false);

    const rows = risks?.data ?? [];

    const allSelected = rows.length > 0 && selected.length === rows.length;

    const toggleAll = () => setSelected(allSelected ? [] : rows.map((r) => r.id));

    const toggle = (id) =>
        setSelected((current) =>
            current.includes(id) ? current.filter((i) => i !== id) : [...current, id],
        );

    const filterConfig = useMemo(
        () => [
            { type: 'search' },
            {
                type: 'select',
                name: 'business_unit',
                label: 'Business Unit',
                placeholder: 'All Units',
                options: (options.businessUnits ?? []).map((u) => ({ value: u.id, label: u.name })),
            },
            {
                type: 'select',
                name: 'category',
                label: 'Risk Category',
                placeholder: 'All Categories',
                options: (options.categories ?? []).map((c) => ({ value: c, label: c })),
            },
            {
                type: 'select',
                name: 'status',
                label: 'Status',
                placeholder: 'All Statuses',
                options: (options.statuses ?? []).map((s) => ({
                    value: s,
                    label: s.charAt(0).toUpperCase() + s.slice(1),
                })),
            },
        ],
        [options],
    );

    /* ------------------------------------------------------------------ */
    /*  Row and bulk actions                                               */
    /* ------------------------------------------------------------------ */

    const post = (routeName, data, confirmText) => {
        if (confirmText && !window.confirm(confirmText)) return;

        router.post(route(routeName), data, {
            preserveScroll: true,
            onSuccess: () => setSelected([]),
        });
    };

    const publish = (ids) =>
        post(
            'rcsa.universe.publish',
            { ids },
            ids.length === 1
                ? 'Publish this risk? Published rows are pulled into every future assessment.'
                : `Publish ${ids.length} risks? Published rows are pulled into every future assessment.`,
        );

    const retire = (ids) =>
        post(
            'rcsa.universe.retire',
            { ids },
            'Retire? The rows stay in past assessments but are not carried into new ones.',
        );

    const destroy = (risk) => {
        if (!window.confirm(`Delete ${risk.risk_no}? This cannot be undone.`)) return;

        router.delete(route('rcsa.universe.destroy', risk.id), { preserveScroll: true });
    };

    // Duplicating asks for a destination, and it asks in the page rather than
    // through window.prompt: a prompt cannot show the unit NAMES (it would be
    // asking someone to type a database id), cannot be cancelled cleanly, and
    // throws outright in embedded browsers.
    const confirmDuplicate = () => {
        if (!duplicating || !duplicateUnit) return;

        router.post(
            route('rcsa.universe.duplicate', duplicating.id),
            { business_unit_id: duplicateUnit },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setDuplicating(null);
                    setDuplicateUnit('');
                },
            },
        );
    };

    const applyBulk = () => {
        const payload = { ids: selected };
        Object.entries(bulk).forEach(([key, value]) => {
            if (value !== '') payload[key] = value;
        });

        if (Object.keys(payload).length === 1) {
            window.alert('Choose an owner, a category or a status to apply.');

            return;
        }

        router.post(route('rcsa.universe.bulk-update'), payload, {
            preserveScroll: true,
            onSuccess: () => {
                setSelected([]);
                setBulk({ owner_id: '', risk_category: '', status: '' });
            },
        });
    };

    return (
        <AppLayout
            header={
                <PageHeader
                    title="RCSA Universe"
                    subtitle="Manage processes, risks and controls"
                    actions={
                        <div className="flex items-center gap-2">
                            {/*
                              * A plain <a>, not an Inertia <Link>: the response
                              * is a streamed .xlsx, and Inertia would try to
                              * parse it as a page payload and show nothing.
                              */}
                            <a
                                href={route('rcsa.imports.template')}
                                className="btn-secondary inline-flex items-center gap-2 text-sm"
                                title="An Excel template listing your business units, processes and users"
                            >
                                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                                </svg>
                                Download Template
                            </a>

                            {can.import && (
                                <button
                                    type="button"
                                    onClick={() => setUploadOpen(!uploadOpen)}
                                    className={`inline-flex items-center gap-2 text-sm ${uploadOpen ? 'btn-primary' : 'btn-secondary'}`}
                                    title="Upload a filled template"
                                >
                                    <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                                    </svg>
                                    Bulk Upload
                                </button>
                            )}

                            {can.create && (
                                <button
                                    type="button"
                                    onClick={() => {
                                        setEditing(null);
                                        setPanelOpen(true);
                                    }}
                                    className="btn-primary inline-flex items-center gap-2"
                                >
                                    <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                    </svg>
                                    Add Risk
                                </button>
                            )}
                        </div>
                    }
                />
            }
        >
            <Head title="RCSA Universe" />

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

            {uploadOpen && <UploadCard onClose={() => setUploadOpen(false)} />}

            <FilterBar
                filters={filterConfig}
                currentFilters={filters}
                route={route('rcsa.universe.index')}
                searchPlaceholder="Search risks…"
            />

            {selected.length > 0 && (
                <div className="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-blue-200 bg-blue-50 p-4">
                    <div className="text-sm font-medium text-blue-900">
                        {selected.length} selected
                    </div>

                    <div className="filter-group min-w-[160px]">
                        <label className="filter-label">Owner</label>
                        <select
                            className="filter-select"
                            value={bulk.owner_id}
                            onChange={(e) => setBulk({ ...bulk, owner_id: e.target.value })}
                        >
                            <option value="">Leave unchanged</option>
                            {(options.owners ?? []).map((u) => (
                                <option key={u.id} value={u.id}>
                                    {u.name}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="filter-group min-w-[160px]">
                        <label className="filter-label">Category</label>
                        <select
                            className="filter-select"
                            value={bulk.risk_category}
                            onChange={(e) => setBulk({ ...bulk, risk_category: e.target.value })}
                        >
                            <option value="">Leave unchanged</option>
                            {(options.categories ?? []).map((c) => (
                                <option key={c} value={c}>
                                    {c}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="flex items-center gap-2">
                        <button type="button" onClick={applyBulk} className="btn-primary text-sm">
                            Apply
                        </button>
                        {can.publish && (
                            <>
                                <button
                                    type="button"
                                    onClick={() => publish(selected)}
                                    className="btn-secondary text-sm"
                                >
                                    Publish
                                </button>
                                <button
                                    type="button"
                                    onClick={() => retire(selected)}
                                    className="btn-secondary text-sm"
                                >
                                    Retire
                                </button>
                            </>
                        )}
                        <button
                            type="button"
                            onClick={() => setSelected([])}
                            className="text-sm text-gray-500 hover:underline"
                        >
                            Clear
                        </button>
                    </div>
                </div>
            )}

            {duplicating && (
                <div className="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-gray-200 bg-white p-4">
                    <div className="text-sm text-gray-700">
                        Copy <span className="font-mono text-xs">{duplicating.risk_no}</span> into
                    </div>

                    <div className="filter-group min-w-[200px]">
                        <label className="filter-label">Business Unit</label>
                        <select
                            autoFocus
                            className="filter-select"
                            value={duplicateUnit}
                            onChange={(e) => setDuplicateUnit(e.target.value)}
                        >
                            <option value="">Choose a business unit…</option>
                            {(options.businessUnits ?? []).map((u) => (
                                <option key={u.id} value={u.id}>
                                    {u.code ? `${u.code} — ${u.name}` : u.name}
                                </option>
                            ))}
                        </select>
                    </div>

                    <button
                        type="button"
                        onClick={confirmDuplicate}
                        disabled={!duplicateUnit}
                        className="btn-primary text-sm disabled:opacity-50"
                    >
                        Copy
                    </button>
                    <button
                        type="button"
                        onClick={() => setDuplicating(null)}
                        className="text-sm text-gray-500 hover:underline"
                    >
                        Cancel
                    </button>

                    <p className="w-full text-xs text-gray-500">
                        The copy arrives as a draft with a new risk number, and carries the controls across.
                    </p>
                </div>
            )}

            <div className="card">
                <div className="overflow-x-auto">
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th className="w-10">
                                    <input
                                        type="checkbox"
                                        checked={allSelected}
                                        onChange={toggleAll}
                                        aria-label="Select all risks on this page"
                                        className="rounded border-gray-300"
                                    />
                                </th>
                                <th className="w-8" />
                                <th>Risk No.</th>
                                <th>Business Unit</th>
                                <th>Process</th>
                                <th>Potential Risk</th>
                                <th>Category</th>
                                <th>Controls</th>
                                <th>Status</th>
                                <th>Last updated</th>
                                <th className="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 && (
                                <tr>
                                    <td colSpan={11} className="py-12 text-center text-gray-400">
                                        <p className="text-sm font-medium">No risks in the universe yet</p>
                                        <p className="mt-1 text-xs">
                                            Add them one at a time, or upload the template once bulk upload ships.
                                        </p>
                                    </td>
                                </tr>
                            )}

                            {rows.map((risk) => (
                                <Fragment key={risk.id}>
                                    <tr>
                                        <td>
                                            <input
                                                type="checkbox"
                                                checked={selected.includes(risk.id)}
                                                onChange={() => toggle(risk.id)}
                                                aria-label={`Select ${risk.risk_no}`}
                                                className="rounded border-gray-300"
                                            />
                                        </td>
                                        <td>
                                            <button
                                                type="button"
                                                onClick={() => setExpanded(expanded === risk.id ? null : risk.id)}
                                                className="text-gray-400 hover:text-gray-600"
                                                aria-expanded={expanded === risk.id}
                                                aria-label={`Show details for ${risk.risk_no}`}
                                            >
                                                <svg
                                                    className={`h-4 w-4 transition-transform ${expanded === risk.id ? 'rotate-90' : ''}`}
                                                    fill="none"
                                                    viewBox="0 0 24 24"
                                                    strokeWidth={2}
                                                    stroke="currentColor"
                                                >
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                                                </svg>
                                            </button>
                                        </td>
                                        <td>
                                            <span className="whitespace-nowrap font-mono text-xs text-gray-500">
                                                {risk.risk_no}
                                            </span>
                                        </td>
                                        <td className="text-sm text-gray-600">{risk.business_unit ?? '—'}</td>
                                        <td className="text-sm text-gray-600">
                                            {risk.process ?? '—'}
                                            {risk.sub_process && (
                                                <span className="block text-xs text-gray-400">{risk.sub_process}</span>
                                            )}
                                        </td>
                                        <td>
                                            <p className="max-w-[320px] truncate text-sm text-gray-700">
                                                {risk.potential_risk}
                                            </p>
                                        </td>
                                        <td>
                                            {risk.risk_category ? (
                                                <span className={`badge ${CATEGORY_TONE}`}>{risk.risk_category}</span>
                                            ) : (
                                                <span className="text-xs text-gray-400">—</span>
                                            )}
                                        </td>
                                        <td className="text-sm text-gray-600">
                                            {risk.controls_count > 0 ? (
                                                risk.controls_count
                                            ) : (
                                                <span className="text-xs text-amber-600">None</span>
                                            )}
                                        </td>
                                        <td>
                                            <StatusBadge status={risk.status} />
                                        </td>
                                        <td className="text-sm text-gray-500">{risk.updated_at ?? '—'}</td>
                                        <td className="text-right">
                                            <div className="flex items-center justify-end gap-2">
                                                {can.create && (
                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            setEditing(risk);
                                                            setPanelOpen(true);
                                                        }}
                                                        className="text-xs text-gray-500 hover:text-[var(--color-primary)]"
                                                    >
                                                        Edit
                                                    </button>
                                                )}
                                                {can.create && (
                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            setDuplicating(risk);
                                                            setDuplicateUnit('');
                                                        }}
                                                        className="text-xs text-gray-500 hover:text-[var(--color-primary)]"
                                                    >
                                                        Duplicate
                                                    </button>
                                                )}
                                                {can.publish && risk.status !== 'published' && (
                                                    <button
                                                        type="button"
                                                        onClick={() => publish([risk.id])}
                                                        className="text-xs text-green-700 hover:underline"
                                                    >
                                                        Publish
                                                    </button>
                                                )}
                                                {can.publish && risk.status === 'published' && (
                                                    <button
                                                        type="button"
                                                        onClick={() => retire([risk.id])}
                                                        className="text-xs text-amber-700 hover:underline"
                                                    >
                                                        Retire
                                                    </button>
                                                )}
                                                {can.delete && risk.status !== 'published' && (
                                                    <button
                                                        type="button"
                                                        onClick={() => destroy(risk)}
                                                        className="text-xs text-red-600 hover:underline"
                                                    >
                                                        Delete
                                                    </button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>

                                    {expanded === risk.id && (
                                        <tr className="bg-gray-50/70">
                                            <td colSpan={11} className="px-6 py-4">
                                                <div className="grid gap-6 md:grid-cols-2">
                                                    <div>
                                                        <h4 className="text-xs font-semibold uppercase tracking-wider text-gray-500">
                                                            Risk driver
                                                        </h4>
                                                        <p className="mt-1 text-sm text-gray-700">
                                                            {risk.risk_driver || 'Not recorded'}
                                                        </p>

                                                        {risk.secondary_categories?.length > 0 && (
                                                            <>
                                                                <h4 className="mt-4 text-xs font-semibold uppercase tracking-wider text-gray-500">
                                                                    Other categories
                                                                </h4>
                                                                <div className="mt-1 flex flex-wrap gap-1">
                                                                    {risk.secondary_categories.map((c) => (
                                                                        <span key={c} className="badge bg-gray-100 text-gray-700">
                                                                            {c}
                                                                        </span>
                                                                    ))}
                                                                </div>
                                                            </>
                                                        )}

                                                        <h4 className="mt-4 text-xs font-semibold uppercase tracking-wider text-gray-500">
                                                            Owner
                                                        </h4>
                                                        <p className="mt-1 text-sm text-gray-700">
                                                            {risk.owner || 'Unassigned'}
                                                        </p>
                                                    </div>

                                                    <div>
                                                        <h4 className="text-xs font-semibold uppercase tracking-wider text-gray-500">
                                                            Existing controls
                                                        </h4>
                                                        {risk.controls?.length > 0 ? (
                                                            <ul className="mt-1 space-y-2">
                                                                {risk.controls.map((control) => (
                                                                    <li key={control.id} className="text-sm text-gray-700">
                                                                        {control.is_key && (
                                                                            <span className="mr-1 badge bg-blue-100 text-blue-700">
                                                                                Key
                                                                            </span>
                                                                        )}
                                                                        {control.description}
                                                                        {control.control_type && (
                                                                            <span className="ml-1 text-xs text-gray-400">
                                                                                ({control.control_type})
                                                                            </span>
                                                                        )}
                                                                    </li>
                                                                ))}
                                                            </ul>
                                                        ) : (
                                                            <p className="mt-1 text-sm text-amber-700">
                                                                No controls recorded. The assessment will have nothing to
                                                                rate for this risk.
                                                            </p>
                                                        )}
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    )}
                                </Fragment>
                            ))}
                        </tbody>
                    </table>
                </div>

                {risks?.links?.length > 3 && (
                    <div className="border-t border-gray-100 px-4 py-3">
                        <Pagination links={risks.links} />
                    </div>
                )}
            </div>

            <AddRiskPanel
                open={panelOpen}
                onClose={() => {
                    setPanelOpen(false);
                    setEditing(null);
                }}
                options={options}
                editing={editing}
            />
        </AppLayout>
    );
}

/**
 * Upload a filled template.
 *
 * The copy is doing real work here: the single most common reason a bank will
 * not touch a bulk upload is not knowing what it is about to do to their data,
 * so the card says plainly that nothing is written until they have seen the
 * preview. That is also true — see RcsaImportProcessor.
 */
function UploadCard({ onClose }) {
    const form = useForm({ file: null });
    const [dragOver, setDragOver] = useState(false);

    const submit = (e) => {
        e.preventDefault();

        if (!form.data.file) return;

        form.post(route('rcsa.imports.store'), { forceFormData: true });
    };

    return (
        <div className="mb-4 rounded-lg border border-gray-200 bg-white p-4">
            <div className="mb-3 flex items-start justify-between">
                <div>
                    <h3 className="text-sm font-semibold text-gray-800">Bulk upload</h3>
                    <p className="text-sm text-gray-500">
                        Nothing is added to the universe when you upload. The file is checked first and you are shown
                        exactly which rows are ready, which are duplicates and which have errors.
                    </p>
                </div>
                <button onClick={onClose} className="text-gray-400 hover:text-gray-600" aria-label="Close">
                    <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <form onSubmit={submit}>
                <label
                    onDragOver={(e) => {
                        e.preventDefault();
                        setDragOver(true);
                    }}
                    onDragLeave={() => setDragOver(false)}
                    onDrop={(e) => {
                        e.preventDefault();
                        setDragOver(false);
                        const file = e.dataTransfer.files[0];
                        if (file) form.setData('file', file);
                    }}
                    className={`block cursor-pointer rounded-xl border-2 border-dashed p-8 text-center transition-colors ${
                        dragOver
                            ? 'border-[var(--color-primary)] bg-blue-50'
                            : form.data.file
                              ? 'border-green-300 bg-green-50'
                              : 'border-gray-300 bg-gray-50 hover:border-gray-400'
                    }`}
                >
                    <input
                        type="file"
                        accept=".xlsx,.xls,.csv"
                        className="sr-only"
                        onChange={(e) => form.setData('file', e.target.files[0] ?? null)}
                    />
                    {form.data.file ? (
                        <p className="text-sm font-medium text-gray-800">{form.data.file.name}</p>
                    ) : (
                        <>
                            <p className="text-sm text-gray-600">Choose a file or drag it here</p>
                            <p className="mt-1 text-xs text-gray-500">Excel or CSV, up to 10 MB</p>
                        </>
                    )}
                </label>

                {form.errors.file && <p className="mt-2 text-sm text-red-600">{form.errors.file}</p>}

                <div className="mt-3 flex items-center justify-end gap-2">
                    <button type="button" onClick={onClose} className="btn-secondary text-sm">
                        Cancel
                    </button>
                    <button
                        type="submit"
                        disabled={!form.data.file || form.processing}
                        className="btn-primary text-sm disabled:opacity-50"
                    >
                        {form.processing ? 'Uploading…' : 'Upload and check'}
                    </button>
                </div>
            </form>
        </div>
    );
}
