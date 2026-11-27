import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import DonutChart from '@thirdline/ui/Components/DonutChart';
import EmptyState from '@thirdline/ui/Components/EmptyState';
import KpiCard from '@thirdline/ui/Components/KpiCard';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';

const CRITICALITY_META = {
    critical: { label: 'Critical', color: '#DC2626', badge: 'bg-red-50 text-red-700 border-red-200' },
    high: { label: 'High', color: '#EA580C', badge: 'bg-orange-50 text-orange-700 border-orange-200' },
    medium: { label: 'Medium', color: '#D97706', badge: 'bg-amber-50 text-amber-700 border-amber-200' },
    low: { label: 'Low', color: '#2D7D46', badge: 'bg-green-50 text-[#2D7D46] border-green-200' },
};

function CriticalityBadge({ criticality }) {
    if (!criticality) {
        return <span className="text-xs text-gray-400">—</span>;
    }
    const meta = CRITICALITY_META[criticality] ?? {
        label: criticality,
        badge: 'bg-gray-100 text-gray-700 border-gray-200',
    };
    return (
        <span
            className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold border ${meta.badge}`}
        >
            {meta.label}
        </span>
    );
}

/**
 * Process Dashboard (ADR 0028 Phase 2, process-dashboard-and-catalogue.md).
 *
 * Displays the APQC PCF-aligned 3-tier ERM process hierarchy overview,
 * risk coverage KPI (Standard §5: "Not assessed" when total_processes === 0),
 * Process Group breakdown, ERM Criticality distribution, and unmapped
 * Critical/High process attention list.
 */
export default function Dashboard({
    kpis = {},
    byGroup = [],
    byCriticality = [],
    unmappedCritical = [],
    can = {},
}) {
    const totalProcesses = kpis.total_processes ?? 0;
    const totalSubProcesses = kpis.total_sub_processes ?? 0;
    const totalGroups = kpis.total_groups ?? 0;
    const criticalProcesses = kpis.critical_processes ?? 0;
    const processesWithRisks = kpis.processes_with_risks ?? 0;
    const unmappedCount = kpis.unmapped_processes_count ?? 0;
    const riskCoverageUnavailable =
        kpis.risk_coverage_pct === null || kpis.risk_coverage_pct === undefined;

    const catalogueUrl = tryRoute('risk.processes.index');
    const groupsUrl = tryRoute('risk.process-groups.index');
    const createUrl = tryRoute('risk.processes.create');
    const exportCsvUrl = tryRoute('risk.processes.export.csv');
    const exportPdfUrl = tryRoute('risk.processes.export.pdf');
    const unmappedFilterUrl = catalogueUrl
        ? `${catalogueUrl}?filters[level]=process&filters[has_risks]=none`
        : null;

    const criticalitySlices = (byCriticality ?? [])
        .filter((row) => (row.count ?? 0) > 0)
        .map((row) => ({
            name: CRITICALITY_META[row.criticality]?.label ?? row.criticality,
            value: row.count ?? 0,
            color: CRITICALITY_META[row.criticality]?.color ?? '#64748B',
        }));

    const isEmpty = totalProcesses === 0 && totalSubProcesses === 0;

    return (
        <AuthenticatedLayout title="Process Dashboard">
            <Head title="Process Dashboard" />

            <PageHeader
                title="Process Dashboard"
                subtitle={`APQC PCF-aligned hierarchy · ${totalGroups} ${totalGroups === 1 ? 'group' : 'groups'} · ${totalProcesses} ${totalProcesses === 1 ? 'process' : 'processes'} · ${totalSubProcesses} ${totalSubProcesses === 1 ? 'sub-process' : 'sub-processes'}`}
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        {exportCsvUrl && (
                            <a
                                href={exportCsvUrl}
                                className="btn-secondary text-sm inline-flex items-center gap-1.5"
                            >
                                <span className="material-symbols-outlined text-lg">download</span>
                                Export CSV
                            </a>
                        )}
                        {exportPdfUrl && (
                            <a
                                href={exportPdfUrl}
                                className="btn-secondary text-sm inline-flex items-center gap-1.5"
                            >
                                <span className="material-symbols-outlined text-lg">picture_as_pdf</span>
                                Export PDF
                            </a>
                        )}
                        {catalogueUrl && (
                            <Link
                                href={catalogueUrl}
                                className="btn-secondary text-sm inline-flex items-center gap-1.5"
                            >
                                <span className="material-symbols-outlined text-lg">list_alt</span>
                                Process Catalogue
                            </Link>
                        )}
                        {groupsUrl && (
                            <Link
                                href={groupsUrl}
                                className="btn-secondary text-sm inline-flex items-center gap-1.5"
                            >
                                <span className="material-symbols-outlined text-lg">workspaces</span>
                                Process Groups
                            </Link>
                        )}
                        {can.create && createUrl && (
                            <Link
                                href={createUrl}
                                className="btn-primary text-sm inline-flex items-center gap-1.5"
                            >
                                <span className="material-symbols-outlined text-lg">add_circle</span>
                                New Process
                            </Link>
                        )}
                    </div>
                }
            />

            {/* KPI Tile Row */}
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6 gap-4 mb-6">
                <KpiCard
                    title="Processes"
                    value={totalProcesses}
                    icon="schema"
                    color="primary"
                />
                <KpiCard
                    title="Sub-Processes"
                    value={totalSubProcesses}
                    icon="account_tree"
                    color="info"
                />
                <KpiCard
                    title="Process Groups"
                    value={totalGroups}
                    icon="workspaces"
                    color="purple"
                />
                <KpiCard
                    title="Critical Processes"
                    value={criticalProcesses}
                    icon="priority_high"
                    color="danger"
                />
                {unmappedFilterUrl ? (
                    <Link
                        href={unmappedFilterUrl}
                        className="block rounded-xl focus:outline-none focus:ring-2 focus:ring-[#1A365D]"
                    >
                        <KpiCard
                            title="Processes with No Risks"
                            value={unmappedCount}
                            icon="warning"
                            color="warning"
                            subtitle={unmappedCount > 0 ? 'View unmapped' : 'All linked'}
                        />
                    </Link>
                ) : (
                    <KpiCard
                        title="Processes with No Risks"
                        value={unmappedCount}
                        icon="warning"
                        color="warning"
                    />
                )}
                <KpiCard
                    title="Risk Coverage"
                    value={riskCoverageUnavailable ? null : `${kpis.risk_coverage_pct}%`}
                    unavailable={riskCoverageUnavailable}
                    unavailableLabel="Not assessed"
                    subtitle={
                        riskCoverageUnavailable
                            ? 'No active processes'
                            : `${processesWithRisks} of ${totalProcesses} linked`
                    }
                    icon="verified_user"
                    color="success"
                />
            </div>

            {isEmpty ? (
                <div className="bg-white rounded-xl border border-gray-200 p-8">
                    <EmptyState
                        icon="account_tree"
                        title="No business processes registered yet"
                        description={
                            can.create
                                ? 'Register your enterprise processes and sub-processes to map operational risks and track risk coverage across value chains.'
                                : 'Contact your Risk Manager to register business processes in the Process Catalogue.'
                        }
                        actionLabel={can.create && createUrl ? 'New Process' : null}
                        actionHref={can.create ? createUrl : null}
                    />
                </div>
            ) : (
                <>
                    {/* Two-Column Breakdown: By Group (left) & Criticality (right) */}
                    <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                        <div className="lg:col-span-2 bg-white rounded-xl border border-gray-200 overflow-hidden">
                            <div className="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                                <div>
                                    <h2 className="text-sm font-semibold text-[#1A365D]">
                                        Processes by Group
                                    </h2>
                                    <p className="text-xs text-gray-500 mt-0.5">
                                        Click a group to filter the Process Catalogue
                                    </p>
                                </div>
                                {groupsUrl && (
                                    <Link
                                        href={groupsUrl}
                                        className="text-xs font-medium text-[#1A365D] hover:underline"
                                    >
                                        Manage groups
                                    </Link>
                                )}
                            </div>

                            {byGroup.length === 0 ? (
                                <div className="p-6 text-center text-sm text-gray-500">
                                    No process groups defined yet.
                                </div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-200 text-sm">
                                        <thead className="bg-gray-50">
                                            <tr>
                                                <th
                                                    scope="col"
                                                    className="px-5 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider"
                                                >
                                                    Process Group
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider"
                                                >
                                                    Processes
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider"
                                                >
                                                    Sub-Processes
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider"
                                                >
                                                    Linked Risks
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="px-5 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider"
                                                >
                                                    No Risks
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-100 bg-white">
                                            {byGroup.map((row) => {
                                                const groupFilterParam =
                                                    row.group_id === null
                                                        ? 'ungrouped'
                                                        : String(row.group_id);
                                                const targetHref = catalogueUrl
                                                    ? `${catalogueUrl}?filters[process_group_id]=${encodeURIComponent(groupFilterParam)}`
                                                    : null;

                                                return (
                                                    <tr
                                                        key={row.group_id ?? 'ungrouped'}
                                                        className="hover:bg-gray-50 transition-colors"
                                                    >
                                                        <td className="px-5 py-3.5">
                                                            <div className="flex flex-col gap-1">
                                                                {targetHref ? (
                                                                    <Link
                                                                        href={targetHref}
                                                                        className="font-medium text-[#1A365D] hover:underline"
                                                                    >
                                                                        {row.group_code ? (
                                                                            <span className="font-mono text-xs text-gray-500 mr-1.5">
                                                                                {row.group_code}
                                                                            </span>
                                                                        ) : null}
                                                                        {row.group_name}
                                                                    </Link>
                                                                ) : (
                                                                    <span className="font-medium text-gray-900">
                                                                        {row.group_name}
                                                                    </span>
                                                                )}
                                                                <div>
                                                                    {row.business_unit_name ? (
                                                                        <span className="text-xs text-gray-500">
                                                                            {row.business_unit_name}
                                                                        </span>
                                                                    ) : (
                                                                        <span className="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-medium bg-gray-100 text-gray-600">
                                                                            Enterprise / Cross-Departmental
                                                                        </span>
                                                                    )}
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td className="px-4 py-3.5 text-right tabular-nums font-medium text-gray-900">
                                                            {row.processes_count ?? 0}
                                                        </td>
                                                        <td className="px-4 py-3.5 text-right tabular-nums text-gray-700">
                                                            {row.sub_processes_count ?? 0}
                                                        </td>
                                                        <td className="px-4 py-3.5 text-right tabular-nums text-gray-700">
                                                            {row.linked_risks_count ?? 0}
                                                        </td>
                                                        <td className="px-5 py-3.5 text-right tabular-nums">
                                                            {(row.unmapped_count ?? 0) > 0 ? (
                                                                <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-50 text-amber-800 border border-amber-200">
                                                                    {row.unmapped_count}
                                                                </span>
                                                            ) : (
                                                                <span className="text-xs text-gray-400">0</span>
                                                            )}
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>

                        {/* Right: Criticality Breakdown Card */}
                        <div className="lg:col-span-1 bg-white rounded-xl border border-gray-200 p-5 flex flex-col justify-between">
                            <div>
                                <h2 className="text-sm font-semibold text-[#1A365D]">
                                    ERM Criticality Breakdown
                                </h2>
                                <p className="text-xs text-gray-500 mt-0.5 mb-5">
                                    Active top-level processes by qualitative ERM criticality
                                </p>

                                {criticalitySlices.length === 0 ? (
                                    <div className="flex items-center justify-center h-40 text-sm text-gray-500 text-center">
                                        No processes classified yet.
                                    </div>
                                ) : (
                                    <DonutChart data={criticalitySlices} />
                                )}
                            </div>

                            <p className="text-[11px] text-gray-500 mt-5 pt-3 border-t border-gray-100">
                                Qualitative ERM process criticality only; BCMS critical-service
                                classification is managed separately in Business Continuity.
                            </p>
                        </div>
                    </div>

                    {/* Attention Table: Critical / High Processes with Zero Linked Risks */}
                    <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                        <div className="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                            <div>
                                <h2 className="text-sm font-semibold text-[#1A365D]">
                                    Critical &amp; High Processes with Zero Linked Risks
                                </h2>
                                <p className="text-xs text-gray-500 mt-0.5">
                                    Priority coverage gaps requiring risk identification and linkage
                                </p>
                            </div>
                            {unmappedFilterUrl && unmappedCritical.length > 0 && (
                                <Link
                                    href={unmappedFilterUrl}
                                    className="text-xs font-medium text-[#1A365D] hover:underline"
                                >
                                    View all unmapped processes
                                </Link>
                            )}
                        </div>

                        {unmappedCritical.length === 0 ? (
                            <div className="px-5 py-6 flex items-center gap-3 text-sm text-[#2D7D46] bg-green-50/40">
                                <span className="material-symbols-outlined text-xl">verified</span>
                                <span className="font-medium">
                                    All critical and high processes have at least one linked risk.
                                </span>
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="min-w-full divide-y divide-gray-200 text-sm">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th
                                                scope="col"
                                                className="px-5 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider"
                                            >
                                                Code
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider"
                                            >
                                                Process
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider"
                                            >
                                                Process Group
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider"
                                            >
                                                Department
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider"
                                            >
                                                Owner
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider"
                                            >
                                                Criticality
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-5 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider"
                                            >
                                                Action
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-100 bg-white">
                                        {unmappedCritical.map((proc) => (
                                            <tr key={proc.id} className="hover:bg-gray-50">
                                                <td className="px-5 py-3 font-mono text-xs font-semibold text-[#1A365D]">
                                                    {proc.code}
                                                </td>
                                                <td className="px-4 py-3 font-medium text-gray-900">
                                                    {proc.name}
                                                </td>
                                                <td className="px-4 py-3 text-gray-700">
                                                    {proc.group_name}
                                                </td>
                                                <td className="px-4 py-3 text-gray-700">
                                                    {proc.department_name}
                                                </td>
                                                <td className="px-4 py-3 text-gray-700">
                                                    {proc.owner_name}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <CriticalityBadge criticality={proc.criticality} />
                                                </td>
                                                <td className="px-5 py-3 text-right">
                                                    <Link
                                                        href={proc.url}
                                                        className="text-xs font-semibold text-[#1A365D] hover:underline"
                                                    >
                                                        View Process
                                                    </Link>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                </>
            )}
        </AuthenticatedLayout>
    );
}
