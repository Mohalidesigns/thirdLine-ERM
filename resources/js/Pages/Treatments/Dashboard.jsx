import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DonutChart from '@/Components/DonutChart';
import HBarChart from '@/Components/HBarChart';
import KpiCard from '@/Components/KpiCard';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import TrendChart from '@/Components/TrendChart';
import { compactNaira, naira, progressTone, strategyTone, titleCase } from './format';

const STRATEGY_COLORS = ['#1A365D', '#553C9A', '#2D7D46', '#C53030'];

/**
 * The treatment plans dashboard (migration Phase 3.5:
 * risk/treatments/dashboard.blade.php).
 *
 * Every figure arrives computed by App\Services\Treatments\TreatmentPlanService
 * and pinned by tests/Feature/Characterisation/TreatmentDashboardStatsTest.
 *
 * The four Chart.js canvases are drawn by the house SVG/CSS components
 * instead, following the entity dashboard (Phase 3.1) — same data, no chart
 * dependency, and they render inside a server-side test.
 */
/**
 * Budget against actual spend, one pair of bars per strategy.
 *
 * Written out rather than reusing GroupedBarChart, whose row header prints the
 * raw series values — legible for a 1-to-5 score, unreadable for a figure in
 * hundreds of millions of naira. Same CSS-bar convention, money formatting.
 */
function BudgetBars({ rows }) {
    const max = Math.max(1, ...rows.flatMap((row) => [row.budget, row.actual]));

    return (
        <div className="space-y-4">
            {rows.map((row) => (
                <div key={row.strategy}>
                    <div className="flex items-center justify-between text-xs mb-1.5">
                        <span className="font-medium text-gray-700">{row.label}</span>
                        <span className="text-gray-500">
                            {naira(row.budget)} budgeted &middot;{' '}
                            <span className={row.actual > row.budget ? 'text-red-600 font-semibold' : 'text-gray-700'}>
                                {naira(row.actual)} spent
                            </span>
                        </span>
                    </div>
                    <div className="space-y-1">
                        {[
                            { key: 'budget', value: row.budget, color: '#1A365D' },
                            { key: 'actual', value: row.actual, color: row.actual > row.budget ? '#C53030' : '#D4AF37' },
                        ].map((bar) => (
                            <div key={bar.key} className="h-2 rounded-full bg-gray-100 overflow-hidden">
                                <div
                                    className="h-full rounded-full transition-all duration-500"
                                    style={{ width: `${(bar.value / max) * 100}%`, backgroundColor: bar.color }}
                                />
                            </div>
                        ))}
                    </div>
                </div>
            ))}

            <div className="flex items-center gap-4 pt-1">
                {[
                    { label: 'Budget', color: '#1A365D' },
                    { label: 'Actual Spend', color: '#D4AF37' },
                ].map((entry) => (
                    <span key={entry.label} className="flex items-center gap-1.5 text-xs text-gray-600">
                        <span className="w-3 h-0.5 rounded" style={{ backgroundColor: entry.color }} />
                        {entry.label}
                    </span>
                ))}
            </div>
        </div>
    );
}

function Panel({ title, icon, children, className = '' }) {
    return (
        <div className={`bg-white rounded-xl border border-gray-200 p-5 ${className}`}>
            <div className="flex items-center justify-between mb-4">
                <h3 className="text-sm font-semibold text-[#1A365D]">{title}</h3>
                <span className="material-symbols-outlined text-gray-400 text-lg">{icon}</span>
            </div>
            {children}
        </div>
    );
}

export default function Dashboard({
    stats = {},
    budgetByStrategy = [],
    strategyMix = [],
    statusMix = [],
    completionTrend = [],
    activeTreatments = [],
    upcomingDeadlines = [],
    recentActivity = [],
}) {
    const strategySlices = strategyMix.map((slice, index) => ({
        name: slice.label,
        value: slice.value,
        color: STRATEGY_COLORS[index % STRATEGY_COLORS.length],
    }));

    // Bars, not a donut: the five status bands OVERLAP — an overdue plan is
    // counted under In Progress as well — so they do not partition a whole and
    // must not be drawn as if they did. The Blade chart was a bar chart too.
    const statusBars = statusMix.map((slice) => ({ label: slice.label, count: slice.value }));

    return (
        <AuthenticatedLayout title="Treatment Plans Dashboard">
            <Head title="Treatment Plans Dashboard" />

            <PageHeader
                title="Treatment Plans Dashboard"
                subtitle="Monitor treatment plan progress, effectiveness, and resource allocation"
                breadcrumbs={[
                    { label: 'Treatment Plans', href: route('risk.treatments.index') },
                    { label: 'Dashboard' },
                ]}
                actions={
                    <Link href={route('risk.treatments.create')} className="btn-primary inline-flex items-center gap-2 text-sm">
                        <span className="material-symbols-outlined text-lg">add</span> New Plan
                    </Link>
                }
            />

            <div className="kpi-strip grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
                <KpiCard title="Total Plans" value={stats.totalPlans ?? 0} icon="assignment" color="primary" />
                <KpiCard title="Active Plans" value={stats.activePlans ?? 0} icon="play_circle" color="info" subtitle="Currently in progress" />
                <KpiCard title="Completed" value={stats.completedPlans ?? 0} icon="check_circle" color="success" />
                <KpiCard title="Overdue" value={stats.overduePlans ?? 0} icon="schedule" color="danger" subtitle="Past target date" />
                <KpiCard title="Total Budget" value={compactNaira(stats.totalBudget)} icon="account_balance" color="warning" subtitle="Allocated resources" />
                <KpiCard title="Avg. Effectiveness" value={`${stats.avgEffectiveness ?? 0}%`} icon="speed" color="success" />
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                <Panel title="Plans by Strategy" icon="donut_large">
                    <DonutChart data={strategySlices} />
                </Panel>

                <Panel title="Monthly Completion Trend" icon="show_chart">
                    <TrendChart
                        data={completionTrend}
                        series={[
                            { key: 'created', label: 'Created', color: '#1A365D' },
                            { key: 'completed', label: 'Completed', color: '#2D7D46' },
                        ]}
                    />
                </Panel>

                <Panel title="Plans by Status" icon="bar_chart">
                    <HBarChart data={statusBars} color="#1A365D" />
                </Panel>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div className="lg:col-span-2 bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <div className="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                        <h3 className="text-sm font-semibold text-[#1A365D]">Active Treatment Plans Progress</h3>
                        <Link href={route('risk.treatments.index')} className="text-xs text-[#1A365D] font-medium hover:underline">
                            View All
                        </Link>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="data-table">
                            <thead>
                                <tr>
                                    <th>Plan</th>
                                    <th>Linked Risk</th>
                                    <th>Strategy</th>
                                    <th>Progress</th>
                                    <th>Owner</th>
                                    <th>Target Date</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {activeTreatments.length === 0 && (
                                    <tr>
                                        <td colSpan={7} className="text-center py-8 text-gray-400">
                                            <span className="material-symbols-outlined text-3xl mb-2 block">assignment</span>
                                            No active treatment plans found
                                        </td>
                                    </tr>
                                )}
                                {activeTreatments.map((plan) => (
                                    <tr key={plan.id}>
                                        <td>
                                            <Link href={plan.url} className="text-[#1A365D] font-medium hover:underline">
                                                {plan.title}
                                            </Link>
                                        </td>
                                        <td className="text-xs text-gray-600">{plan.riskCode ?? '-'}</td>
                                        <td>
                                            <span className={`badge ${strategyTone(plan.strategy)}`}>{titleCase(plan.strategy)}</span>
                                        </td>
                                        <td>
                                            <div className="flex items-center gap-2">
                                                <div className="w-20 bg-gray-200 rounded-full h-2">
                                                    <div className={`h-2 rounded-full ${progressTone(plan.progress)}`} style={{ width: `${plan.progress}%` }} />
                                                </div>
                                                <span className="text-xs text-gray-600">{plan.progress}%</span>
                                            </div>
                                        </td>
                                        <td className="text-xs">{plan.owner ?? '-'}</td>
                                        <td className="text-xs text-gray-500">{plan.targetDate ?? '-'}</td>
                                        <td><StatusBadge status={plan.status} /></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

                <div className="bg-white rounded-xl border border-gray-200">
                    <div className="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                        <h3 className="text-sm font-semibold text-[#1A365D]">Recent Activity</h3>
                        <span className="material-symbols-outlined text-gray-400 text-lg">history</span>
                    </div>
                    <div className="p-4 space-y-3 max-h-[400px] overflow-y-auto">
                        {recentActivity.length === 0 && (
                            <div className="text-center py-6 text-gray-400 text-sm">
                                <span className="material-symbols-outlined text-2xl mb-1 block">history</span>
                                No recent activity
                            </div>
                        )}
                        {recentActivity.map((activity) => (
                            <div key={activity.id} className="flex items-start gap-3 p-3 rounded-lg bg-gray-50">
                                <div className="w-8 h-8 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center flex-shrink-0">
                                    <span className="material-symbols-outlined text-sm">{activity.icon}</span>
                                </div>
                                <div className="flex-1 min-w-0">
                                    <p className="text-xs text-gray-700 font-medium">{activity.description}</p>
                                    <p className="text-[10px] text-gray-500 mt-1">
                                        {activity.user ?? 'System'} &middot; {activity.at}
                                    </p>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            </div>

            <Panel title="Budget vs Actual Spend by Strategy" icon="account_balance_wallet" className="mt-6">
                <BudgetBars rows={budgetByStrategy} />
            </Panel>
        </AuthenticatedLayout>
    );
}
