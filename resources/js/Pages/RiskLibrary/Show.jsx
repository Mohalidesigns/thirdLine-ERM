import { Head, Link, router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import EmptyState from '@thirdline/ui/Components/EmptyState';
import InputError from '@thirdline/ui/Components/InputError';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import RatingBadge from '@thirdline/ui/Components/RatingBadge';
import StatusBadge from '@thirdline/ui/Components/StatusBadge';
import tryRoute from '@thirdline/ui/lib/tryRoute';
import BulkApplySlideOver from '@/Components/RiskLibrary/BulkApplySlideOver';

const formatDate = (value) =>
    value
        ? new Date(value).toLocaleDateString('en-GB', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          })
        : '—';

const formatDateTime = (value) =>
    value
        ? new Date(value).toLocaleString('en-GB', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          })
        : '—';

const humaniseAction = (value) =>
    (value ?? '')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (c) => c.toUpperCase());

const normaliseList = (list, key) =>
    (Array.isArray(list) ? list : []).map((row) =>
        typeof row === 'string' ? row : row?.[key] ?? '',
    );

/**
 * Pure client-side field diff between two immutable version snapshots.
 */
function buildVersionDiff(fromSnap, toSnap) {
    if (!fromSnap || !toSnap) return [];

    const fields = [
        { key: 'title', label: 'Title', before: fromSnap.title ?? '', after: toSnap.title ?? '' },
        {
            key: 'description',
            label: 'Description',
            before: fromSnap.description ?? '',
            after: toSnap.description ?? '',
        },
        {
            key: 'category_name',
            label: 'Category',
            before: fromSnap.category_name ?? '—',
            after: toSnap.category_name ?? '—',
        },
        {
            key: 'risk_source',
            label: 'Risk Source',
            before: fromSnap.risk_source ? humaniseAction(fromSnap.risk_source) : '—',
            after: toSnap.risk_source ? humaniseAction(toSnap.risk_source) : '—',
        },
        {
            key: 'basel_l1_label',
            label: 'Basel L1 Event Type',
            before: fromSnap.basel_l1_label ?? '—',
            after: toSnap.basel_l1_label ?? '—',
        },
        {
            key: 'basel_l2_label',
            label: 'Basel L2 Event Type',
            before: fromSnap.basel_l2_label ?? '—',
            after: toSnap.basel_l2_label ?? '—',
        },
        {
            key: 'typical_causes',
            label: 'Typical Causes',
            isList: true,
            before: normaliseList(fromSnap.typical_causes, 'cause'),
            after: normaliseList(toSnap.typical_causes, 'cause'),
        },
        {
            key: 'typical_consequences',
            label: 'Typical Consequences',
            isList: true,
            before: normaliseList(fromSnap.typical_consequences, 'consequence'),
            after: normaliseList(toSnap.typical_consequences, 'consequence'),
        },
        {
            key: 'suggested_controls',
            label: 'Suggested Controls',
            isList: true,
            before: normaliseList(fromSnap.suggested_controls, 'title'),
            after: normaliseList(toSnap.suggested_controls, 'title'),
        },
        {
            key: 'suggested_kris',
            label: 'Suggested KRIs',
            isList: true,
            before: normaliseList(fromSnap.suggested_kris, 'name'),
            after: normaliseList(toSnap.suggested_kris, 'name'),
        },
    ];

    return fields.map((f) => ({
        ...f,
        changed: JSON.stringify(f.before) !== JSON.stringify(f.after),
    }));
}

function DiffValue({ value, isList = false }) {
    if (isList) {
        if (!Array.isArray(value) || value.length === 0) {
            return <p className="text-xs text-gray-400 italic">None listed</p>;
        }
        return (
            <ul className="list-disc pl-4 space-y-1 text-xs text-gray-800">
                {value.map((entry, idx) => (
                    <li key={idx}>{entry}</li>
                ))}
            </ul>
        );
    }

    return <p className="text-xs text-gray-800 whitespace-pre-line">{value || '—'}</p>;
}

/**
 * Master Risk Library Detail, Interactive Version Diff & Linked Instances (ADR 0028 Phase 1).
 *
 * No Delete button exists anywhere on this screen (ADR 0028 Decision 6: Retire/Reactivate only).
 */
export default function Show({
    item,
    versions = [],
    instances = { total: 0, outdated_count: 0, rows: [], can_view_rows: false, hidden_count: 0, truncated: false },
    auditTrail = [],
    can = {},
}) {
    const isActive = item.status === 'active';
    const [tab, setTab] = useState('definition');
    const [retireOpen, setRetireOpen] = useState(false);
    const [reactivating, setReactivating] = useState(false);
    const [bulkApplyOpen, setBulkApplyOpen] = useState(false);
    const [showUnchanged, setShowUnchanged] = useState(false);

    // Default diff selection: v{current - 1} → v{current}
    const sortedVersions = useMemo(
        () => [...versions].sort((a, b) => b.version_number - a.version_number),
        [versions],
    );
    const defaultTo = sortedVersions[0]?.version_number ?? item.current_version;
    const defaultFrom = sortedVersions[1]?.version_number ?? defaultTo;

    const [fromVersion, setFromVersion] = useState(defaultFrom);
    const [toVersion, setToVersion] = useState(defaultTo);

    const retireForm = useForm({ retirement_reason: '' });

    const submitRetire = (e) => {
        e.preventDefault();
        retireForm.post(route('risk.library.retire', item.id), {
            preserveScroll: true,
            onSuccess: () => {
                retireForm.reset();
                setRetireOpen(false);
            },
        });
    };

    const handleReactivate = () => {
        if (
            !window.confirm(
                `Reactivate ${item.code}? It will reappear in Add-from-Library pickers.`,
            )
        ) {
            return;
        }
        setReactivating(true);
        router.post(
            route('risk.library.reactivate', item.id),
            {},
            {
                preserveScroll: true,
                onFinish: () => setReactivating(false),
            },
        );
    };

    const fromSnap = useMemo(
        () => sortedVersions.find((v) => v.version_number === Number(fromVersion)) ?? null,
        [sortedVersions, fromVersion],
    );
    const toSnap = useMemo(
        () => sortedVersions.find((v) => v.version_number === Number(toVersion)) ?? null,
        [sortedVersions, toVersion],
    );

    const diffFields = useMemo(() => buildVersionDiff(fromSnap, toSnap), [fromSnap, toSnap]);
    const changedFields = useMemo(() => diffFields.filter((f) => f.changed), [diffFields]);
    const unchangedFields = useMemo(() => diffFields.filter((f) => !f.changed), [diffFields]);

    const tabs = [
        { key: 'definition', label: 'Definition' },
        { key: 'versions', label: `Version History (${versions.length})` },
        { key: 'instances', label: `Linked Instances (${instances.total ?? 0})` },
        { key: 'audit', label: 'Audit Trail' },
    ];

    const handleTabKeyDown = (e, index) => {
        if (e.key === 'ArrowRight') {
            e.preventDefault();
            setTab(tabs[(index + 1) % tabs.length].key);
        } else if (e.key === 'ArrowLeft') {
            e.preventDefault();
            setTab(tabs[(index - 1 + tabs.length) % tabs.length].key);
        }
    };

    const exportItemPdfUrl = tryRoute('risk.library.export-item', item.id);
    const editUrl = tryRoute('risk.library.edit', item.id);

    return (
        <AuthenticatedLayout title={item.code}>
            <Head title={`${item.code} — ${item.title} | Risk Library`} />

            <PageHeader
                title={`${item.code} — ${item.title}`}
                subtitle={`Version v${item.current_version} · ${item.category?.name ?? 'Uncategorised'}`}
                breadcrumbs={[
                    { label: 'Risk Library', href: route('risk.library.index') },
                    { label: item.code },
                ]}
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        {isActive && can.bulkApply && (
                            <button
                                type="button"
                                onClick={() => setBulkApplyOpen(true)}
                                className="btn-secondary text-sm inline-flex items-center gap-1.5"
                            >
                                <span className="material-symbols-outlined text-lg">library_add</span>
                                Apply to Entities
                            </button>
                        )}
                        {isActive && can.update && editUrl && (
                            <Link
                                href={editUrl}
                                className="btn-primary text-sm inline-flex items-center gap-1.5"
                            >
                                <span className="material-symbols-outlined text-lg">edit</span>
                                Edit (Creates v{item.current_version + 1})
                            </Link>
                        )}
                        {isActive && can.retire && (
                            <button
                                type="button"
                                onClick={() => setRetireOpen((prev) => !prev)}
                                className="btn-secondary text-sm inline-flex items-center gap-1.5 text-red-700 border-red-200 hover:bg-red-50"
                            >
                                <span className="material-symbols-outlined text-lg">archive</span>
                                Retire
                            </button>
                        )}
                        {!isActive && can.retire && (
                            <button
                                type="button"
                                disabled={reactivating}
                                onClick={handleReactivate}
                                className="btn-secondary text-sm inline-flex items-center gap-1.5 disabled:opacity-50"
                            >
                                <span className="material-symbols-outlined text-lg">unarchive</span>
                                {reactivating ? 'Reactivating…' : 'Reactivate'}
                            </button>
                        )}
                        {exportItemPdfUrl && (
                            <a
                                href={exportItemPdfUrl}
                                className="btn-secondary text-sm inline-flex items-center gap-1.5"
                            >
                                <span className="material-symbols-outlined text-lg">picture_as_pdf</span>
                                Export Version History PDF
                            </a>
                        )}
                    </div>
                }
            />

            {/* Header Metadata Badges */}
            <div className="flex flex-wrap items-center gap-2 mb-5">
                <StatusBadge status={item.status} />
                <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-mono font-semibold bg-gray-100 text-gray-800 border border-gray-200">
                    v{item.current_version}
                </span>
                {item.category && (
                    <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-blue-50 text-[#1A365D] border border-blue-200">
                        {item.category.name}
                        {item.category.cbn_mapping_code ? ` (${item.category.cbn_mapping_code})` : ''}
                    </span>
                )}
                {item.basel_l1 && (
                    <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-indigo-50 text-indigo-800 border border-indigo-200">
                        {item.basel_l1.label}
                        {item.basel_l2 ? ` — ${item.basel_l2.label}` : ''}
                    </span>
                )}
            </div>

            {/* Retired Callout */}
            {!isActive && (
                <div
                    role="status"
                    className="mb-6 rounded-xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900 flex items-start gap-3"
                >
                    <span className="material-symbols-outlined text-xl shrink-0 mt-0.5">inventory_2</span>
                    <div>
                        <p className="font-semibold">
                            Retired on {formatDate(item.retired_at)}
                            {item.retired_by_name ? ` by ${item.retired_by_name}` : ''}.
                        </p>
                        {item.retirement_reason && (
                            <p className="text-xs mt-1">
                                <strong>Reason:</strong> {item.retirement_reason}
                            </p>
                        )}
                        <p className="text-xs mt-1 text-amber-800">
                            Existing linked risk instances remain active and assessable; this item is
                            hidden from new pickers.
                        </p>
                    </div>
                </div>
            )}

            {/* Inline Retire Confirmation Form */}
            {retireOpen && isActive && can.retire && (
                <form
                    onSubmit={submitRetire}
                    className="mb-6 rounded-xl border border-red-200 bg-red-50/50 p-5 space-y-3"
                >
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <h2 className="text-sm font-semibold text-red-900">
                                Retire {item.code} from the Master Risk Library
                            </h2>
                            <p className="text-xs text-red-800 mt-1">
                                This item has <strong>{instances.total ?? 0}</strong> linked{' '}
                                {(instances.total ?? 0) === 1 ? 'instance' : 'instances'}. They will stay
                                active and display a &ldquo;Retired in Library&rdquo; badge.
                            </p>
                        </div>
                        <button
                            type="button"
                            onClick={() => setRetireOpen(false)}
                            aria-label="Close retire panel"
                            className="text-red-700 hover:text-red-900"
                        >
                            <span className="material-symbols-outlined text-lg">close</span>
                        </button>
                    </div>

                    <div>
                        <label
                            htmlFor="retirement-reason"
                            className="block text-xs font-semibold text-red-900 mb-1"
                        >
                            Retirement Reason <span className="text-red-600">*</span>
                        </label>
                        <textarea
                            id="retirement-reason"
                            rows={2}
                            required
                            minLength={10}
                            maxLength={500}
                            value={retireForm.data.retirement_reason}
                            onChange={(e) => retireForm.setData('retirement_reason', e.target.value)}
                            placeholder="Explain why this definition is being retired (minimum 10 characters)…"
                            className="w-full px-3 py-2 border border-red-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-red-300 focus:border-red-500"
                        />
                        <InputError message={retireForm.errors.retirement_reason} className="mt-1" />
                    </div>

                    <div className="flex items-center justify-end gap-2">
                        <button
                            type="button"
                            disabled={retireForm.processing}
                            onClick={() => setRetireOpen(false)}
                            className="btn-secondary text-xs"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            disabled={retireForm.processing}
                            className="px-4 py-2 rounded-lg text-xs font-semibold bg-red-700 text-white hover:bg-red-800 disabled:opacity-50"
                        >
                            {retireForm.processing ? 'Retiring…' : 'Confirm Retirement'}
                        </button>
                    </div>
                </form>
            )}

            {/* Tabs */}
            <div className="bg-white rounded-t-xl border border-b-0 border-gray-200">
                <div
                    role="tablist"
                    aria-label="Risk Library item sections"
                    className="flex gap-8 px-6 border-b border-gray-200 overflow-x-auto"
                >
                    {tabs.map((t, index) => (
                        <button
                            key={t.key}
                            id={`tab-${t.key}`}
                            type="button"
                            role="tab"
                            aria-selected={tab === t.key}
                            aria-controls={`panel-${t.key}`}
                            onClick={() => setTab(t.key)}
                            onKeyDown={(e) => handleTabKeyDown(e, index)}
                            className={`border-b-2 py-4 text-sm whitespace-nowrap transition-colors ${
                                tab === t.key
                                    ? 'border-[#1A365D] text-[#1A365D] font-semibold'
                                    : 'border-transparent text-gray-600 hover:text-[#1A365D]'
                            }`}
                        >
                            {t.label}
                        </button>
                    ))}
                </div>
            </div>

            <div className="bg-white rounded-b-xl border border-t-0 border-gray-200 p-6">
                {/* TAB 1: Definition */}
                {tab === 'definition' && (
                    <div id="panel-definition" role="tabpanel" aria-labelledby="tab-definition" className="space-y-6">
                        <div className="rounded-lg border border-blue-200 bg-blue-50/50 px-4 py-3 text-xs text-[#1A365D] flex items-center gap-2">
                            <span className="material-symbols-outlined text-base">info</span>
                            <span>
                                Library definitions carry no likelihood or impact scores. Scores are
                                assessed on each entity or process instance.
                            </span>
                        </div>

                        {/* Metadata Grid */}
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-4 p-4 rounded-xl bg-gray-50 border border-gray-200">
                            <div>
                                <p className="text-xs font-medium text-gray-500 uppercase tracking-wide">
                                    Category
                                </p>
                                <p className="text-sm font-semibold text-gray-900 mt-1">
                                    {item.category?.name ?? '—'}
                                </p>
                            </div>
                            <div>
                                <p className="text-xs font-medium text-gray-500 uppercase tracking-wide">
                                    Risk Source
                                </p>
                                <p className="text-sm font-semibold text-gray-900 mt-1">
                                    {item.risk_source ? humaniseAction(item.risk_source) : '—'}
                                </p>
                            </div>
                            <div>
                                <p className="text-xs font-medium text-gray-500 uppercase tracking-wide">
                                    Basel II/III Event Type
                                </p>
                                <p className="text-sm font-semibold text-gray-900 mt-1">
                                    {item.basel_l1 ? (
                                        <>
                                            {item.basel_l1.label}
                                            {item.basel_l2 && (
                                                <span className="block text-xs font-normal text-gray-600 mt-0.5">
                                                    L2: {item.basel_l2.label}
                                                </span>
                                            )}
                                        </>
                                    ) : (
                                        '—'
                                    )}
                                </p>
                            </div>
                        </div>

                        {/* Plain-text Description */}
                        <div>
                            <h3 className="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">
                                Risk Description
                            </h3>
                            <p className="text-sm text-gray-800 whitespace-pre-line leading-relaxed">
                                {item.description}
                            </p>
                        </div>

                        {/* 2x2 Causes / Consequences / Controls / KRIs */}
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            {/* Typical Causes */}
                            <div className="rounded-xl border border-gray-200 p-4">
                                <h3 className="text-sm font-semibold text-[#1A365D] mb-3">
                                    Typical Causes
                                </h3>
                                {(item.typical_causes ?? []).length === 0 ? (
                                    <p className="text-xs text-gray-400 italic">None listed</p>
                                ) : (
                                    <ul className="space-y-2">
                                        {item.typical_causes.map((c, idx) => (
                                            <li
                                                key={idx}
                                                className="text-sm text-gray-800 flex items-start justify-between gap-2"
                                            >
                                                <span>{c.cause}</span>
                                                {c.cause_category_name && (
                                                    <span className="shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-[11px] bg-gray-100 text-gray-700">
                                                        {c.cause_category_name}
                                                    </span>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>

                            {/* Typical Consequences */}
                            <div className="rounded-xl border border-gray-200 p-4">
                                <h3 className="text-sm font-semibold text-[#1A365D] mb-3">
                                    Typical Consequences
                                </h3>
                                {(item.typical_consequences ?? []).length === 0 ? (
                                    <p className="text-xs text-gray-400 italic">None listed</p>
                                ) : (
                                    <ul className="space-y-2">
                                        {item.typical_consequences.map((c, idx) => (
                                            <li
                                                key={idx}
                                                className="text-sm text-gray-800 flex items-start justify-between gap-2"
                                            >
                                                <span>{c.consequence}</span>
                                                {c.impact_domain && (
                                                    <span className="shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-[11px] bg-blue-50 text-[#1A365D]">
                                                        {c.impact_domain}
                                                    </span>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>

                            {/* Suggested Controls */}
                            <div className="rounded-xl border border-gray-200 p-4">
                                <h3 className="text-sm font-semibold text-[#1A365D] mb-3">
                                    Suggested Controls
                                </h3>
                                {(item.suggested_controls ?? []).length === 0 ? (
                                    <p className="text-xs text-gray-400 italic">None listed</p>
                                ) : (
                                    <ul className="space-y-2">
                                        {item.suggested_controls.map((c, idx) => {
                                            const controlUrl = c.control_id
                                                ? tryRoute('risk.controls.show', c.control_id)
                                                : null;
                                            return (
                                                <li
                                                    key={idx}
                                                    className="text-sm text-gray-800 flex items-start justify-between gap-2"
                                                >
                                                    {controlUrl ? (
                                                        <Link
                                                            href={controlUrl}
                                                            className="text-[#1A365D] hover:underline font-medium"
                                                        >
                                                            {c.title}
                                                        </Link>
                                                    ) : (
                                                        <span>{c.title}</span>
                                                    )}
                                                    {c.control_type && (
                                                        <span className="shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-[11px] bg-gray-100 text-gray-700">
                                                            {humaniseAction(c.control_type)}
                                                        </span>
                                                    )}
                                                </li>
                                            );
                                        })}
                                    </ul>
                                )}
                            </div>

                            {/* Suggested KRIs */}
                            <div className="rounded-xl border border-gray-200 p-4">
                                <h3 className="text-sm font-semibold text-[#1A365D] mb-3">
                                    Suggested KRIs
                                </h3>
                                {(item.suggested_kris ?? []).length === 0 ? (
                                    <p className="text-xs text-gray-400 italic">None listed</p>
                                ) : (
                                    <ul className="space-y-2">
                                        {item.suggested_kris.map((k, idx) => {
                                            const kriUrl = k.kri_id
                                                ? tryRoute('risk.kri.show', k.kri_id)
                                                : null;
                                            return (
                                                <li
                                                    key={idx}
                                                    className="text-sm text-gray-800 flex items-start justify-between gap-2"
                                                >
                                                    {kriUrl ? (
                                                        <Link
                                                            href={kriUrl}
                                                            className="text-[#1A365D] hover:underline font-medium"
                                                        >
                                                            {k.name}
                                                        </Link>
                                                    ) : (
                                                        <span>{k.name}</span>
                                                    )}
                                                    {k.metric_unit && (
                                                        <span className="shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-mono bg-gray-100 text-gray-700">
                                                            {k.metric_unit}
                                                        </span>
                                                    )}
                                                </li>
                                            );
                                        })}
                                    </ul>
                                )}
                            </div>
                        </div>

                        <div className="text-xs text-gray-500 pt-2 border-t border-gray-100 flex flex-wrap gap-x-6 gap-y-1">
                            <span>Created by: {item.created_by_name ?? 'System'}</span>
                            <span>Last updated: {formatDateTime(item.updated_at)}</span>
                        </div>
                    </div>
                )}

                {/* TAB 2: Version History & Interactive Side-by-Side Diff */}
                {tab === 'versions' && (
                    <div id="panel-versions" role="tabpanel" aria-labelledby="tab-versions" className="space-y-6">
                        {sortedVersions.length < 2 ? (
                            <div className="rounded-xl border border-gray-200 bg-gray-50 p-5 text-sm text-gray-600">
                                Only <strong>v1</strong> exists — edit this item to publish v2 and compare
                                versions side by side.
                            </div>
                        ) : (
                            <div className="rounded-xl border border-gray-200 overflow-hidden">
                                {/* Compare Selector Bar */}
                                <div className="bg-gray-50 px-5 py-3.5 border-b border-gray-200 flex flex-wrap items-center justify-between gap-3">
                                    <div className="flex flex-wrap items-center gap-3">
                                        <div className="flex items-center gap-2">
                                            <label
                                                htmlFor="diff-from"
                                                className="text-xs font-semibold text-gray-700"
                                            >
                                                Compare from:
                                            </label>
                                            <select
                                                id="diff-from"
                                                value={fromVersion}
                                                onChange={(e) => setFromVersion(Number(e.target.value))}
                                                className="px-2.5 py-1.5 border border-gray-300 rounded-lg text-xs bg-white font-mono"
                                            >
                                                {sortedVersions.map((v) => (
                                                    <option key={v.version_number} value={v.version_number}>
                                                        v{v.version_number} ({formatDate(v.created_at)})
                                                    </option>
                                                ))}
                                            </select>
                                        </div>

                                        <span className="text-xs text-gray-400">→</span>

                                        <div className="flex items-center gap-2">
                                            <label
                                                htmlFor="diff-to"
                                                className="text-xs font-semibold text-gray-700"
                                            >
                                                to:
                                            </label>
                                            <select
                                                id="diff-to"
                                                value={toVersion}
                                                onChange={(e) => setToVersion(Number(e.target.value))}
                                                className="px-2.5 py-1.5 border border-gray-300 rounded-lg text-xs bg-white font-mono"
                                            >
                                                {sortedVersions.map((v) => (
                                                    <option key={v.version_number} value={v.version_number}>
                                                        v{v.version_number} ({formatDate(v.created_at)})
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                    </div>

                                    {unchangedFields.length > 0 && (
                                        <button
                                            type="button"
                                            onClick={() => setShowUnchanged((prev) => !prev)}
                                            className="text-xs font-medium text-[#1A365D] hover:underline"
                                        >
                                            {showUnchanged
                                                ? 'Hide unchanged fields'
                                                : `Show unchanged fields (${unchangedFields.length})`}
                                        </button>
                                    )}
                                </div>

                                {/* Target version metadata */}
                                {toSnap && (
                                    <div className="px-5 py-3 bg-white border-b border-gray-200 text-xs text-gray-600 flex flex-wrap items-center justify-between gap-2">
                                        <span>
                                            <strong className="text-gray-900">v{toSnap.version_number} summary:</strong>{' '}
                                            {toSnap.change_summary || 'Initial version'}
                                        </span>
                                        <span>
                                            By {toSnap.created_by_name ?? 'System'} · {formatDateTime(toSnap.created_at)}
                                        </span>
                                    </div>
                                )}

                                {/* Side-by-Side Diff Rows */}
                                <div className="p-5 space-y-4">
                                    {changedFields.length === 0 ? (
                                        <p className="text-sm text-gray-500 text-center py-4">
                                            No field differences between v{fromVersion} and v{toVersion}.
                                        </p>
                                    ) : (
                                        changedFields.map((field) => (
                                            <div key={field.key} className="space-y-1.5">
                                                <p className="text-xs font-semibold text-gray-700">
                                                    {field.label}
                                                </p>
                                                <div className="grid grid-cols-1 lg:grid-cols-2 gap-3">
                                                    <div className="rounded-lg border border-red-200 bg-red-50/40 p-3">
                                                        <div className="mb-1.5">
                                                            <span className="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold bg-red-100 text-red-800">
                                                                − Previous (v{fromVersion})
                                                            </span>
                                                        </div>
                                                        <DiffValue value={field.before} isList={field.isList} />
                                                    </div>
                                                    <div className="rounded-lg border border-green-200 bg-green-50/40 p-3">
                                                        <div className="mb-1.5">
                                                            <span className="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold bg-green-100 text-[#2D7D46]">
                                                                + Updated (v{toVersion})
                                                            </span>
                                                        </div>
                                                        <DiffValue value={field.after} isList={field.isList} />
                                                    </div>
                                                </div>
                                            </div>
                                        ))
                                    )}

                                    {showUnchanged && unchangedFields.length > 0 && (
                                        <div className="pt-4 border-t border-gray-200 space-y-3">
                                            <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide">
                                                Unchanged Fields
                                            </p>
                                            {unchangedFields.map((field) => (
                                                <div
                                                    key={field.key}
                                                    className="rounded-lg border border-gray-200 bg-gray-50/60 p-3"
                                                >
                                                    <p className="text-xs font-semibold text-gray-600 mb-1">
                                                        {field.label}
                                                    </p>
                                                    <DiffValue value={field.after} isList={field.isList} />
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            </div>
                        )}

                        {/* Version Timeline */}
                        <div>
                            <h3 className="text-sm font-semibold text-[#1A365D] mb-3">
                                Immutable Version Timeline
                            </h3>
                            <div className="space-y-3">
                                {sortedVersions.map((v) => (
                                    <div
                                        key={v.version_number}
                                        className="rounded-xl border border-gray-200 p-4 flex flex-wrap items-start justify-between gap-3"
                                    >
                                        <div className="space-y-1">
                                            <div className="flex items-center gap-2">
                                                <span className="inline-flex items-center px-2 py-0.5 rounded font-mono text-xs font-semibold bg-[#1A365D] text-white">
                                                    v{v.version_number}
                                                </span>
                                                {v.version_number === item.current_version && (
                                                    <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-green-50 text-[#2D7D46]">
                                                        Current
                                                    </span>
                                                )}
                                                <span className="text-xs text-gray-500">
                                                    {formatDateTime(v.created_at)} · {v.created_by_name ?? 'System'}
                                                </span>
                                            </div>
                                            <p className="text-sm text-gray-800">
                                                {v.change_summary || 'Initial version'}
                                            </p>
                                        </div>

                                        {v.version_number !== item.current_version && (
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    setFromVersion(v.version_number);
                                                    setToVersion(item.current_version);
                                                }}
                                                className="btn-secondary text-xs"
                                            >
                                                Compare with current (v{item.current_version})
                                            </button>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>
                )}

                {/* TAB 3: Linked Instances */}
                {tab === 'instances' && (
                    <div id="panel-instances" role="tabpanel" aria-labelledby="tab-instances" className="space-y-4">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <p className="text-sm text-gray-700">
                                <strong>{instances.total ?? 0}</strong> linked risk{' '}
                                {(instances.total ?? 0) === 1 ? 'instance' : 'instances'} (
                                <strong>{instances.outdated_count ?? 0}</strong> behind current version v
                                {item.current_version})
                            </p>
                            {isActive && can.bulkApply && (
                                <button
                                    type="button"
                                    onClick={() => setBulkApplyOpen(true)}
                                    className="btn-secondary text-xs inline-flex items-center gap-1"
                                >
                                    <span className="material-symbols-outlined text-sm">library_add</span>
                                    Apply to Entities
                                </button>
                            )}
                        </div>

                        {/* Rows are register data, listed only within the caller's register reach
                            (RiskLibraryPresenter::show). Say so rather than implying there are none. */}
                        {(instances.total ?? 0) > 0 && !instances.can_view_rows && (
                            <p className="text-sm text-gray-600 bg-gray-50 border border-gray-200 rounded-lg px-4 py-3">
                                You can see how many risks use this definition, but not the risks themselves:
                                that needs access to the Risk Register.
                            </p>
                        )}
                        {instances.can_view_rows && (instances.hidden_count ?? 0) > 0 && (
                            <p className="text-sm text-gray-600 bg-gray-50 border border-gray-200 rounded-lg px-4 py-3">
                                {instances.truncated
                                    ? `Showing the first ${(instances.rows ?? []).length} instances you can access. `
                                    : ''}
                                {instances.hidden_count} {instances.hidden_count === 1 ? 'instance is' : 'instances are'} not
                                listed{instances.truncated ? '' : ' because they sit outside the part of the organisation you can view'}.
                            </p>
                        )}

                        {(instances.total ?? 0) === 0 ? (
                            <EmptyState
                                icon="account_tree"
                                title="No linked risk instances yet"
                                description="This library definition has not been applied to any entity or process yet."
                                actionLabel={isActive && can.bulkApply ? 'Apply to Entities' : null}
                                onAction={isActive && can.bulkApply ? () => setBulkApplyOpen(true) : undefined}
                            />
                        ) : (instances.rows ?? []).length === 0 ? null : (
                            <div className="overflow-x-auto">
                                <table className="data-table w-full">
                                    <thead>
                                        <tr>
                                            <th>Risk Code</th>
                                            <th>Entity</th>
                                            <th>Process</th>
                                            <th>Owner</th>
                                            <th>Residual Rating</th>
                                            <th>Version</th>
                                            <th>Last Synced</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {instances.rows.map((row) => (
                                            <tr key={row.id} className="hover:bg-blue-50/50">
                                                <td>
                                                    <Link
                                                        href={row.url}
                                                        className="font-mono font-semibold text-[#1A365D] hover:underline"
                                                    >
                                                        {row.risk_code}
                                                    </Link>
                                                </td>
                                                <td className="text-sm text-gray-800">{row.entity_name ?? '—'}</td>
                                                <td className="text-sm text-gray-700">{row.process_name ?? '—'}</td>
                                                <td className="text-sm text-gray-700">{row.owner_name ?? 'Unassigned'}</td>
                                                <td>
                                                    {row.residual_rating ? (
                                                        <RatingBadge rating={row.residual_rating} />
                                                    ) : (
                                                        <span className="text-xs text-gray-400">Not assessed</span>
                                                    )}
                                                </td>
                                                <td>
                                                    <div className="flex items-center gap-1.5">
                                                        <span className="inline-flex items-center px-2 py-0.5 rounded bg-gray-100 text-gray-800 font-mono text-xs">
                                                            {row.library_version ? `v${row.library_version}` : '—'}
                                                        </span>
                                                        {row.is_outdated && (
                                                            <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-amber-50 text-amber-900 border border-amber-200">
                                                                Update pending
                                                            </span>
                                                        )}
                                                    </div>
                                                </td>
                                                <td className="text-xs text-gray-600">
                                                    {formatDate(row.library_synced_at)}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                )}

                {/* TAB 4: Audit Trail */}
                {tab === 'audit' && (
                    <div id="panel-audit" role="tabpanel" aria-labelledby="tab-audit">
                        {auditTrail.length === 0 ? (
                            <p className="text-sm text-gray-500">No audit entries recorded yet.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="data-table w-full">
                                    <thead>
                                        <tr>
                                            <th>Timestamp</th>
                                            <th>Actor</th>
                                            <th>Action</th>
                                            <th>Summary</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {auditTrail.map((entry) => (
                                            <tr key={entry.id}>
                                                <td className="text-xs text-gray-600 whitespace-nowrap">
                                                    {formatDateTime(entry.created_at)}
                                                </td>
                                                <td className="text-sm text-gray-800">{entry.actor_name}</td>
                                                <td className="text-xs font-medium text-[#1A365D]">
                                                    {humaniseAction(entry.action_type)}
                                                </td>
                                                <td className="text-sm text-gray-700">{entry.summary || '—'}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                )}
            </div>

            <BulkApplySlideOver
                open={bulkApplyOpen}
                onClose={() => setBulkApplyOpen(false)}
                initialItem={item}
            />
        </AuthenticatedLayout>
    );
}
