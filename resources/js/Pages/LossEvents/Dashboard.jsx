import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import HBarChart from '@thirdline/ui/Components/HBarChart';
import KpiCard from '@thirdline/ui/Components/KpiCard';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import TrendChart from '@thirdline/ui/Components/TrendChart';
import { compactNaira, naira, severityTone, statusTone, titleCase } from './format';

/**
 * The loss-event dashboard (migration Phase 4.3:
 * risk/loss-events/dashboard.blade.php).
 *
 * Figures come from App\Services\LossEvents\LossEventDashboardService and are
 * pinned by tests/Feature/Characterisation/LossEventDashboardFiguresTest —
 * which could not exist before the port, because the monthly trend used
 * `MONTH()` and threw on the SQLite the suite runs on.
 */
export default function Dashboard({
    kpis = {},
    monthlyTrend = [],
    baselCategories = [],
    recentEvents = [],
    regulatoryAlerts = [],
    canCreate = false,
}) {
    const categoryBars = baselCategories.map((row) => ({ label: titleCase(row.category), count: row.loss }));

    return (
        <AuthenticatedLayout title="Loss Event Dashboard">
            <Head title="Loss Event Dashboard" />

            <PageHeader
                title="Loss Event Dashboard"
                subtitle={`Operational loss experience for ${new Date().getFullYear()}`}
                breadcrumbs={[{ label: 'Loss Events', href: route('risk.loss-events.index') }, { label: 'Dashboard' }]}
                actions={
                    canCreate && (
                        <Link href={route('risk.loss-events.create')} className="btn-primary inline-flex items-center gap-2 text-sm">
                            <span className="material-symbols-outlined text-lg">add</span> Report Loss Event
                        </Link>
                    )
                }
            />

            <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
                <KpiCard title="Events (YTD)" value={kpis.totalEvents ?? 0} icon="report" color="primary" />
                <KpiCard title="Gross Loss (YTD)" value={compactNaira(kpis.totalGrossLoss)} icon="trending_down" color="danger" />
                <KpiCard title="Recovered (YTD)" value={compactNaira(kpis.recoveredAmount)} icon="savings" color="success" />
                <KpiCard title="Net Loss (YTD)" value={compactNaira(kpis.netLossYtd)} icon="account_balance" color="warning" />
            </div>

            <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <KpiCard title="CBN Notifications Due" value={kpis.pendingCbnNotifications ?? 0} icon="gavel" color="danger" subtitle="Reportable and still open" />
                <KpiCard title="NFIU Filings Due" value={kpis.pendingNfiuFilings ?? 0} icon="description" color="warning" subtitle="Not yet filed" />
                <KpiCard title="Open Investigations" value={kpis.openInvestigations ?? 0} icon="search" color="info" />
                <KpiCard title="Near Misses (YTD)" value={kpis.nearMisses ?? 0} icon="shield" color="success" />
            </div>

            {regulatoryAlerts.length > 0 && (
                <div className="bg-white rounded-xl border border-red-200 overflow-hidden mb-6">
                    <div className="px-5 py-4 border-b border-red-100 flex items-center gap-2">
                        <span className="material-symbols-outlined text-red-600">notification_important</span>
                        <h3 className="text-sm font-semibold text-[#1A365D]">Regulatory notifications outstanding</h3>
                    </div>
                    <ul className="divide-y divide-gray-100">
                        {regulatoryAlerts.map((alert) => (
                            <li key={alert.id} className="flex items-center justify-between gap-4 px-5 py-3">
                                <div className="min-w-0">
                                    <Link href={alert.url} className="text-sm font-medium text-[#1A365D] hover:underline">
                                        {alert.reference}
                                    </Link>
                                    <p className="text-xs text-gray-500 mt-0.5">{alert.type}</p>
                                </div>
                                <span className={`badge shrink-0 ${alert.isOverdue ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700'}`}>
                                    {alert.isOverdue ? 'Overdue' : 'Due'} {alert.deadline ?? '—'}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <div className="bg-white rounded-xl border border-gray-200 p-5">
                    <h3 className="text-sm font-semibold text-[#1A365D] mb-1">Monthly Loss Trend</h3>
                    <p className="text-xs text-gray-400 mb-4">Events reported per month, this year</p>
                    <TrendChart data={monthlyTrend} series={[{ key: 'count', label: 'Events', color: '#1A365D' }]} />
                </div>

                <div className="bg-white rounded-xl border border-gray-200 p-5">
                    <h3 className="text-sm font-semibold text-[#1A365D] mb-1">Gross Loss by Basel Category</h3>
                    <p className="text-xs text-gray-400 mb-4">Naira, this year</p>
                    {categoryBars.length > 0
                        ? <HBarChart data={categoryBars} color="#C53030" formatLabel={(label) => label} />
                        : <p className="text-sm text-gray-400 py-8 text-center">No events recorded this year.</p>}
                </div>
            </div>

            <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <div className="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                    <h3 className="text-sm font-semibold text-[#1A365D]">Recent Loss Events</h3>
                    <Link href={route('risk.loss-events.index')} className="text-xs text-[#1A365D] font-medium hover:underline">
                        View register
                    </Link>
                </div>
                <div className="overflow-x-auto">
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Event</th>
                                <th>Business Unit</th>
                                <th>Category</th>
                                <th>Gross Loss</th>
                                <th>Severity</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            {recentEvents.length === 0 && (
                                <tr><td colSpan={8} className="text-center py-8 text-gray-400">No loss events reported.</td></tr>
                            )}
                            {recentEvents.map((event) => (
                                <tr key={event.id}>
                                    <td className="text-xs font-mono">
                                        <Link href={event.url} className="text-[#1A365D] hover:underline">{event.reference}</Link>
                                    </td>
                                    <td className="text-sm font-medium text-[#1A365D] max-w-xs truncate" title={event.title}>{event.title}</td>
                                    <td className="text-xs">{event.businessUnit ?? '—'}</td>
                                    <td className="text-xs">{titleCase(event.category)}</td>
                                    <td className="text-xs tabular-nums">{naira(event.grossLoss)}</td>
                                    <td><span className={`badge ${severityTone(event.severity)}`}>{titleCase(event.severity)}</span></td>
                                    <td><span className={`badge ${statusTone(event.status)}`}>{titleCase(event.status)}</span></td>
                                    <td className="text-xs text-gray-500">{event.dateOfLoss ?? '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
