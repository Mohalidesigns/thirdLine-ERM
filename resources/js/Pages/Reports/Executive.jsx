import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import KpiCard from '@thirdline/ui/Components/KpiCard';
import StatusBadge from '@thirdline/ui/Components/StatusBadge';
import RatingBadge from '@thirdline/ui/Components/RatingBadge';
import SeriesChart from '@/Components/Quantification/SeriesChart';
import ReportShell, { LibraryLink } from '@thirdline/ui/Components/ReportShell';
import { naira, number, percent } from '@/Components/Quantification/figures';
import tryRoute from '@thirdline/ui/lib/tryRoute';

const PERIODS = [
    { value: 'month', label: 'This month' },
    { value: 'quarter', label: 'This quarter' },
    { value: 'year', label: 'This year' },
];

/**
 * Executive risk summary (migration Phase 5.4).
 *
 * Figures from ExecutiveReportService, pinned by
 * Characterisation/ExecutiveReportCharacterisationTest.
 *
 * The Trend column the Blade table used to carry is still gone: it read
 * `$risk->trend`, which does not exist on `risks` and is derived nowhere, so
 * every row drew a grey flat arrow — a claim of "no change" that nothing
 * computed.
 */
export default function Executive({
    period,
    totalRisks,
    criticalRisks,
    financialExposure,
    treatmentCompletion,
    kriBreaches,
    appetiteStatus,
    kriGreen,
    kriAmber,
    kriRed,
    topRisks,
    categoryChartData,
    ratingChartData,
    trendChartData,
    exposureChartData,
    kriStatusChartData,
}) {
    const setPeriod = (value) =>
        router.get(route('risk.reports.executive'), { period: value }, { preserveScroll: true, preserveState: true });

    return (
        <AuthenticatedLayout title="Executive Summary">
            <Head title="Executive Summary" />

            <ReportShell
                title="Executive Risk Summary"
                subtitle={`High-level risk overview for executive management · ${new Date().toLocaleDateString('en-GB', { month: 'long', year: 'numeric' })}`}
                routeName="risk.reports.executive"
                extraQuery={{ period }}
                actions={
                    <>
                        <select
                            value={period}
                            onChange={(e) => setPeriod(e.target.value)}
                            className="border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-lg text-sm"
                            aria-label="Reporting period"
                        >
                            {PERIODS.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                        <LibraryLink />
                    </>
                }
            >
                <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
                    <KpiCard title="Total Active Risks" value={number(totalRisks)} icon="shield" color="primary" />
                    <KpiCard title="Critical Risks" value={number(criticalRisks)} icon="error" color="danger" />
                    <KpiCard title="Financial Exposure" value={naira(financialExposure)} icon="payments" color="warning" />
                    <KpiCard
                        title="Treatment Completion"
                        value={percent(treatmentCompletion)}
                        unavailable={treatmentCompletion === null}
                        unavailableLabel="No plans on record"
                        icon="task_alt"
                        color="success"
                    />
                    <KpiCard title="KRI Breaches" value={number(kriBreaches)} icon="notifications_active" color="danger" />
                    {/*
                        Reads from the declared appetite statements via RiskAppetiteService.
                        A literal fallback here used to assert "Within" — a green tile
                        claiming the organisation sat inside a Board-approved appetite —
                        for any tenant the controller had produced no status for.
                    */}
                    <KpiCard
                        title="Risk Appetite Status"
                        value={appetiteStatus}
                        unavailable={appetiteStatus === null}
                        unavailableLabel="No appetite declared"
                        icon="speed"
                        color={appetiteStatus === 'Within' ? 'success' : 'danger'}
                    />
                </div>

                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                    <section className="bg-white rounded-xl border border-gray-200 p-5">
                        <h3 className="text-sm font-semibold text-[#1A365D] mb-4">Risk by Category</h3>
                        <SeriesChart
                            labels={categoryChartData.labels}
                            values={categoryChartData.values}
                            height={220}
                            ariaLabel="Risks by category"
                            valueLabel={(v) => String(Math.round(v))}
                        />
                    </section>
                    <section className="bg-white rounded-xl border border-gray-200 p-5">
                        <h3 className="text-sm font-semibold text-[#1A365D] mb-4">Risk Rating Distribution</h3>
                        <SeriesChart
                            labels={ratingChartData.labels}
                            values={ratingChartData.values}
                            height={220}
                            ariaLabel="Risk rating distribution"
                            valueLabel={(v) => String(Math.round(v))}
                        />
                    </section>
                    <section className="bg-white rounded-xl border border-gray-200 p-5">
                        <h3 className="text-sm font-semibold text-[#1A365D] mb-1">Risk Trend (12 Months)</h3>
                        <p className="text-xs text-gray-500 mb-3">
                            Risks on the register at each month end, by the date each was identified.
                        </p>
                        <SeriesChart
                            labels={trendChartData.labels}
                            values={trendChartData.values}
                            variant="line"
                            height={220}
                            ariaLabel="Risks on the register over twelve months"
                            valueLabel={(v) => String(Math.round(v))}
                        />
                    </section>
                </div>

                <section className="bg-white rounded-xl border border-gray-200 overflow-hidden mb-6">
                    <header className="px-5 py-4 border-b border-gray-100">
                        <h3 className="text-sm font-semibold text-[#1A365D]">Top 10 Risks</h3>
                    </header>
                    <div className="overflow-x-auto">
                        <table className="data-table">
                            <thead>
                                <tr>
                                    <th>Rank</th>
                                    <th>Risk code</th>
                                    <th>Title</th>
                                    <th>Category</th>
                                    <th>Residual rating</th>
                                    <th>Owner</th>
                                    <th>Treatment status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {topRisks.length === 0 ? (
                                    <tr>
                                        <td colSpan={7} className="text-center py-8 text-gray-400">No risk data available</td>
                                    </tr>
                                ) : (
                                    topRisks.map((risk, index) => {
                                        const url = tryRoute('risk.register.show', risk.id);

                                        return (
                                            <tr key={risk.id}>
                                                <td className="text-xs font-bold text-gray-400">{index + 1}</td>
                                                <td className="font-medium text-[#1A365D]">
                                                    {url ? (
                                                        <Link href={url} className="hover:underline">
                                                            {risk.risk_code}
                                                        </Link>
                                                    ) : (
                                                        risk.risk_code
                                                    )}
                                                </td>
                                                <td className="text-xs">{risk.title}</td>
                                                <td className="text-xs">{risk.category ?? '—'}</td>
                                                <td>
                                                    <RatingBadge rating={(risk.residual_rating ?? '').toLowerCase()} />
                                                </td>
                                                <td className="text-xs">{risk.owner ?? '—'}</td>
                                                <td>
                                                    <StatusBadge status={risk.derived_treatment_status ?? 'Not started'} type="treatment" />
                                                </td>
                                            </tr>
                                        );
                                    })
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>

                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <section className="bg-white rounded-xl border border-gray-200 p-5">
                        <h3 className="text-sm font-semibold text-[#1A365D] mb-4">Financial Exposure by Category</h3>
                        {exposureChartData.labels.length === 0 ? (
                            <p className="text-sm text-gray-400 italic py-10 text-center">No loss events recorded.</p>
                        ) : (
                            <SeriesChart
                                labels={exposureChartData.labels}
                                values={exposureChartData.values}
                                height={200}
                                ariaLabel="Financial exposure by category"
                                valueLabel={(v) => naira(v) ?? ''}
                            />
                        )}
                    </section>

                    <section className="bg-white rounded-xl border border-gray-200 p-5">
                        <h3 className="text-sm font-semibold text-[#1A365D] mb-4">Key Risk Indicators Status</h3>
                        <div className="grid grid-cols-3 gap-4 mb-4">
                            <div className="text-center p-3 bg-green-50 rounded-lg">
                                <p className="text-2xl font-bold text-green-700">{kriGreen}</p>
                                <p className="text-xs text-gray-500">Green</p>
                            </div>
                            <div className="text-center p-3 bg-yellow-50 rounded-lg">
                                <p className="text-2xl font-bold text-yellow-700">{kriAmber}</p>
                                <p className="text-xs text-gray-500">Amber</p>
                            </div>
                            <div className="text-center p-3 bg-red-50 rounded-lg">
                                <p className="text-2xl font-bold text-red-700">{kriRed}</p>
                                <p className="text-xs text-gray-500">Red</p>
                            </div>
                        </div>
                        <SeriesChart
                            labels={kriStatusChartData.labels}
                            values={kriStatusChartData.values}
                            height={180}
                            ariaLabel="KRI status"
                            valueLabel={(v) => String(Math.round(v))}
                        />
                    </section>
                </div>
            </ReportShell>
        </AuthenticatedLayout>
    );
}
