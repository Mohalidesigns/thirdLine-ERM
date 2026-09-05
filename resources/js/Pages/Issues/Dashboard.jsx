import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DonutChart from '@/Components/DonutChart';
import HBarChart from '@/Components/HBarChart';
import KpiCard from '@/Components/KpiCard';
import PageHeader from '@/Components/PageHeader';
import { BAND_COLORS, priorityTone, titleCase } from './format';

/**
 * The issues dashboard (migration Phase 4.4: risk/issues/dashboard.blade.php).
 *
 * Figures come from App\Services\Issues\IssueDashboardService — twelve separate
 * COUNT queries became three grouped ones — and are pinned by
 * tests/Feature/Characterisation/IssueDashboardFiguresTest, which could not
 * exist before the port because `avgDaysToClose` used MySQL-only DATEDIFF().
 */
export default function Dashboard({ stats = {}, ageing = [], overdueIssues = [], canCreate = false }) {
    const ageingSlices = ageing.map((band, index) => ({
        name: band.label,
        value: band.value,
        color: BAND_COLORS[index % BAND_COLORS.length],
    }));

    const priorityBars = [
        { label: 'Critical', count: stats.critical ?? 0 },
        { label: 'High', count: stats.high ?? 0 },
        { label: 'Medium', count: stats.medium ?? 0 },
        { label: 'Low', count: stats.low ?? 0 },
    ];

    return (
        <AuthenticatedLayout title="Issues Dashboard">
            <Head title="Issues Dashboard" />

            <PageHeader
                title="Issues Dashboard"
                subtitle="Findings, remediation and what is running late"
                breadcrumbs={[{ label: 'Issues', href: route('risk.issues.index') }, { label: 'Dashboard' }]}
                actions={
                    canCreate && (
                        <Link href={route('risk.issues.create')} className="btn-primary inline-flex items-center gap-2 text-sm">
                            <span className="material-symbols-outlined text-lg">add</span> Raise Issue
                        </Link>
                    )
                }
            />

            <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
                <KpiCard title="Total Issues" value={stats.total ?? 0} icon="list_alt" color="primary" />
                <KpiCard title="Open" value={stats.openIssues ?? 0} icon="pending_actions" color="info" subtitle="Open and in progress" />
                <KpiCard title="Overdue" value={stats.overdue ?? 0} icon="error" color="danger" />
                <KpiCard title="Pending Closure" value={stats.pendingClosure ?? 0} icon="how_to_reg" color="warning" />
                <KpiCard title="CBN Findings" value={stats.cbnFindings ?? 0} icon="gavel" color="danger" subtitle="Open examination findings" />
                <KpiCard
                    title="Avg. Days to Close"
                    value={stats.avgDaysToClose ?? 0}
                    icon="timer"
                    color="success"
                    unavailable={(stats.closed ?? 0) === 0}
                    unavailableLabel="Nothing closed yet"
                />
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <div className="bg-white rounded-xl border border-gray-200 p-5">
                    <h3 className="text-sm font-semibold text-[#1A365D] mb-1">Ageing of Open Issues</h3>
                    {/* Half-open bands: every open issue falls in exactly one. */}
                    <p className="text-xs text-gray-400 mb-4">Days since the issue was raised</p>
                    <DonutChart data={ageingSlices} />
                </div>

                <div className="bg-white rounded-xl border border-gray-200 p-5">
                    <h3 className="text-sm font-semibold text-[#1A365D] mb-1">Open Issues by Priority</h3>
                    <p className="text-xs text-gray-400 mb-4">Closed and cancelled issues are excluded</p>
                    <HBarChart data={priorityBars} color="#1A365D" />
                </div>
            </div>

            <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <div className="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                    <h3 className="text-sm font-semibold text-[#1A365D]">Overdue Issues</h3>
                    <Link href={route('risk.issues.ageing')} className="text-xs text-[#1A365D] font-medium hover:underline">
                        Ageing report
                    </Link>
                </div>
                <div className="overflow-x-auto">
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Issue</th>
                                <th>Owner</th>
                                <th>Business Unit</th>
                                <th>Priority</th>
                                <th>Due</th>
                                <th>Days Late</th>
                            </tr>
                        </thead>
                        <tbody>
                            {overdueIssues.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="text-center py-10">
                                        <span className="material-symbols-outlined text-3xl text-green-300 mb-2 block">task_alt</span>
                                        <p className="text-sm text-gray-500">Nothing is overdue.</p>
                                    </td>
                                </tr>
                            )}
                            {overdueIssues.map((issue) => (
                                <tr key={issue.id}>
                                    <td className="text-xs font-mono">
                                        <Link href={issue.url} className="text-[#1A365D] hover:underline">{issue.reference}</Link>
                                    </td>
                                    <td className="text-sm font-medium max-w-xs truncate" title={issue.title}>{issue.title}</td>
                                    <td className="text-xs">{issue.owner ?? '—'}</td>
                                    <td className="text-xs">{issue.businessUnit ?? '—'}</td>
                                    <td><span className={`badge ${priorityTone(issue.priority)}`}>{titleCase(issue.priority)}</span></td>
                                    <td className="text-xs text-gray-500">{issue.dueDate ?? '—'}</td>
                                    <td className="text-xs font-semibold text-red-600">
                                        {issue.daysOverdue !== null ? `${issue.daysOverdue}` : '—'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
