import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import KpiCard from '@thirdline/ui/Components/KpiCard';
import WidgetGrid from '@thirdline/ui/Components/WidgetGrid';
import RatingBadge from '@thirdline/ui/Components/RatingBadge';
import EmptyState from '@thirdline/ui/Components/EmptyState';
import SeriesChart from '@/Components/Quantification/SeriesChart';
import GroupedBarChart from '@thirdline/ui/Components/GroupedBarChart';
import ExportMenu from '@/Components/Reporting/ExportMenu';
import { NOT_ASSESSED, naira, number, percent } from '@/Components/Quantification/figures';
import tryRoute from '@thirdline/ui/lib/tryRoute';

const shortDate = (value) =>
    value ? new Date(value).toLocaleDateString('en-GB', { day: '2-digit', month: 'short' }) : '—';

/** Grid rows/cols are 1-5 with likelihood inverted, as the service builds it. */
function Heatmap({ data }) {
    const cell = (count) =>
        count === 0
            ? 'bg-gray-50 text-gray-300'
            : count < 3
              ? 'bg-yellow-100 text-yellow-800'
              : count < 6
                ? 'bg-orange-200 text-orange-900'
                : 'bg-red-300 text-red-900';

    return (
        <div className="flex gap-2">
            <div className="flex flex-col justify-between py-1 text-[10px] text-gray-400">
                {[5, 4, 3, 2, 1].map((l) => (
                    <span key={l}>{l}</span>
                ))}
            </div>
            <div className="flex-1">
                <div className="grid grid-cols-5 gap-1">
                    {data.map((row, r) =>
                        row.map((count, c) => (
                            <div
                                key={`${r}-${c}`}
                                className={`aspect-square rounded flex items-center justify-center text-xs font-semibold ${cell(count)}`}
                                title={`Likelihood ${5 - r}, impact ${c + 1}: ${count} risk${count === 1 ? '' : 's'}`}
                            >
                                {count || ''}
                            </div>
                        )),
                    )}
                </div>
                <div className="grid grid-cols-5 gap-1 mt-1 text-[10px] text-gray-400 text-center">
                    {[1, 2, 3, 4, 5].map((i) => (
                        <span key={i}>{i}</span>
                    ))}
                </div>
                <p className="text-[10px] text-gray-400 text-center mt-1">Impact →</p>
            </div>
        </div>
    );
}

/**
 * The Command Centre (migration Phase 5, criterion 7).
 *
 * CRITERION 7, AND HOW IT IS MET. The criterion asks for this page to be
 * "composed of `Widget`s from the seeded `erm-hq` dashboard", with the seven
 * inline Chart.js constructions deleted rather than ported.
 *
 * The Chart.js is gone. The widget composition is PARTIAL, deliberately:
 * `erm-hq` carries seven widgets and this page has eight sections, and the
 * widget catalogue has no KRI, appetite, activity-feed or regulatory-review
 * type at all — so composing purely from widgets would delete four sections
 * from the product's landing page. The widgets render what they cover; the rest
 * keeps its own figures and draws with the house SVG components. The evidence
 * is in docs/migration/phase-5-notes/command-centre.md.
 */
export default function Dashboard({
    widgetLayout,
    widgetPayloads,
    dashboardName,
    totalActiveRisks,
    criticalRisks,
    highRisks,
    kriBreaches,
    openIssues,
    ytdNetLoss,
    overdueTreatments,
    carPercentage,
    heatmapData,
    ratingDistribution,
    riskTrendData,
    lossTrendData,
    kriStatusCounts,
    breachedKris,
    controlEffectiveness,
    appetiteData,
    treatmentStatusDist,
    avgTreatmentProgress,
    topRisks,
    activityFeed,
    cbnReportableCount,
    upcomingReviews,
    overdueReviews,
    regulatoryIssues,
    asOfPeriod,
}) {
    const hasWidgets = widgetLayout.length > 0 && Object.keys(widgetPayloads).length > 0;

    return (
        <AuthenticatedLayout title="Command Centre">
            <Head title="Command Centre" />

            <div className="flex items-start justify-between mb-6">
                <div>
                    <h1 className="text-xl font-bold text-[#1A365D]">Command Centre</h1>
                    <p className="text-sm text-gray-500 mt-1">The organisation&apos;s risk position, at a glance</p>
                </div>
                <ExportMenu />
            </div>

            {/*
                HISTORIC SCORES, AND WHICH PANELS ARE NOT. When a closed period is
                selected, some panels read as at that period and the rest are
                current state — and the difference has to be on screen, because a
                reader comparing an historic risk count against a live KRI count
                is comparing two different dates without being told. Carried over
                from the Blade view unchanged; the port dropped it once and
                PeriodSelectorTest caught that.
            */}
            {asOfPeriod && (
                <div className="mb-4 p-4 bg-blue-50 border border-blue-200 rounded-xl flex items-start gap-3">
                    <span className="material-symbols-outlined text-blue-600">history</span>
                    <div className="text-sm text-blue-800">
                        <p className="font-semibold">
                            Risk scores shown as at {asOfPeriod.name}
                            {asOfPeriod.end_date && (
                                <>
                                    {' '}
                                    ({new Date(asOfPeriod.end_date).toLocaleDateString('en-GB', {
                                        day: '2-digit', month: 'short', year: 'numeric',
                                    })})
                                </>
                            )}
                            .
                        </p>
                        <p className="text-xs text-blue-700 mt-0.5">
                            Active risk count, heatmap and rating mix are historic. Issues, treatments, loss trends
                            and KRI panels below remain current state.{' '}
                            <a
                                href={route('risk.periods.select', { direction: 'current', redirect: '/risk/dashboard' })}
                                className="underline font-medium"
                            >
                                Return to the current period
                            </a>
                            .
                        </p>
                    </div>
                </div>
            )}

            {/*
                Section 1 — the executive strip. Four of these are erm-hq widget
                tiles below; these two are the figures no widget type covers.
            */}
            <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <KpiCard title="Active risks" value={number(totalActiveRisks)} icon="shield" color="primary" subtitle={`${criticalRisks} critical, ${highRisks} high`} />
                <KpiCard title="YTD net loss" value={naira(ytdNetLoss)} icon="payments" color="warning" />
                <KpiCard title="KRI breaches" value={number(kriBreaches)} icon="notifications_active" color={kriBreaches > 0 ? 'danger' : 'success'} />
                {/*
                    Capital adequacy through IcaapService. This tile was
                    `number_format((float) ($icaap->car_actual ?? 0), 1)` — and
                    car_actual is nullable, so an assessment on file without a
                    typed ratio put "0.0%" on the landing page. The fourth place
                    this exact defect was found.
                */}
                <KpiCard
                    title="Capital adequacy"
                    value={carPercentage === null ? null : `${carPercentage}%`}
                    unavailable={carPercentage === null}
                    unavailableLabel={NOT_ASSESSED}
                    icon="account_balance"
                    color="primary"
                />
            </div>

            {hasWidgets && (
                <section className="mb-6">
                    <div className="flex items-center gap-2 mb-3">
                        <h2 className="text-sm font-semibold text-[#1A365D]">{dashboardName ?? 'Enterprise Risk Management'}</h2>
                        <span className="text-xs text-gray-400">from the shared dashboard</span>
                    </div>
                    <WidgetGrid layout={widgetLayout} payloads={widgetPayloads} keyPrefix="command-centre-" />
                </section>
            )}

            {/* Section 2 — the heat map and rating mix, kept for organisations
                whose seeded dashboard has not been published. */}
            {!hasWidgets && (
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                    <section className="bg-white rounded-xl border border-gray-200 p-5">
                        <h3 className="text-sm font-semibold text-[#1A365D] mb-4">Residual risk heat map</h3>
                        <Heatmap data={heatmapData} />
                    </section>
                    <section className="bg-white rounded-xl border border-gray-200 p-5">
                        <h3 className="text-sm font-semibold text-[#1A365D] mb-4">Rating distribution</h3>
                        <SeriesChart
                            labels={Object.keys(ratingDistribution)}
                            values={Object.values(ratingDistribution)}
                            height={200}
                            ariaLabel="Risks by residual rating"
                            valueLabel={(v) => String(Math.round(v))}
                        />
                    </section>
                </div>
            )}

            {/* Section 3 — trends. */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <section className="bg-white rounded-xl border border-gray-200 p-5">
                    <h3 className="text-sm font-semibold text-[#1A365D] mb-4">Assessed ratings, 12 months</h3>
                    <GroupedBarChart
                        data={riskTrendData.map((row) => ({ month: row.label, ...row }))}
                        series={[
                            { key: 'critical', label: 'Critical', color: '#C53030' },
                            { key: 'high', label: 'High', color: '#DD6B20' },
                            { key: 'medium', label: 'Medium', color: '#D69E2E' },
                            { key: 'low', label: 'Low', color: '#2D7D46' },
                        ]}
                    />
                </section>

                <section className="bg-white rounded-xl border border-gray-200 p-5">
                    <h3 className="text-sm font-semibold text-[#1A365D] mb-4">Net loss, 12 months</h3>
                    <SeriesChart
                        labels={lossTrendData.map((row) => row.label)}
                        values={lossTrendData.map((row) => row.net)}
                        variant="line"
                        height={220}
                        ariaLabel="Net loss by month"
                        valueLabel={(v) => naira(v) ?? ''}
                    />
                </section>
            </div>

            {/* Section 4 — KRI and controls. No widget type covers either. */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <section className="bg-white rounded-xl border border-gray-200 p-5">
                    <h3 className="text-sm font-semibold text-[#1A365D] mb-4">Key risk indicators</h3>
                    <div className="grid grid-cols-3 gap-3 mb-4">
                        {[
                            ['Green', kriStatusCounts.green, 'bg-green-50 text-green-700'],
                            ['Amber', kriStatusCounts.amber, 'bg-yellow-50 text-yellow-700'],
                            ['Red', kriStatusCounts.red, 'bg-red-50 text-red-700'],
                        ].map(([label, count, classes]) => (
                            <div key={label} className={`text-center p-3 rounded-lg ${classes}`}>
                                <p className="text-2xl font-bold">{count}</p>
                                <p className="text-xs text-gray-500">{label}</p>
                            </div>
                        ))}
                    </div>

                    {breachedKris.length > 0 && (
                        <ul className="divide-y divide-gray-100 text-sm">
                            {breachedKris.map((kri) => (
                                <li key={kri.id} className="py-2 flex items-center gap-3">
                                    <span className="w-2 h-2 rounded-full bg-red-500 shrink-0" />
                                    <span className="flex-1 truncate">{kri.name}</span>
                                    <span className="text-xs font-medium">{kri.current_value ?? '—'}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className="bg-white rounded-xl border border-gray-200 p-5">
                    <h3 className="text-sm font-semibold text-[#1A365D] mb-4">Control effectiveness</h3>
                    <SeriesChart
                        labels={Object.keys(controlEffectiveness)}
                        values={Object.values(controlEffectiveness)}
                        height={200}
                        ariaLabel="Controls by effectiveness band"
                        valueLabel={(v) => String(Math.round(v))}
                    />
                </section>
            </div>

            {/* Section 5 — appetite and treatments. */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <section className="bg-white rounded-xl border border-gray-200 p-5">
                    <h3 className="text-sm font-semibold text-[#1A365D] mb-4">Risk appetite</h3>
                    {(appetiteData?.categories ?? []).length === 0 ? (
                        <p className="text-sm text-gray-400 italic py-8 text-center">
                            No appetite statement has been declared.
                        </p>
                    ) : (
                        <ul className="space-y-3">
                            {appetiteData.categories.map((row, index) => (
                                <li key={index} className="flex items-center gap-3">
                                    <span className="w-40 text-xs text-gray-600 shrink-0 truncate">{row.name ?? row.label}</span>
                                    <div className="flex-1 bg-gray-100 rounded h-3 overflow-hidden">
                                        <div
                                            className={`h-3 ${(row.utilization_pct ?? 0) > 100 ? 'bg-red-500' : 'bg-[#1A365D]'}`}
                                            style={{ width: `${Math.min(100, row.utilization_pct ?? 0)}%` }}
                                        />
                                    </div>
                                    <span className="w-14 text-right text-xs">{percent(row.utilization_pct) ?? '—'}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className="bg-white rounded-xl border border-gray-200 p-5">
                    <h3 className="text-sm font-semibold text-[#1A365D] mb-1">Treatment progress</h3>
                    <p className="text-xs text-gray-500 mb-4">
                        Average progress across open and completed plans: {Math.round(avgTreatmentProgress)}%
                    </p>
                    <SeriesChart
                        labels={Object.keys(treatmentStatusDist)}
                        values={Object.values(treatmentStatusDist)}
                        height={180}
                        ariaLabel="Treatment plans by status"
                        valueLabel={(v) => String(Math.round(v))}
                    />
                </section>
            </div>

            {/* Sections 6 to 8 — top risks, activity, regulatory. */}
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <section className="lg:col-span-2 bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <header className="px-5 py-4 border-b border-gray-100">
                        <h3 className="text-sm font-semibold text-[#1A365D]">Top risks by residual score</h3>
                    </header>
                    {topRisks.length === 0 ? (
                        <EmptyState
                            icon={<span className="material-symbols-outlined text-3xl text-gray-400">shield</span>}
                            title="No scored risks"
                            description="Assess risks in the register to see them ranked here."
                        />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="data-table">
                                <thead>
                                    <tr>
                                        <th>Code</th><th>Title</th><th>Owner</th><th>Rating</th><th className="text-right">Score</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {topRisks.map((risk) => {
                                        const url = tryRoute('risk.register.show', risk.id);

                                        return (
                                            <tr key={risk.id}>
                                                <td className="text-xs font-medium text-[#1A365D]">
                                                    {url ? <Link href={url} className="hover:underline">{risk.risk_code}</Link> : risk.risk_code}
                                                </td>
                                                <td className="text-xs">{risk.title}</td>
                                                <td className="text-xs">{risk.risk_owner?.name ?? risk.owner?.name ?? '—'}</td>
                                                <td><RatingBadge rating={(risk.residual_rating ?? '').toLowerCase()} /></td>
                                                <td className="text-right text-xs font-semibold">{risk.residual_score ?? '—'}</td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                <div className="space-y-6">
                    <section className="bg-white rounded-xl border border-gray-200 p-5">
                        <h3 className="text-sm font-semibold text-[#1A365D] mb-4">Regulatory</h3>
                        <dl className="space-y-3 text-sm">
                            {[
                                ['CBN reportable, not notified', cbnReportableCount],
                                ['Regulatory issues open', regulatoryIssues],
                                ['Reviews due in 30 days', upcomingReviews],
                                ['Reviews overdue', overdueReviews],
                            ].map(([label, value]) => (
                                <div key={label} className="flex justify-between items-center">
                                    <dt className="text-xs text-gray-600">{label}</dt>
                                    <dd className={`font-semibold ${value > 0 ? 'text-red-600' : 'text-[#1A365D]'}`}>{value}</dd>
                                </div>
                            ))}
                        </dl>
                    </section>

                    <section className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                        <header className="px-5 py-4 border-b border-gray-100">
                            <h3 className="text-sm font-semibold text-[#1A365D]">Recent activity</h3>
                        </header>
                        {activityFeed.length === 0 ? (
                            <p className="px-5 py-8 text-sm text-gray-500 text-center">Nothing recorded yet.</p>
                        ) : (
                            <ul className="divide-y divide-gray-100">
                                {activityFeed.map((item, index) => (
                                    <li key={index} className="px-5 py-3 flex items-start gap-3">
                                        <span className={`w-7 h-7 rounded-full flex items-center justify-center shrink-0 ${item.icon_bg}`}>
                                            <span className={`material-symbols-outlined text-sm ${item.icon_color}`}>{item.icon}</span>
                                        </span>
                                        <div className="flex-1 min-w-0">
                                            <a href={item.url} className="text-sm text-gray-800 hover:underline block truncate">
                                                {item.title}
                                            </a>
                                            <p className="text-xs text-gray-500">
                                                {item.code}
                                                {item.amount !== null && item.amount !== undefined && <> &middot; {naira(item.amount)}</>}
                                            </p>
                                        </div>
                                        <span className="text-xs text-gray-400 shrink-0">{shortDate(item.date)}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
