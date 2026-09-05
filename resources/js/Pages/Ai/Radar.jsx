import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import KpiCard from '@/Components/KpiCard';
import EmptyState from '@/Components/EmptyState';
import SeriesChart from '@/Components/Quantification/SeriesChart';
import { number } from '@/Components/Quantification/figures';
import tryRoute from '@/lib/tryRoute';

const IMPACT_CLASSES = {
    Critical: 'bg-red-100 text-red-700',
    High: 'bg-orange-100 text-orange-700',
    Medium: 'bg-yellow-100 text-yellow-700',
    Low: 'bg-green-100 text-green-700',
};

const shortDate = (value) =>
    value ? new Date(value).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : null;

/**
 * Emerging Risk Radar (migration Phase 5.6).
 *
 * Plotted from the tenant's own `emerging_risks` register. The screen was a
 * hardcoded array of eight emerging risks with invented confidence
 * percentages, identical for every tenant, and a `sources_scanned: 47` that
 * scanned nothing; docs/ai-number-provenance.md records the whole of it.
 *
 * The scatter is inline SVG rather than a chart library, for the house reason
 * and one of its own: proximity and velocity are 1-5 ORDINAL scores, so the
 * axes are five fixed positions, not a continuous scale to be auto-fitted.
 */
function RadarScatter({ points }) {
    if (points.length === 0) return null;

    const width = 560;
    const height = 360;
    const pad = { top: 20, right: 24, bottom: 44, left: 56 };
    const plotW = width - pad.left - pad.right;
    const plotH = height - pad.top - pad.bottom;

    // 1-5 on both axes, so the grid is fixed rather than fitted.
    const xFor = (score) => pad.left + ((score - 1) / 4) * plotW;
    const yFor = (score) => pad.top + plotH - ((score - 1) / 4) * plotH;

    const radius = (impact) => ({ Critical: 11, High: 9, Medium: 7, Low: 5 })[impact] ?? 6;
    const fill = (impact) =>
        ({ Critical: 'rgba(197,48,48,0.75)', High: 'rgba(234,88,12,0.7)', Medium: 'rgba(217,119,6,0.65)', Low: 'rgba(45,125,70,0.6)' })[impact]
        ?? 'rgba(26,54,93,0.6)';

    return (
        <svg viewBox={`0 0 ${width} ${height}`} className="w-full" role="img" aria-label="Emerging risks by proximity and velocity">
            {[1, 2, 3, 4, 5].map((tick) => (
                <g key={`grid-${tick}`}>
                    <line x1={xFor(tick)} x2={xFor(tick)} y1={pad.top} y2={pad.top + plotH} stroke="#E5E7EB" />
                    <line x1={pad.left} x2={pad.left + plotW} y1={yFor(tick)} y2={yFor(tick)} stroke="#E5E7EB" />
                    <text x={xFor(tick)} y={height - 24} textAnchor="middle" fontSize="10" fill="#9CA3AF">{tick}</text>
                    <text x={pad.left - 10} y={yFor(tick) + 3.5} textAnchor="end" fontSize="10" fill="#9CA3AF">{tick}</text>
                </g>
            ))}

            <text x={pad.left + plotW / 2} y={height - 6} textAnchor="middle" fontSize="11" fill="#6B7280">
                Proximity (how soon)
            </text>
            <text x={14} y={pad.top + plotH / 2} textAnchor="middle" fontSize="11" fill="#6B7280"
                transform={`rotate(-90 14 ${pad.top + plotH / 2})`}>
                Velocity (how fast)
            </text>

            {points.map((point, index) => (
                <circle
                    key={index}
                    cx={xFor(point.x)}
                    cy={yFor(point.y)}
                    r={radius(point.impact)}
                    fill={fill(point.impact)}
                    stroke="#fff"
                    strokeWidth="1.5"
                >
                    <title>{`${point.label} — ${point.impact ?? 'Unrated'} impact`}</title>
                </circle>
            ))}
        </svg>
    );
}

export default function Radar({
    register,
    points,
    categoryChart,
    byHorizon,
    totalOnRadar,
    fastMoving,
    imminent,
    highImpact,
    neverReviewed,
    staleReviews,
}) {
    const registerUrl = tryRoute('risk.emerging.index');

    return (
        <AuthenticatedLayout title="Emerging Risk Radar">
            <Head title="Emerging Risk Radar" />

            <div className="flex items-start justify-between mb-6">
                <div>
                    <h1 className="text-xl font-bold text-[#1A365D]">Emerging Risk Radar</h1>
                    <p className="text-sm text-gray-500 mt-1">
                        The horizon your team maintains, plotted by how soon and how fast.
                    </p>
                </div>
                {registerUrl && (
                    <Link href={registerUrl} className="btn-secondary text-sm inline-flex items-center gap-2">
                        <span className="material-symbols-outlined text-lg">list</span> Manage register
                    </Link>
                )}
            </div>

            <div className="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-6 flex items-start gap-3">
                <span className="material-symbols-outlined text-blue-600 text-lg">info</span>
                <p className="text-xs text-blue-900 leading-relaxed">
                    Every point is an entry from your own emerging risk register, scored by your team. Nothing here is
                    scanned from an external source and nothing is inferred.
                </p>
            </div>

            <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
                <KpiCard title="On radar" value={number(totalOnRadar)} icon="radar" color="primary" />
                <KpiCard title="Imminent" value={number(imminent)} icon="schedule" color="danger" subtitle="Proximity 4+" />
                <KpiCard title="Fast moving" value={number(fastMoving)} icon="speed" color="warning" subtitle="Velocity 4+" />
                <KpiCard title="High impact" value={number(highImpact)} icon="priority_high" color="danger" />
                <KpiCard title="Never reviewed" value={number(neverReviewed)} icon="visibility_off" color="warning" />
                <KpiCard title="Stale reviews" value={number(staleReviews)} icon="update" color="warning" subtitle="Over 90 days" />
            </div>

            {totalOnRadar === 0 ? (
                <EmptyState
                    icon={<span className="material-symbols-outlined text-3xl text-gray-400">radar</span>}
                    title="Nothing on the radar"
                    description="Add entries to the emerging risk register and score them for proximity and velocity to see them plotted here."
                    actionLabel={registerUrl ? 'Open the register' : undefined}
                    actionHref={registerUrl ?? undefined}
                />
            ) : (
                <>
                    <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                        <section className="lg:col-span-2 bg-white rounded-xl border border-gray-200 p-5">
                            <h3 className="text-sm font-semibold text-[#1A365D] mb-1">Radar</h3>
                            <p className="text-xs text-gray-500 mb-4">Point size is potential impact.</p>
                            <RadarScatter points={points} />
                        </section>

                        <div className="space-y-6">
                            <section className="bg-white rounded-xl border border-gray-200 p-5">
                                <h3 className="text-sm font-semibold text-[#1A365D] mb-4">By category</h3>
                                {categoryChart.labels.length === 0 ? (
                                    <p className="text-sm text-gray-400 italic py-6 text-center">Nothing categorised.</p>
                                ) : (
                                    <SeriesChart
                                        labels={categoryChart.labels}
                                        values={categoryChart.values}
                                        height={180}
                                        ariaLabel="Emerging risks by category"
                                        valueLabel={(v) => String(Math.round(v))}
                                    />
                                )}
                            </section>

                            <section className="bg-white rounded-xl border border-gray-200 p-5">
                                <h3 className="text-sm font-semibold text-[#1A365D] mb-4">By horizon</h3>
                                <dl className="space-y-2">
                                    {Object.entries(byHorizon).map(([horizon, count]) => (
                                        <div key={horizon} className="flex items-center justify-between text-sm">
                                            <dt className="text-gray-600">{horizon}</dt>
                                            <dd className="font-semibold text-[#1A365D]">{count}</dd>
                                        </div>
                                    ))}
                                </dl>
                            </section>
                        </div>
                    </div>

                    <section className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                        <header className="px-5 py-4 border-b border-gray-100">
                            <h3 className="text-sm font-semibold text-[#1A365D]">The register</h3>
                        </header>
                        <div className="overflow-x-auto">
                            <table className="data-table">
                                <thead>
                                    <tr>
                                        <th>Reference</th>
                                        <th>Entry</th>
                                        <th>Category</th>
                                        <th>Horizon</th>
                                        <th>Proximity</th>
                                        <th>Velocity</th>
                                        <th>Impact</th>
                                        <th>Owner</th>
                                        <th>Last reviewed</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {register.map((entry) => (
                                        <tr key={entry.id}>
                                            <td className="text-xs font-medium text-[#1A365D]">{entry.reference}</td>
                                            <td className="text-sm">
                                                {entry.title}
                                                {entry.source && (
                                                    <span className="block text-xs text-gray-500">Source: {entry.source}</span>
                                                )}
                                            </td>
                                            <td className="text-xs">{entry.category ?? '—'}</td>
                                            <td className="text-xs">{entry.horizon ?? '—'}</td>
                                            <td className="text-xs">
                                                {entry.proximity_score}
                                                {entry.proximity_label && (
                                                    <span className="block text-gray-500">{entry.proximity_label}</span>
                                                )}
                                            </td>
                                            <td className="text-xs">
                                                {entry.velocity_score}
                                                {entry.velocity_label && (
                                                    <span className="block text-gray-500">{entry.velocity_label}</span>
                                                )}
                                            </td>
                                            <td>
                                                <span className={`badge text-[10px] ${IMPACT_CLASSES[entry.potential_impact] ?? 'bg-gray-100 text-gray-600'}`}>
                                                    {entry.potential_impact ?? 'Unrated'}
                                                </span>
                                            </td>
                                            <td className="text-xs">{entry.owner ?? '—'}</td>
                                            <td className="text-xs text-gray-500">
                                                {shortDate(entry.last_reviewed_at) ?? (
                                                    <span className="text-yellow-700">Never</span>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                </>
            )}
        </AuthenticatedLayout>
    );
}
