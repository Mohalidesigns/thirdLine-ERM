import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DonutChart from '@/Components/DonutChart';
import KpiCard from '@/Components/KpiCard';
import PageHeader from '@/Components/PageHeader';
import TrendChart from '@/Components/TrendChart';

const STATUS = {
    red: { dot: 'bg-red-500', tile: 'border-red-300 bg-red-50', text: 'text-red-700' },
    amber: { dot: 'bg-yellow-500', tile: 'border-yellow-300 bg-yellow-50', text: 'text-yellow-700' },
    yellow: { dot: 'bg-yellow-400', tile: 'border-yellow-200 bg-yellow-50', text: 'text-yellow-700' },
    green: { dot: 'bg-green-500', tile: 'border-green-300 bg-green-50', text: 'text-green-700' },
};

const SLICE_COLORS = { Green: '#2D7D46', Amber: '#D4AF37', Red: '#C53030' };

function tone(status) {
    return STATUS[status] ?? STATUS.green;
}

function TrendIcon({ trend }) {
    if (trend === 'up') return <span className="material-symbols-outlined text-xs text-red-500">trending_up</span>;
    if (trend === 'down') return <span className="material-symbols-outlined text-xs text-green-500">trending_down</span>;

    return <span className="material-symbols-outlined text-xs text-gray-400">trending_flat</span>;
}

/**
 * The KRI monitoring dashboard (migration Phase 4.1:
 * risk/kri/dashboard.blade.php).
 *
 * Figures come from App\Services\Kri\KriService, pinned by
 * tests/Feature/Characterisation/KriDashboardFiguresTest. The two Chart.js
 * canvases are the house SVG/CSS components, as in Phase 3.
 */
export default function Dashboard({
    kpis = {},
    statusDistribution = [],
    breachTrend = [],
    breaching = [],
    trafficLights = [],
    canCreate = false,
}) {
    const slices = statusDistribution.map((slice) => ({
        name: slice.label,
        value: slice.value,
        color: SLICE_COLORS[slice.label] ?? '#6B7280',
    }));

    return (
        <AuthenticatedLayout title="KRI Dashboard">
            <Head title="KRI Dashboard" />

            <PageHeader
                title="KRI Monitoring Dashboard"
                subtitle="Key risk indicator status, thresholds and breach monitoring"
                breadcrumbs={[{ label: 'KRI Monitoring', href: route('risk.kri.index') }, { label: 'Dashboard' }]}
                actions={
                    canCreate && (
                        <Link href={route('risk.kri.create')} className="btn-primary inline-flex items-center gap-2 text-sm">
                            <span className="material-symbols-outlined text-lg">add</span> New KRI
                        </Link>
                    )
                }
            />

            <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
                <KpiCard title="Total KRIs" value={kpis.total ?? 0} icon="speed" color="primary" />
                <KpiCard title="Green (Normal)" value={kpis.green ?? 0} icon="check_circle" color="success" />
                <KpiCard title="Amber (Warning)" value={kpis.amber ?? 0} icon="warning" color="warning" />
                <KpiCard title="Red (Breach)" value={kpis.red ?? 0} icon="error" color="danger" />
                <KpiCard title="Active Breaches" value={kpis.activeBreaches ?? 0} icon="notifications_active" color="danger" subtitle="Requires action" />
                <KpiCard title="Avg. Health Score" value={`${kpis.avgHealthScore ?? 0}%`} icon="monitor_heart" color="info" />
            </div>

            <div className="bg-white rounded-xl border border-gray-200 p-5 mb-6">
                <div className="flex flex-wrap items-center justify-between gap-3 mb-4">
                    <h3 className="text-sm font-semibold text-[#1A365D]">KRI Traffic Light Overview</h3>
                    <div className="flex items-center gap-4 text-xs text-gray-500">
                        <span className="flex items-center gap-1"><span className="w-3 h-3 rounded-full bg-green-500 inline-block" /> Within Tolerance</span>
                        <span className="flex items-center gap-1"><span className="w-3 h-3 rounded-full bg-yellow-500 inline-block" /> Warning</span>
                        <span className="flex items-center gap-1"><span className="w-3 h-3 rounded-full bg-red-500 inline-block" /> Breach</span>
                    </div>
                </div>

                {trafficLights.length === 0 ? (
                    <div className="text-center py-8 text-gray-400">
                        <span className="material-symbols-outlined text-3xl mb-2 block">speed</span>
                        No KRIs configured.{' '}
                        {canCreate && (
                            <Link href={route('risk.kri.create')} className="text-[#1A365D] hover:underline">Create your first KRI</Link>
                        )}
                    </div>
                ) : (
                    <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6 gap-3">
                        {trafficLights.map((kri) => (
                            <Link key={kri.id} href={kri.url} className={`block p-3 rounded-lg border transition-all hover:shadow-md ${tone(kri.status).tile}`}>
                                <div className="flex items-center justify-between mb-2">
                                    <span className={`w-3 h-3 rounded-full ${tone(kri.status).dot}`} />
                                    <TrendIcon trend={kri.trend} />
                                </div>
                                <p className="text-xs font-semibold text-gray-800 truncate" title={kri.name}>{kri.name}</p>
                                <p className={`text-lg font-bold mt-1 ${tone(kri.status).text}`}>
                                    {kri.value ?? '—'}{kri.unit ?? ''}
                                </p>
                                <p className="text-[10px] text-gray-500 mt-1">{kri.code}</p>
                            </Link>
                        ))}
                    </div>
                )}
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <div className="bg-white rounded-xl border border-gray-200 p-5">
                    <h3 className="text-sm font-semibold text-[#1A365D] mb-4">Status Distribution</h3>
                    <DonutChart data={slices} />
                </div>

                <div className="bg-white rounded-xl border border-gray-200 p-5">
                    <h3 className="text-sm font-semibold text-[#1A365D] mb-1">Breaches Opened</h3>
                    {/* One row per crossing, from the breach register — not one
                        per reading that happened to be in breach. */}
                    <p className="text-xs text-gray-400 mb-4">Trailing twelve months, from the breach register</p>
                    <TrendChart
                        data={breachTrend}
                        series={[
                            { key: 'red', label: 'Red', color: '#C53030' },
                            { key: 'amber', label: 'Amber', color: '#D4AF37' },
                        ]}
                    />
                </div>
            </div>

            <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <div className="px-5 py-4 border-b border-gray-100">
                    <h3 className="text-sm font-semibold text-[#1A365D]">Indicators Currently Breaching</h3>
                </div>
                <div className="overflow-x-auto">
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th>KRI</th>
                                <th>Linked Risk</th>
                                <th>Current Value</th>
                                <th>Red Threshold</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {breaching.length === 0 && (
                                <tr><td colSpan={5} className="text-center py-8 text-gray-400">No indicators are breaching.</td></tr>
                            )}
                            {breaching.map((kri) => (
                                <tr key={kri.id}>
                                    <td>
                                        <Link href={kri.url} className="font-medium text-[#1A365D] hover:underline">{kri.name}</Link>
                                        <div className="text-[10px] text-gray-400">{kri.code}</div>
                                    </td>
                                    <td className="text-xs">{kri.riskCode ?? '-'}</td>
                                    <td className={`text-sm font-semibold ${tone(kri.status).text}`}>{kri.currentValue ?? '—'}</td>
                                    <td className="text-xs text-gray-500">{kri.thresholdValue ?? 'Not set'}</td>
                                    <td>
                                        <span className="flex items-center gap-1.5 text-xs">
                                            <span className={`w-2.5 h-2.5 rounded-full ${tone(kri.status).dot}`} />
                                            {kri.status}
                                        </span>
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
