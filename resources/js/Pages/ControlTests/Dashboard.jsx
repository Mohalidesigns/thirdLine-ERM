import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import EmptyState from '@thirdline/ui/Components/EmptyState';
import KpiCard from '@thirdline/ui/Components/KpiCard';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import StatusBadge from '@thirdline/ui/Components/StatusBadge';

const shortDate = (value) =>
    value ? new Date(value).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '-';

const humanise = (value) =>
    value ? String(value).replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase()) : '';

function TestList({ title, tests, emptyMessage, showOverdue = false }) {
    return (
        <div className="bg-white rounded-xl border border-gray-200">
            <div className="px-5 py-4 border-b border-gray-100">
                <h3 className="text-sm font-semibold text-[#1A365D]">{title}</h3>
            </div>
            <div className="p-5">
                {tests.length === 0 ? (
                    <p className="text-sm text-gray-500">{emptyMessage}</p>
                ) : (
                    <ul className="divide-y divide-gray-100">
                        {tests.map((test) => {
                            const overdue =
                                showOverdue && test.scheduled_date && new Date(test.scheduled_date) < new Date();

                            return (
                                <li key={test.id} className="py-3 flex items-start justify-between gap-4">
                                    <div className="min-w-0">
                                        <Link
                                            href={route('risk.control-tests.show', test.id)}
                                            className="text-sm font-medium text-[#1A365D] hover:underline"
                                        >
                                            {test.test_code}
                                        </Link>
                                        <p className="text-xs text-gray-500 truncate">{test.title}</p>
                                        <p className="text-xs text-gray-400">
                                            {test.control_code} · {test.tester ?? 'Unassigned'}
                                        </p>
                                    </div>
                                    <div className="text-right flex-shrink-0">
                                        <StatusBadge status={test.status} />
                                        <p className={`text-xs mt-1 ${overdue ? 'text-red-600 font-medium' : 'text-gray-500'}`}>
                                            {shortDate(test.scheduled_date)}
                                            {overdue && ' · overdue'}
                                        </p>
                                        {test.result && <p className="text-xs text-gray-500">{humanise(test.result)}</p>}
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>
        </div>
    );
}

/** Migration Phase 3.4: risk/controls/testing-dashboard.blade.php. */
export default function Dashboard({
    totalTests = 0,
    scheduledTests = 0,
    inProgress = 0,
    completedTests = 0,
    overdueTests = 0,
    passRate = 0,
    recentTests = [],
    upcomingTests = [],
}) {
    return (
        <AuthenticatedLayout title="Control Testing">
            <Head title="Control Testing" />

            <PageHeader
                title="Control Testing"
                subtitle={`${totalTests} test${totalTests === 1 ? '' : 's'} recorded`}
                breadcrumbs={[{ label: 'Control Testing' }]}
                actions={
                    <>
                        <Link href={route('risk.control-tests.index')} className="btn-secondary text-sm">
                            All tests
                        </Link>
                        <Link href={route('risk.control-tests.create')} className="btn-primary text-sm inline-flex items-center gap-2">
                            <span className="material-symbols-outlined text-lg">add_circle</span> Schedule a test
                        </Link>
                    </>
                }
            />

            <div className="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
                <KpiCard icon="fact_check" title="Total" value={totalTests} />
                <KpiCard icon="event" title="Scheduled" value={scheduledTests} />
                <KpiCard icon="pending" title="In Progress" value={inProgress} />
                <KpiCard icon="task_alt" title="Completed" value={completedTests} />
                <KpiCard
                    icon="warning"
                    title="Overdue"
                    value={overdueTests}
                    color={overdueTests > 0 ? 'danger' : 'primary'}
                    subtitle={overdueTests > 0 ? 'Scheduled, past due' : 'None past due'}
                />
                {/* passRate is a real measurement even at zero — zero of the
                    completed tests passed — so it is a figure, not an
                    unavailable tile. It reads 0 only when nothing is completed,
                    which the subtitle says. */}
                <KpiCard
                    icon="percent"
                    title="Pass Rate"
                    value={`${passRate}%`}
                    subtitle={completedTests > 0 ? `of ${completedTests} completed` : 'Nothing completed yet'}
                    color={passRate >= 80 ? 'success' : passRate >= 50 ? 'warning' : 'primary'}
                />
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <TestList
                    title="Upcoming Tests"
                    tests={upcomingTests}
                    emptyMessage="Nothing is scheduled."
                    showOverdue
                />
                <TestList
                    title="Recent Activity"
                    tests={recentTests}
                    emptyMessage="No tests have been recorded yet."
                />
            </div>

            {totalTests === 0 && (
                <div className="mt-6">
                    <EmptyState
                        icon="fact_check"
                        title="No control tests yet."
                        description="A control's effectiveness rating carries more weight once a test stands behind it."
                        actionLabel="Schedule the first test"
                        actionHref={route('risk.control-tests.create')}
                    />
                </div>
            )}
        </AuthenticatedLayout>
    );
}
