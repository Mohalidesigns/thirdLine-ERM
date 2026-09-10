import { useMemo, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';

/**
 * The CBN Appendix II §1.4 register — the view the export is taken from.
 *
 * AC-13 requires the export to reconcile row for row to THIS screen, so the
 * download links carry the current filters verbatim rather than re-deriving
 * them. A button that exported "everything" from a filtered page would produce
 * a file nobody could tie back to what they were looking at.
 *
 * THE PROVENANCE PANEL IS ON THE SCREEN, NOT ONLY IN THE FILE. The preparer
 * checks the filters before downloading, which is the only point at which a
 * wrong view is cheap to notice.
 */
export default function CbnRegister({
    columns = [],
    rows = [],
    summary = {},
    provenance = {},
    filters = {},
    options = {},
    can = {},
}) {
    const [reviewerId, setReviewerId] = useState('');

    const groups = useMemo(() => {
        const seen = [];
        columns.forEach((column) => {
            if (!seen.includes(column.group)) seen.push(column.group);
        });
        return seen;
    }, [columns]);

    const [group, setGroup] = useState('Provider');
    const visible = columns.filter((column) => column.group === group || column.key === 'reference');

    const apply = (key, value) => {
        const next = { ...filters };
        if (value === '' || value === false) delete next[key];
        else next[key] = value;

        router.get(route('tprm.reports.cbn-register'), next, { preserveState: true, preserveScroll: true });
    };

    const exportUrl = (format) => {
        const params = new URLSearchParams({ ...filters, format });
        if (reviewerId) params.set('reviewer_id', reviewerId);
        return `${route('tprm.reports.cbn-register.export')}?${params.toString()}`;
    };

    return (
        <AppLayout title="ICT third-party register">
            <Head title="ICT third-party register" />

            <PageHeader
                title="ICT third-party and cloud service provider register"
                subtitle="CBN Risk-Based Cybersecurity Framework, Appendix II §1.4. One row per arrangement, because risk is assessed at the engagement and not at the company."
            />

            <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <Tile label="Arrangements in this view" value={summary.total ?? 0} hint="ICT, outsourcing and cloud" />
                <Tile
                    label="Critical tier"
                    value={summary.critical_tier ?? 0}
                    tone={summary.critical_tier ? 'warn' : null}
                    hint={`${summary.supports_critical_function ?? 0} support a critical function`}
                />
                <Tile
                    label="With undocumented connections"
                    value={summary.undocumented_connections ?? 0}
                    tone={summary.undocumented_connections ? 'critical' : null}
                    hint="approved, but missing endpoint, encryption, auth or firewall rule"
                />
                <Tile
                    label="Access expired, not revoked"
                    value={summary.access_grants_overdue ?? 0}
                    tone={summary.access_grants_overdue ? 'critical' : null}
                    hint="the credential should be assumed to still work"
                />
            </div>

            {/* ---------------------------------------------------- filters */}
            <div className="card mb-4 flex flex-wrap items-end gap-4 p-4">
                <label className="text-sm">
                    <span className="mb-1 block text-xs font-medium text-gray-600">Tier</span>
                    <select
                        className="filter-input"
                        value={filters.tier ?? ''}
                        onChange={(event) => apply('tier', event.target.value)}
                    >
                        <option value="">All tiers</option>
                        <option value="critical">Critical</option>
                        <option value="high">High</option>
                        <option value="moderate">Moderate</option>
                        <option value="low">Low</option>
                    </select>
                </label>

                <label className="text-sm">
                    <span className="mb-1 block text-xs font-medium text-gray-600">Business unit</span>
                    <select
                        className="filter-input"
                        value={filters.business_unit ?? ''}
                        onChange={(event) => apply('business_unit', event.target.value)}
                    >
                        <option value="">All business units</option>
                        {(options.business_units ?? []).map((unit) => (
                            <option key={unit.value} value={unit.value}>{unit.label}</option>
                        ))}
                    </select>
                </label>

                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={Boolean(filters.critical_only)}
                        onChange={(event) => apply('critical_only', event.target.checked ? 1 : '')}
                    />
                    Critical functions only
                </label>

                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={Boolean(filters.include_inactive)}
                        onChange={(event) => apply('include_inactive', event.target.checked ? 1 : '')}
                    />
                    Include terminated and archived
                </label>

                <div className="ml-auto flex items-end gap-2">
                    {can.export && (
                        <label className="text-sm">
                            <span className="mb-1 block text-xs font-medium text-gray-600">Reviewed by</span>
                            <select
                                className="filter-input"
                                value={reviewerId}
                                onChange={(event) => setReviewerId(event.target.value)}
                            >
                                <option value="">Not reviewed</option>
                                {(options.reviewers ?? []).map((reviewer) => (
                                    <option key={reviewer.value} value={reviewer.value}>{reviewer.label}</option>
                                ))}
                            </select>
                        </label>
                    )}

                    {can.export && (
                        <div className="flex gap-2">
                            <a className="btn-secondary" href={exportUrl('xlsx')}>XLSX</a>
                            <a className="btn-secondary" href={exportUrl('csv')}>CSV</a>
                            <a className="btn-primary" href={exportUrl('pdf')}>Signed PDF</a>
                        </div>
                    )}
                </div>
            </div>

            {/* ------------------------------------------------- provenance */}
            <div className="card mb-4 p-4 text-xs text-gray-600">
                <p className="mb-2 font-medium text-gray-700">
                    This is the view an export will reproduce, row for row.
                </p>
                <dl className="grid grid-cols-2 gap-x-6 gap-y-1 md:grid-cols-3">
                    <Provenance label="Position as at" value={provenance.as_at_label} />
                    <Provenance label="Prepared by" value={provenance.prepared_by} />
                    <Provenance label="Reviewed by" value={provenance.reviewed_by ?? 'Not reviewed'} />
                    {Object.entries(provenance.filters ?? {}).map(([label, value]) => (
                        <Provenance key={label} label={label} value={value} />
                    ))}
                    {Object.entries(provenance.versions ?? {}).map(([label, value]) => (
                        <Provenance key={label} label={label} value={value} />
                    ))}
                </dl>
            </div>

            {/* ------------------------------------------------------ table */}
            <div className="mb-3 flex flex-wrap gap-2">
                {groups.map((name) => (
                    <button
                        key={name}
                        type="button"
                        onClick={() => setGroup(name)}
                        className={`rounded-full px-3 py-1 text-xs font-medium ${
                            group === name ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-600'
                        }`}
                    >
                        {name}
                    </button>
                ))}
            </div>

            <div className="card overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200 text-sm">
                    <caption className="px-4 py-2 text-left text-xs text-gray-500">
                        Thirty columns do not fit on one screen and would not be readable if they did. The groups above
                        switch which set is shown; every group holds the same {rows.length} rows in the same order, and
                        so does every export.
                    </caption>
                    <thead className="bg-gray-50">
                        <tr>
                            {visible.map((column) => (
                                <th key={column.key} scope="col" className="whitespace-nowrap px-4 py-2 text-left font-medium text-gray-600">
                                    {column.label}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {rows.map((row) => (
                            <tr key={row.uuid}>
                                {visible.map((column) => (
                                    <td key={column.key} className="whitespace-nowrap px-4 py-2 align-top">
                                        {column.key === 'reference' ? (
                                            <a className="font-medium text-indigo-600" href={row.url}>{row.reference}</a>
                                        ) : (
                                            <Cell column={column.key} value={row[column.key]} />
                                        )}
                                    </td>
                                ))}
                            </tr>
                        ))}
                        {rows.length === 0 && (
                            <tr>
                                <td colSpan={visible.length} className="px-4 py-10 text-center text-sm text-gray-500">
                                    No ICT arrangements match this view. That is a statement about the filters above,
                                    not about the estate.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}

function Cell({ column, value }) {
    if (value === null || value === undefined || value === '') {
        return <span className="text-gray-400">—</span>;
    }

    const tone = {
        Undocumented: 'bg-red-50 text-red-700',
        Expired: 'bg-red-50 text-red-700',
        'None held': 'bg-amber-50 text-amber-700',
        Critical: 'bg-red-50 text-red-700',
        High: 'bg-amber-50 text-amber-700',
        'Fully documented': 'bg-emerald-50 text-emerald-700',
        Current: 'bg-emerald-50 text-emerald-700',
    }[value];

    if (tone) {
        return <span className={`rounded px-2 py-0.5 text-xs font-medium ${tone}`}>{value}</span>;
    }

    if (column === 'connection_documentation' && String(value).startsWith('Partial')) {
        return <span className="rounded bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">{value}</span>;
    }

    return <span>{value}</span>;
}

function Provenance({ label, value }) {
    return (
        <div className="flex gap-2">
            <dt className="text-gray-500">{label}:</dt>
            <dd className="font-medium text-gray-700">{value ?? '—'}</dd>
        </div>
    );
}

function Tile({ label, value, hint, tone }) {
    const toneClass = tone === 'critical' ? 'text-red-600' : tone === 'warn' ? 'text-amber-600' : 'text-gray-900';

    return (
        <div className="card p-4">
            <div className="text-xs font-medium uppercase tracking-wide text-gray-500">{label}</div>
            <div className={`mt-1 text-2xl font-bold ${toneClass}`}>{value}</div>
            {hint && <div className="mt-1 text-xs text-gray-500">{hint}</div>}
        </div>
    );
}
