import { Head, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import KpiCard from '@thirdline/ui/Components/KpiCard';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import RatingBadge from '@thirdline/ui/Components/RatingBadge';
import TrendChart from '@thirdline/ui/Components/TrendChart';
import { BAND_SERIES, INPUT, bandSeriesData, singleSeriesData } from './format';

/**
 * Risk trends (migration Phase 5.1: risk/analysis/trends.blade.php).
 *
 * The four series are point-in-time since 5.1: each month is scored as the
 * register stood THEN, through RiskRepository. Before that every month carried
 * today's score, so the lines could only climb and no risk could ever be seen
 * moving between bands.
 */
function MoversTable({ title, subtitle, rows, emptyMessage }) {
    return (
        <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div className="px-5 py-4 border-b border-gray-100">
                <h3 className="text-sm font-semibold text-[#1A365D]">{title}</h3>
                <p className="text-xs text-gray-400 mt-0.5">{subtitle}</p>
            </div>
            <table className="data-table">
                <thead>
                    <tr><th>Risk</th><th>Inherent</th><th>Residual</th><th>Gap</th></tr>
                </thead>
                <tbody>
                    {rows.length === 0 && (
                        <tr><td colSpan={4} className="text-center py-8 text-sm text-gray-400">{emptyMessage}</td></tr>
                    )}
                    {rows.map((row) => (
                        <tr key={row.risk_code}>
                            <td className="font-mono text-xs">{row.risk_code}</td>
                            <td><RatingBadge rating={String(row.inherent_rating ?? '').toLowerCase()} /></td>
                            <td><RatingBadge rating={String(row.residual_rating ?? '').toLowerCase()} /></td>
                            <td className="text-sm font-semibold">{row.score_change > 0 ? `−${row.score_change}` : `+${Math.abs(row.score_change)}`}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

export default function Trends({
    stats = {},
    ratingTrend,
    scoreTrend,
    categoryTrend,
    treatmentTrend,
    increasers = [],
    decreasers = [],
    window: range = {},
}) {
    const { data, setData } = useForm({ from: range.from ?? '', to: range.to ?? '' });

    const applyWindow = (e) => {
        e.preventDefault();
        router.get(route('risk.analysis.trends'), data, { preserveState: true, preserveScroll: true });
    };

    return (
        <AuthenticatedLayout title="Risk Trends">
            <Head title="Risk Trends" />

            <PageHeader
                title="Risk Trends"
                subtitle="How the register has moved over the selected window"
                breadcrumbs={[{ label: 'Analysis' }, { label: 'Trends' }]}
                actions={
                    <form onSubmit={applyWindow} className="flex items-end gap-2">
                        <div>
                            <label htmlFor="from" className="sr-only">From</label>
                            <input id="from" type="date" value={data.from} onChange={(e) => setData('from', e.target.value)} className={INPUT} />
                        </div>
                        <div>
                            <label htmlFor="to" className="sr-only">To</label>
                            <input id="to" type="date" value={data.to} onChange={(e) => setData('to', e.target.value)} className={INPUT} />
                        </div>
                        <button type="submit" className="btn-primary text-sm">Apply</button>
                    </form>
                }
            />

            <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <KpiCard title="Active Risks" value={stats.totalActiveRisks ?? 0} icon="warning" color="primary" subtitle={`${stats.activeRisksChange} since mid-window`} />
                <KpiCard title="Avg Inherent Score" value={stats.avgRiskScore ?? 0} icon="speed" color="info" subtitle={`${stats.avgScoreChange} since mid-window`} />
                <KpiCard title="New in Window" value={stats.newRisks ?? 0} icon="add_circle" color="warning" />
                <KpiCard title="Closed in Window" value={stats.closedRisks ?? 0} icon="task_alt" color="success" />
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <div className="bg-white rounded-xl border border-gray-200 p-5">
                    <h3 className="text-sm font-semibold text-[#1A365D]">Rating Mix</h3>
                    <p className="text-xs text-gray-400 mb-4">Counts per band at each month end, scored as the register stood then</p>
                    <TrendChart data={bandSeriesData(ratingTrend)} series={BAND_SERIES} />
                </div>

                <div className="bg-white rounded-xl border border-gray-200 p-5">
                    <h3 className="text-sm font-semibold text-[#1A365D]">Average Inherent Score</h3>
                    <p className="text-xs text-gray-400 mb-4">Mean across the register at each month end</p>
                    <TrendChart
                        data={singleSeriesData(scoreTrend, 'score')}
                        series={[{ key: 'score', label: 'Average score', color: '#1A365D' }]}
                    />
                </div>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <div className="bg-white rounded-xl border border-gray-200 p-5">
                    <h3 className="text-sm font-semibold text-[#1A365D]">Risks by Category</h3>
                    <p className="text-xs text-gray-400 mb-4">Where the register has grown</p>
                    <TrendChart
                        data={(categoryTrend?.labels ?? []).map((label, index) => ({
                            month: label,
                            ...Object.fromEntries((categoryTrend?.datasets ?? []).map((d) => [d.label, d.data?.[index] ?? 0])),
                        }))}
                        series={(categoryTrend?.datasets ?? []).map((d, i) => ({
                            key: d.label,
                            label: d.label,
                            color: ['#1A365D', '#2B6CB0', '#D4AF37', '#2D7D46', '#C53030', '#805AD5'][i % 6],
                        }))}
                    />
                </div>

                <div className="bg-white rounded-xl border border-gray-200 p-5">
                    <h3 className="text-sm font-semibold text-[#1A365D]">Treatment Progress</h3>
                    {/* Read from treatment_plans since 5.1. It used to be drawn
                        from the RISKS table and touched no plan at all. */}
                    <p className="text-xs text-gray-400 mb-4">
                        Plans completed in each month, and plans running past their target date at each month end
                    </p>
                    <TrendChart
                        data={(treatmentTrend?.labels ?? []).map((label, index) => ({
                            month: label,
                            completed: treatmentTrend.completed?.[index] ?? 0,
                            overdue: treatmentTrend.overdue?.[index] ?? 0,
                        }))}
                        series={[
                            { key: 'completed', label: 'Completed', color: '#2D7D46' },
                            { key: 'overdue', label: 'Overdue', color: '#C53030' },
                        ]}
                    />
                </div>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {/* Named for what they are since 5.1: this is the gap controls
                    close on each risk today, not movement over time. */}
                <MoversTable
                    title="Largest Control Benefit"
                    subtitle="Risks whose residual score sits furthest below inherent"
                    rows={increasers}
                    emptyMessage="No risk has both an inherent and a residual assessment yet."
                />
                <MoversTable
                    title="Residual Above Inherent"
                    subtitle="Where the residual assessment scores higher than the inherent one"
                    rows={decreasers}
                    emptyMessage="No risk scores higher after controls than before."
                />
            </div>
        </AuthenticatedLayout>
    );
}
