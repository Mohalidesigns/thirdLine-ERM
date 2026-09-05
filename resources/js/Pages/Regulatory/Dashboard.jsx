import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import EmptyState from '@/Components/EmptyState';
import tryRoute from '@/lib/tryRoute';

const IMPACT_CLASSES = {
    critical: 'bg-red-100 text-red-700',
    high: 'bg-orange-100 text-orange-700',
    medium: 'bg-yellow-100 text-yellow-700',
    low: 'bg-green-100 text-green-700',
};

const shortDate = (value) =>
    value ? new Date(value).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';

/**
 * Regulatory compliance dashboard (migration Phase 5.3).
 *
 * Figures from RegulatoryDashboardService, pinned by
 * Characterisation/RegulatoryDashboardTest.
 *
 * The compliance-rate tile reads "Not assessed" over an empty register rather
 * than 0%. `$total > 0 ? ... : 0` used to put a green "0%" in front of an
 * institution that had simply recorded no circulars — a claim of total
 * non-compliance made from no data.
 */
export default function Dashboard({
    totalCirculars,
    pendingCompliance,
    overdueCount,
    complianceRate,
    upcomingDeadlines,
    recentCirculars,
    regulatorStats,
}) {
    const calendarUrl = tryRoute('risk.regulatory.calendar');
    const deadlinesUrl = tryRoute('risk.regulatory.deadlines');
    const circularsUrl = tryRoute('risk.regulatory.circulars');

    return (
        <AuthenticatedLayout title="Regulatory Compliance">
            <Head title="Regulatory Compliance" />

            <PageHeader
                title="Regulatory Compliance"
                subtitle="Circulars from the regulators, and the filing calendar they are answered on"
                actions={
                    <>
                        {calendarUrl && (
                            <Link href={calendarUrl} className="btn-secondary text-sm inline-flex items-center gap-2">
                                <span className="material-symbols-outlined text-lg">calendar_month</span> Calendar
                            </Link>
                        )}
                        {circularsUrl && (
                            <Link href={circularsUrl} className="btn-secondary text-sm inline-flex items-center gap-2">
                                <span className="material-symbols-outlined text-lg">description</span> Circulars
                            </Link>
                        )}
                    </>
                }
            />

            <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <KpiCard title="Circulars on Record" value={totalCirculars} icon="description" color="info" />
                <KpiCard
                    title="Pending Compliance"
                    value={pendingCompliance}
                    icon="pending"
                    color={pendingCompliance > 0 ? 'warning' : 'success'}
                    subtitle="Not assessed, partial or non-compliant"
                />
                <KpiCard
                    title="Overdue Deadlines"
                    value={overdueCount}
                    icon="error"
                    color={overdueCount > 0 ? 'danger' : 'success'}
                />
                <KpiCard
                    title="Compliance Rate"
                    value={complianceRate === null ? null : `${complianceRate}%`}
                    unavailable={complianceRate === null}
                    unavailableLabel="No circulars on record"
                    icon="verified"
                    color={complianceRate !== null && complianceRate >= 80 ? 'success' : 'warning'}
                    subtitle="Compliant, over the register"
                />
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <section className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <header className="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                        <h3 className="text-sm font-semibold text-[#1A365D]">Upcoming Deadlines</h3>
                        {deadlinesUrl && (
                            <Link href={deadlinesUrl} className="text-xs text-[#1A365D] font-medium hover:underline">
                                View all
                            </Link>
                        )}
                    </header>

                    {upcomingDeadlines.length === 0 ? (
                        <p className="px-5 py-10 text-center text-sm text-gray-500">
                            Nothing due. Deadlines added to the calendar appear here as they approach.
                        </p>
                    ) : (
                        <ul className="divide-y divide-gray-100">
                            {upcomingDeadlines.map((deadline) => (
                                <li key={deadline.id} className="px-5 py-3 flex items-center gap-3">
                                    <span className="badge bg-blue-50 text-blue-700 text-[10px]">{deadline.regulator}</span>
                                    <div className="flex-1 min-w-0">
                                        <p className="text-sm font-medium text-gray-800 truncate">{deadline.title}</p>
                                        <p className="text-xs text-gray-500">{deadline.report_type}</p>
                                    </div>
                                    <span className="text-xs text-gray-500 shrink-0">{shortDate(deadline.deadline_date)}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <header className="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                        <h3 className="text-sm font-semibold text-[#1A365D]">Recent Circulars</h3>
                        {circularsUrl && (
                            <Link href={circularsUrl} className="text-xs text-[#1A365D] font-medium hover:underline">
                                View all
                            </Link>
                        )}
                    </header>

                    {recentCirculars.length === 0 ? (
                        <EmptyState
                            icon={<span className="material-symbols-outlined text-3xl text-gray-400">description</span>}
                            title="No circulars recorded"
                            description="Record a regulator's circular to track this institution's response to it."
                        />
                    ) : (
                        <ul className="divide-y divide-gray-100">
                            {recentCirculars.map((circular) => {
                                const url = tryRoute('risk.regulatory.show-circular', circular.id);

                                return (
                                    <li key={circular.id} className="px-5 py-3 flex items-center gap-3">
                                        <span className={`badge text-[10px] ${IMPACT_CLASSES[circular.impact_level] ?? 'bg-gray-100 text-gray-600'}`}>
                                            {circular.impact_level}
                                        </span>
                                        <div className="flex-1 min-w-0">
                                            <p className="text-sm font-medium text-gray-800 truncate">
                                                {url ? (
                                                    <Link href={url} className="hover:underline">
                                                        {circular.title}
                                                    </Link>
                                                ) : (
                                                    circular.title
                                                )}
                                            </p>
                                            <p className="text-xs text-gray-500">
                                                {circular.regulator} &middot; {circular.circular_ref}
                                            </p>
                                        </div>
                                        <span className="text-xs text-gray-500 shrink-0">{shortDate(circular.date_issued)}</span>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </section>
            </div>

            {regulatorStats.length > 0 && (
                <section className="bg-white rounded-xl border border-gray-200 p-5">
                    <h3 className="text-sm font-semibold text-[#1A365D] mb-4">Compliance by Regulator</h3>
                    <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
                        {regulatorStats.map((stat) => {
                            const rate = stat.total > 0 ? Math.round((stat.compliant_count / stat.total) * 100) : null;

                            return (
                                <div key={stat.regulator} className="p-4 bg-gray-50 rounded-lg">
                                    <p className="text-xs text-gray-500">{stat.regulator}</p>
                                    <p className="text-xl font-bold text-[#1A365D]">
                                        {stat.compliant_count}
                                        <span className="text-sm font-normal text-gray-400">/{stat.total}</span>
                                    </p>
                                    <p className="text-xs text-gray-500 mt-1">
                                        {rate === null ? 'nothing on record' : `${rate}% compliant`}
                                    </p>
                                </div>
                            );
                        })}
                    </div>
                </section>
            )}
        </AuthenticatedLayout>
    );
}
