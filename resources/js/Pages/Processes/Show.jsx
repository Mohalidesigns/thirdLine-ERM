import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import EmptyState from '@thirdline/ui/Components/EmptyState';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import RatingBadge from '@thirdline/ui/Components/RatingBadge';
import StatusBadge from '@thirdline/ui/Components/StatusBadge';
import tryRoute from '@thirdline/ui/lib/tryRoute';

const CRITICALITY_BADGE = {
    critical: { label: 'Critical', cls: 'bg-red-50 text-red-700 border-red-200' },
    high: { label: 'High', cls: 'bg-orange-50 text-orange-700 border-orange-200' },
    medium: { label: 'Medium', cls: 'bg-amber-50 text-amber-700 border-amber-200' },
    low: { label: 'Low', cls: 'bg-green-50 text-[#2D7D46] border-green-200' },
};

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

function CriticalityBadge({ criticality }) {
    if (!criticality) {
        return (
            <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600 border border-gray-200">
                Unclassified
            </span>
        );
    }
    const meta = CRITICALITY_BADGE[criticality] ?? {
        label: criticality,
        cls: 'bg-gray-100 text-gray-700 border-gray-200',
    };
    return (
        <span
            className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold border ${meta.cls}`}
        >
            {meta.label}
        </span>
    );
}

/**
 * Business Process / Sub-Process Detail (ADR 0028 Phase 2).
 */
export default function Show({
    process,
    subProcesses = [],
    entities = [],
    risks = [],
    auditTrail = [],
    linkedCounts = { risks: 0, risks_hidden: 0, can_view_risks: false, entities: 0, entities_hidden: 0 },
    can = {},
}) {
    const isSubProcess = Boolean(process.is_sub_process);
    const isActive = Boolean(process.is_active);
    const [toggling, setToggling] = useState(false);

    const tabs = [
        ...(!isSubProcess
            ? [{ key: 'sub_processes', label: `Sub-Processes (${subProcesses.length})` }]
            : []),
        { key: 'entities', label: `Linked Entities (${linkedCounts.entities ?? entities.length})` },
        { key: 'risks', label: `Linked Risks (${linkedCounts.risks ?? risks.length})` },
        { key: 'audit', label: `Audit Trail (${auditTrail.length})` },
    ];

    const [tab, setTab] = useState(tabs[0].key);

    const handleTabKeyDown = (e, index) => {
        if (e.key === 'ArrowRight') {
            e.preventDefault();
            setTab(tabs[(index + 1) % tabs.length].key);
        } else if (e.key === 'ArrowLeft') {
            e.preventDefault();
            setTab(tabs[(index - 1 + tabs.length) % tabs.length].key);
        }
    };

    const handleToggleActive = () => {
        const promptText = isActive
            ? `Deactivate ${process.code} — ${process.name}? Linked risks and audit history will remain intact, but this process will be hidden from new pickers.`
            : `Reactivate ${process.code} — ${process.name}? It will reappear in active process selectors.`;

        if (!window.confirm(promptText)) {
            return;
        }

        setToggling(true);
        router.post(
            route('risk.processes.toggle-active', process.id),
            {},
            {
                preserveScroll: true,
                onFinish: () => setToggling(false),
            },
        );
    };

    const catalogueUrl = tryRoute('risk.processes.index');
    const editUrl = tryRoute('risk.processes.edit', process.id);
    const addSubProcessUrl = tryRoute('risk.processes.create')
        ? `${route('risk.processes.create')}?level=sub_process&parent_id=${process.id}`
        : null;

    const breadcrumbs = [
        ...(catalogueUrl ? [{ label: 'Process Catalogue', href: catalogueUrl }] : []),
        ...(isSubProcess && process.parent
            ? [{ label: process.parent.code, href: process.parent.url }]
            : []),
        { label: process.code },
    ];

    return (
        <AuthenticatedLayout title={process.code}>
            <Head title={`${process.code} — ${process.name} | Process Catalogue`} />

            <PageHeader
                title={`${process.code} — ${process.name}`}
                subtitle={
                    isSubProcess && process.parent
                        ? `Sub-Process of ${process.parent.code} — ${process.parent.name}`
                        : process.process_group
                          ? `${process.process_group.code} — ${process.process_group.name}`
                          : 'Ungrouped Business Process'
                }
                breadcrumbs={breadcrumbs}
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        {can.createSubProcess && addSubProcessUrl && (
                            <Link
                                href={addSubProcessUrl}
                                className="btn-secondary text-sm inline-flex items-center gap-1.5"
                            >
                                <span className="material-symbols-outlined text-lg">
                                    account_tree
                                </span>
                                + Add Sub-Process
                            </Link>
                        )}
                        {can.update && editUrl && (
                            <Link
                                href={editUrl}
                                className="btn-primary text-sm inline-flex items-center gap-1.5"
                            >
                                <span className="material-symbols-outlined text-lg">edit</span>
                                Edit
                            </Link>
                        )}
                        {can.update && (
                            <button
                                type="button"
                                disabled={toggling}
                                onClick={handleToggleActive}
                                className={`btn-secondary text-sm inline-flex items-center gap-1.5 disabled:opacity-50 ${
                                    isActive
                                        ? 'text-red-700 border-red-200 hover:bg-red-50'
                                        : 'text-[#2D7D46] border-green-200 hover:bg-green-50'
                                }`}
                            >
                                <span className="material-symbols-outlined text-lg">
                                    {isActive ? 'pause_circle' : 'play_circle'}
                                </span>
                                {toggling
                                    ? 'Updating…'
                                    : isActive
                                      ? 'Deactivate'
                                      : 'Reactivate'}
                            </button>
                        )}
                    </div>
                }
            />

            {/* Header Status & Hierarchy Badges */}
            <div className="flex flex-wrap items-center gap-2 mb-5">
                <StatusBadge status={isActive ? 'active' : 'inactive'} />
                <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-blue-50 text-[#1A365D] border border-blue-200">
                    {isSubProcess ? 'Sub-Process' : 'Top-Level Process'}
                </span>
                <CriticalityBadge criticality={process.criticality} />
                {process.apqc_code && (
                    <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-mono font-medium bg-gray-100 text-gray-800 border border-gray-200">
                        APQC {process.apqc_code}
                    </span>
                )}
            </div>

            {/* Metadata Summary Card */}
            <div className="bg-white rounded-xl border border-gray-200 p-6 mb-6 space-y-5">
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div>
                        <p className="text-xs font-medium text-gray-500 uppercase tracking-wide">
                            Process Group
                        </p>
                        <p className="text-sm font-semibold text-gray-900 mt-1">
                            {process.process_group
                                ? `${process.process_group.code} — ${process.process_group.name}`
                                : 'Ungrouped'}
                        </p>
                    </div>
                    <div>
                        <p className="text-xs font-medium text-gray-500 uppercase tracking-wide">
                            Department
                        </p>
                        <p className="text-sm font-semibold text-gray-900 mt-1">
                            {process.business_unit?.name ?? 'Enterprise / Cross-Departmental'}
                        </p>
                    </div>
                    <div>
                        <p className="text-xs font-medium text-gray-500 uppercase tracking-wide">
                            Process Owner
                        </p>
                        <p className="text-sm font-semibold text-gray-900 mt-1">
                            {process.owner?.name ?? '—'}
                        </p>
                        {process.owner?.email && (
                            <p className="text-xs text-gray-500">{process.owner.email}</p>
                        )}
                    </div>
                    <div>
                        <p className="text-xs font-medium text-gray-500 uppercase tracking-wide">
                            {isSubProcess ? 'Parent Process' : 'APQC PCF Code'}
                        </p>
                        <p className="text-sm font-semibold text-gray-900 mt-1">
                            {isSubProcess && process.parent ? (
                                <Link
                                    href={process.parent.url}
                                    className="text-[#1A365D] hover:underline"
                                >
                                    {process.parent.code} — {process.parent.name}
                                </Link>
                            ) : (
                                process.apqc_code ?? '—'
                            )}
                        </p>
                    </div>
                </div>

                {process.description && (
                    <div className="pt-4 border-t border-gray-100">
                        <h2 className="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">
                            Description
                        </h2>
                        <p className="text-sm text-gray-800 whitespace-pre-line leading-relaxed">
                            {process.description}
                        </p>
                    </div>
                )}

                <div className="text-xs text-gray-500 pt-3 border-t border-gray-100 flex flex-wrap justify-between gap-2">
                    <span>Last updated: {formatDateTime(process.updated_at)}</span>
                    <span>
                        ERM Criticality is qualitative; BCMS critical-service designation is managed
                        in Business Continuity.
                    </span>
                </div>
            </div>

            {/* Tabs */}
            <div className="bg-white rounded-t-xl border border-b-0 border-gray-200">
                <div
                    role="tablist"
                    aria-label="Process detail sections"
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
                {/* TAB: Sub-Processes */}
                {tab === 'sub_processes' && !isSubProcess && (
                    <div
                        id="panel-sub_processes"
                        role="tabpanel"
                        aria-labelledby="tab-sub_processes"
                        className="space-y-4"
                    >
                        <div className="flex items-center justify-between gap-3">
                            <p className="text-xs text-gray-600">
                                Level-3 sub-processes operating under <strong>{process.name}</strong>.
                            </p>
                            {can.createSubProcess && addSubProcessUrl && (
                                <Link
                                    href={addSubProcessUrl}
                                    className="btn-secondary text-xs inline-flex items-center gap-1"
                                >
                                    <span className="material-symbols-outlined text-base">add</span>
                                    Add Sub-Process
                                </Link>
                            )}
                        </div>

                        {subProcesses.length === 0 ? (
                            <EmptyState
                                icon="account_tree"
                                title="No sub-processes yet"
                                description="Break this process down into specific sub-processes for granular risk and control mapping."
                                actionLabel={
                                    can.createSubProcess && addSubProcessUrl
                                        ? '+ Add Sub-Process'
                                        : null
                                }
                                actionHref={can.createSubProcess ? addSubProcessUrl : null}
                            />
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="min-w-full divide-y divide-gray-200 text-sm">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th
                                                scope="col"
                                                className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase"
                                            >
                                                Code
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase"
                                            >
                                                Sub-Process Name
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase"
                                            >
                                                Owner
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase"
                                            >
                                                Criticality
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase"
                                            >
                                                Linked Risks
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase"
                                            >
                                                Status
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-100">
                                        {subProcesses.map((sub) => (
                                            <tr key={sub.id} className="hover:bg-gray-50">
                                                <td className="px-4 py-3 font-mono text-xs font-semibold">
                                                    <Link
                                                        href={sub.url}
                                                        className="text-[#1A365D] hover:underline"
                                                    >
                                                        {sub.code}
                                                    </Link>
                                                    {sub.apqc_code && (
                                                        <span className="block text-[11px] text-gray-500 font-normal">
                                                            APQC {sub.apqc_code}
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 font-medium text-gray-900">
                                                    <Link
                                                        href={sub.url}
                                                        className="hover:text-[#1A365D] hover:underline"
                                                    >
                                                        {sub.name}
                                                    </Link>
                                                </td>
                                                <td className="px-4 py-3 text-gray-700">
                                                    {sub.owner_name ?? '—'}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <CriticalityBadge
                                                        criticality={sub.criticality}
                                                    />
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums">
                                                    {sub.linked_risks_count ?? 0}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <StatusBadge
                                                        status={
                                                            sub.is_active ? 'active' : 'inactive'
                                                        }
                                                    />
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                )}

                {/* TAB: Linked Entities */}
                {tab === 'entities' && (
                    <div
                        id="panel-entities"
                        role="tabpanel"
                        aria-labelledby="tab-entities"
                    >
                        {(linkedCounts.entities_hidden ?? 0) > 0 && (
                            <p className="text-sm text-gray-600 bg-gray-50 border border-gray-200 rounded-lg px-4 py-3 mb-4">
                                {linkedCounts.entities_hidden} linked {linkedCounts.entities_hidden === 1 ? 'entity is' : 'entities are'} not listed because {linkedCounts.entities_hidden === 1 ? 'it sits' : 'they sit'} outside what you can view in Scoping.
                            </p>
                        )}
                        {(linkedCounts.entities ?? entities.length) === 0 ? (
                            <EmptyState
                                icon="domain"
                                title="No linked entities"
                                description="Attach this process to one or more entities in Scoping to track entity-level risk coverage."
                            />
                        ) : entities.length === 0 ? null : (
                            <div className="overflow-x-auto">
                                <table className="min-w-full divide-y divide-gray-200 text-sm">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th
                                                scope="col"
                                                className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase"
                                            >
                                                Entity Code
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase"
                                            >
                                                Entity Name
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase"
                                            >
                                                Type
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase"
                                            >
                                                Role
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-100">
                                        {entities.map((entity) => (
                                            <tr key={entity.id} className="hover:bg-gray-50">
                                                <td className="px-4 py-3 font-mono text-xs font-semibold">
                                                    <Link
                                                        href={entity.url}
                                                        className="text-[#1A365D] hover:underline"
                                                    >
                                                        {entity.entity_code}
                                                    </Link>
                                                </td>
                                                <td className="px-4 py-3 font-medium text-gray-900">
                                                    <Link
                                                        href={entity.url}
                                                        className="hover:text-[#1A365D] hover:underline"
                                                    >
                                                        {entity.name}
                                                    </Link>
                                                </td>
                                                <td className="px-4 py-3 text-gray-700">
                                                    {entity.entity_type ?? '—'}
                                                </td>
                                                <td className="px-4 py-3">
                                                    {entity.is_primary ? (
                                                        <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-blue-50 text-[#1A365D] border border-blue-200">
                                                            Primary
                                                        </span>
                                                    ) : (
                                                        <span className="text-xs text-gray-500">
                                                            Linked
                                                        </span>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                )}

                {/* TAB: Linked Risks */}
                {tab === 'risks' && (
                    <div id="panel-risks" role="tabpanel" aria-labelledby="tab-risks">
                        {(linkedCounts.risks_hidden ?? 0) > 0 && (
                            <p className="text-sm text-gray-600 bg-gray-50 border border-gray-200 rounded-lg px-4 py-3 mb-4">
                                {linkedCounts.can_view_risks ? `${linkedCounts.risks_hidden} linked ${linkedCounts.risks_hidden === 1 ? 'risk is' : 'risks are'} not listed: outside the part of the organisation you can view, or beyond the first 200.` : 'You can see how many risks are linked, but not the risks themselves: that needs access to the Risk Register.'}
                            </p>
                        )}
                        {(linkedCounts.risks ?? risks.length) === 0 ? (
                            <EmptyState
                                icon="shield"
                                title="No risks linked to this process"
                                description="Link risks from the Risk Register or Master Risk Library to establish risk coverage for this process."
                            />
                        ) : risks.length === 0 ? null : (
                            <div className="overflow-x-auto">
                                <table className="min-w-full divide-y divide-gray-200 text-sm">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th
                                                scope="col"
                                                className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase"
                                            >
                                                Risk Code
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase"
                                            >
                                                Title
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase"
                                            >
                                                Category
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase"
                                            >
                                                Entity
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase"
                                            >
                                                Owner
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase"
                                            >
                                                Residual Rating
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase"
                                            >
                                                Status
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-100">
                                        {risks.map((risk) => (
                                            <tr key={risk.id} className="hover:bg-gray-50">
                                                <td className="px-4 py-3 font-mono text-xs font-semibold">
                                                    <Link
                                                        href={risk.url}
                                                        className="text-[#1A365D] hover:underline"
                                                    >
                                                        {risk.risk_code}
                                                    </Link>
                                                    {risk.library_code && (
                                                        <span className="block text-[11px] font-normal text-gray-500">
                                                            Library: {risk.library_code}
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 font-medium text-gray-900">
                                                    <Link
                                                        href={risk.url}
                                                        className="hover:text-[#1A365D] hover:underline"
                                                    >
                                                        {risk.title}
                                                    </Link>
                                                </td>
                                                <td className="px-4 py-3 text-gray-700">
                                                    {risk.category_name ?? '—'}
                                                </td>
                                                <td className="px-4 py-3 text-gray-700">
                                                    {risk.entity_name ?? '—'}
                                                </td>
                                                <td className="px-4 py-3 text-gray-700">
                                                    {risk.owner_name ?? '—'}
                                                </td>
                                                <td className="px-4 py-3">
                                                    {risk.residual_rating ? (
                                                        <RatingBadge rating={risk.residual_rating} />
                                                    ) : (
                                                        <span className="text-xs text-gray-400">
                                                            Not assessed
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <StatusBadge status={risk.status} />
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                )}

                {/* TAB: Audit Trail */}
                {tab === 'audit' && (
                    <div id="panel-audit" role="tabpanel" aria-labelledby="tab-audit">
                        {auditTrail.length === 0 ? (
                            <EmptyState
                                icon="history"
                                title="No audit events recorded"
                                description="Changes to this process will appear here automatically."
                            />
                        ) : (
                            <div className="divide-y divide-gray-100">
                                {auditTrail.map((entry) => (
                                    <div
                                        key={entry.id}
                                        className="py-3.5 flex flex-wrap items-start justify-between gap-3"
                                    >
                                        <div>
                                            <p className="text-sm font-semibold text-gray-900">
                                                {humaniseAction(entry.action_type)}
                                            </p>
                                            {entry.summary && (
                                                <p className="text-xs text-gray-600 mt-0.5">
                                                    {entry.summary}
                                                </p>
                                            )}
                                            <p className="text-xs text-gray-500 mt-0.5">
                                                By {entry.actor_name}
                                            </p>
                                        </div>
                                        <span className="text-xs text-gray-500 tabular-nums">
                                            {formatDateTime(entry.created_at)}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
