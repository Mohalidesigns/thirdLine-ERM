import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DonutChart from '@/Components/DonutChart';
import HBarChart from '@/Components/HBarChart';
import KpiCard from '@/Components/KpiCard';
import PageHeader from '@/Components/PageHeader';
import RatingBadge from '@/Components/RatingBadge';
import StatusBadge from '@/Components/StatusBadge';

const RATING_COLORS = { Critical: '#C53030', High: '#DD6B20', Medium: '#D4AF37', Low: '#2D7D46' };
const EFFECTIVENESS_COLORS = ['#2D7D46', '#D4AF37', '#C53030', '#6B7280'];

const EFFECTIVENESS_TONE = {
    effective: 'bg-green-100 text-green-700',
    partially: 'bg-yellow-100 text-yellow-700',
    ineffective: 'bg-red-100 text-red-700',
};

const EFFECTIVENESS_LABEL = {
    effective: 'Effective',
    partially: 'Partially effective',
    ineffective: 'Ineffective',
};

function Panel({ title, children }) {
    return (
        <div className="bg-white rounded-xl border border-gray-200 p-5">
            <h3 className="text-sm font-semibold text-[#1A365D] mb-4">{title}</h3>
            {children}
        </div>
    );
}

function progressTone(progress) {
    if (progress >= 80) return 'bg-green-500';
    if (progress >= 50) return 'bg-yellow-500';

    return 'bg-red-500';
}

/**
 * The RCSA dashboard (migration Phase 3.8: risk/rcsa/dashboard.blade.php).
 *
 * Every figure arrives computed by App\Services\Rcsa\RcsaService and pinned by
 * tests/Feature/Characterisation/RcsaFiguresTest. The three Chart.js canvases
 * are the house SVG/CSS components, as in 3.1 and 3.5.
 */
export default function Dashboard({
    kpis = {},
    unitProgress = [],
    topRisks = [],
    riskDistribution = [],
    controlEffectiveness = [],
}) {
    const distributionSlices = riskDistribution.map((band) => ({
        name: band.rating,
        value: band.value,
        color: RATING_COLORS[band.rating] ?? '#6B7280',
    }));

    const effectivenessSlices = controlEffectiveness.map((band, index) => ({
        name: band.label,
        value: band.value,
        color: EFFECTIVENESS_COLORS[index % EFFECTIVENESS_COLORS.length],
    }));

    const completionBars = unitProgress.map((unit) => ({ label: unit.name, count: unit.progress }));

    return (
        <AuthenticatedLayout title="RCSA Dashboard">
            <Head title="RCSA Dashboard" />

            <PageHeader
                title="RCSA Dashboard"
                subtitle="Risk and Control Self-Assessment overview and completion tracking"
                breadcrumbs={[{ label: 'RCSA' }, { label: 'Dashboard' }]}
                actions={
                    <Link href={route('risk.rcsa.worksheet')} className="btn-primary inline-flex items-center gap-2 text-sm">
                        <span className="material-symbols-outlined text-lg">add</span> New Assessment
                    </Link>
                }
            />

            <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
                <KpiCard title="Total Assessments" value={kpis.total ?? 0} icon="assignment" color="primary" />
                <KpiCard title="Completed" value={kpis.completed ?? 0} icon="check_circle" color="success" subtitle="Assessed within 12 months" />
                <KpiCard title="In Progress" value={kpis.inProgress ?? 0} icon="pending" color="info" subtitle="Assessed 12–18 months ago" />
                <KpiCard title="Not Started" value={kpis.notStarted ?? 0} icon="schedule" color="warning" subtitle="Never assessed" />
                <KpiCard title="Overdue" value={kpis.overdue ?? 0} icon="error" color="danger" subtitle="Never, or over 12 months" />
                <KpiCard title="Completion Rate" value={`${kpis.completionRate ?? 0}%`} icon="percent" color="primary" />
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                <Panel title="Completion by Business Unit">
                    {completionBars.length > 0
                        ? <HBarChart data={completionBars} color="#1A365D" />
                        : <p className="text-sm text-gray-400 py-8 text-center">No business units yet</p>}
                </Panel>
                <Panel title="Risk Distribution">
                    <DonutChart data={distributionSlices} />
                </Panel>
                <Panel title="Control Effectiveness">
                    <DonutChart data={effectivenessSlices} />
                </Panel>
            </div>

            <div className="bg-white rounded-xl border border-gray-200 overflow-hidden mb-6">
                <div className="px-5 py-4 border-b border-gray-100">
                    <h3 className="text-sm font-semibold text-[#1A365D]">Assessment Progress by Business Unit</h3>
                </div>
                <div className="overflow-x-auto">
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th>Business Unit</th>
                                <th>Total Risks</th>
                                <th>Assessed</th>
                                <th>Progress</th>
                                <th>High Risks</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {unitProgress.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-center py-8 text-gray-400">
                                        <span className="material-symbols-outlined text-3xl mb-2 block">assignment</span>
                                        No business unit data available
                                    </td>
                                </tr>
                            )}
                            {unitProgress.map((unit) => (
                                <tr key={unit.id}>
                                    <td className="font-medium text-[#1A365D]">{unit.name}</td>
                                    <td className="text-xs">{unit.totalRisks}</td>
                                    <td className="text-xs">{unit.assessed}</td>
                                    <td>
                                        <div className="flex items-center gap-2">
                                            <div className="w-20 bg-gray-200 rounded-full h-2">
                                                <div className={`h-2 rounded-full ${progressTone(unit.progress)}`} style={{ width: `${unit.progress}%` }} />
                                            </div>
                                            <span className="text-xs">{unit.progress}%</span>
                                        </div>
                                    </td>
                                    <td className="text-xs font-semibold text-red-600">{unit.highRisks}</td>
                                    <td><StatusBadge status={unit.status.toLowerCase().replace(' ', '_')} /></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <div className="px-5 py-4 border-b border-gray-100">
                    <h3 className="text-sm font-semibold text-[#1A365D]">Top Risks by Residual Rating</h3>
                </div>
                <div className="overflow-x-auto">
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th>Risk</th>
                                <th>Business Unit</th>
                                <th>Inherent Rating</th>
                                <th>Residual Rating</th>
                                <th>Weakest Mapped Control</th>
                                <th>Controls</th>
                            </tr>
                        </thead>
                        <tbody>
                            {topRisks.length === 0 && (
                                <tr><td colSpan={6} className="text-center py-8 text-gray-400">No risks identified yet</td></tr>
                            )}
                            {topRisks.map((risk) => (
                                <tr key={risk.id}>
                                    <td className="font-medium text-[#1A365D]">{risk.title}</td>
                                    <td className="text-xs">{risk.businessUnit ?? '-'}</td>
                                    <td><RatingBadge rating={risk.inherentRating?.toLowerCase()} /></td>
                                    <td><RatingBadge rating={risk.residualRating?.toLowerCase()} /></td>
                                    <td>
                                        {risk.controlEffectiveness ? (
                                            <span className={`badge ${EFFECTIVENESS_TONE[risk.controlEffectiveness]}`}>
                                                {EFFECTIVENESS_LABEL[risk.controlEffectiveness]}
                                            </span>
                                        ) : (
                                            <span className="text-xs text-gray-400">Not assessed</span>
                                        )}
                                    </td>
                                    <td className="text-xs">{risk.controlCount}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
