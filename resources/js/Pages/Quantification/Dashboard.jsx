import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import KpiCard from '@thirdline/ui/Components/KpiCard';
import StatusBadge from '@thirdline/ui/Components/StatusBadge';
import Figure from '@/Components/Quantification/Figure';
import SeriesChart from '@/Components/Quantification/SeriesChart';
import { NOT_ASSESSED, NOT_RECORDED, naira, number, percent, trimmedPercent } from '@/Components/Quantification/figures';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/** Naira compacted so six tiles fit without overflowing. */
function compactNaira(amount) {
    if (amount === null || amount === undefined) return null;

    const magnitude = Math.abs(Number(amount));
    if (magnitude >= 1e9) return `₦${(amount / 1e9).toFixed(2)}B`;
    if (magnitude >= 1e6) return `₦${(amount / 1e6).toFixed(2)}M`;
    if (magnitude >= 1e3) return `₦${(amount / 1e3).toFixed(1)}K`;

    return naira(amount);
}

/**
 * The quantification dashboard (migration Phase 5.2).
 *
 * Every figure comes from QuantificationDashboardService and is pinned by
 * Characterisation/QuantificationDashboardTest.
 *
 * The add-on chart is a labelled list rather than a doughnut, for the reason
 * the ICAAP waterfall is: a component the assessment does not carry has to
 * leave a GAP. A doughnut cannot draw an absence — it can only draw a zero
 * slice, which is a claim that the component is nil.
 */
export default function Dashboard({
    hasAssessment,
    activeScenarios,
    simulationsRun,
    var95,
    expectedShortfall,
    minimumCar,
    capitalAdequacyRatio,
    carBasis,
    totalCapital,
    pillar2aCapital,
    pillar2bCapital,
    capitalBuffer,
    totalEconomicCapital,
    capitalByTypeData,
    lossDistData,
    recentSimulations,
}) {
    const carMeets = capitalAdequacyRatio !== null && capitalAdequacyRatio >= minimumCar;

    const carBox = capitalAdequacyRatio === null
        ? 'bg-gray-50 border border-gray-200'
        : carMeets
          ? 'bg-green-50 border border-green-200'
          : 'bg-red-50 border border-red-200';

    const carText = capitalAdequacyRatio === null ? 'text-gray-400' : carMeets ? 'text-green-700' : 'text-red-700';

    const componentScale = Math.max(...capitalByTypeData.values.filter((v) => v !== null).map(Math.abs), 0);

    return (
        <AuthenticatedLayout title="Quantification">
            <Head title="Quantification" />

            <PageHeader
                title="Risk Quantification"
                subtitle="Monte Carlo loss modelling and the ICAAP capital position"
                actions={
                    <>
                        <Link href={route('risk.quantification.simulate')} className="btn-primary text-sm inline-flex items-center gap-2">
                            <span className="material-symbols-outlined text-lg">play_arrow</span> New simulation
                        </Link>
                        <Link href={route('risk.quantification.scenarios')} className="btn-secondary text-sm inline-flex items-center gap-2">
                            <span className="material-symbols-outlined text-lg">category</span> Scenarios
                        </Link>
                    </>
                }
            />

            <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
                {/*
                    WP-08: this is the sum of the Pillar 2A and Pillar 2B columns on
                    the latest ICAAP assessment. It is not a modelled economic capital
                    number and it is not read at any confidence level — the tile used
                    to be subtitled "99.9% confidence", which nothing here computes.
                    It also used to coerce both pillars to zero, so an organisation
                    with no assessment read "₦0, as assessed".
                */}
                <KpiCard
                    title="ICAAP Capital Add-on"
                    value={compactNaira(totalEconomicCapital)}
                    unavailable={totalEconomicCapital === null}
                    unavailableLabel={NOT_RECORDED}
                    icon="account_balance"
                    color="primary"
                    subtitle="Pillar 2A + Pillar 2B, as assessed"
                />
                {/*
                    WP-08: CAR is computed from capital / RWA, is null (not 0) when
                    that is impossible, and is coloured against the RESOLVED CBN
                    minimum for this organisation rather than a hardcoded 10 / 15.
                */}
                <KpiCard
                    title="Capital Adequacy Ratio"
                    value={percent(capitalAdequacyRatio)}
                    unavailable={capitalAdequacyRatio === null}
                    icon="shield"
                    color={carMeets ? 'success' : 'danger'}
                    subtitle={`CBN minimum: ${trimmedPercent(minimumCar)}%`}
                />
                <KpiCard title="Active Scenarios" value={number(activeScenarios)} icon="category" color="info" />
                <KpiCard title="Simulations Run" value={number(simulationsRun)} icon="calculate" color="primary" />
                <KpiCard
                    title="VaR (95%)"
                    value={compactNaira(var95)}
                    unavailable={var95 === null}
                    unavailableLabel="No completed run"
                    icon="trending_up"
                    color="warning"
                />
                <KpiCard
                    title="Expected Shortfall"
                    value={compactNaira(expectedShortfall)}
                    unavailable={expectedShortfall === null}
                    unavailableLabel="No completed run"
                    icon="priority_high"
                    color="danger"
                    subtitle="Mean loss beyond VaR 95"
                />
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <section className="bg-white rounded-xl border border-gray-200 p-5">
                    <div className="flex items-center justify-between mb-1">
                        <h3 className="text-sm font-semibold text-[#1A365D]">ICAAP Capital Add-on by Component</h3>
                        <span className="material-symbols-outlined text-gray-400 text-lg">donut_large</span>
                    </div>
                    <p className="text-xs text-gray-500 mb-4">
                        The Pillar 2A columns and the Pillar 2B stress buffer, named for the columns they read. These
                        were once headed &quot;Credit / Market / Operational / Liquidity / Other&quot; as though they
                        decomposed economic capital by risk type.
                    </p>

                    <div className="space-y-2">
                        {capitalByTypeData.labels.map((label, index) => {
                            const value = capitalByTypeData.values[index];

                            return (
                                <div key={label} className="flex items-center gap-3">
                                    <div className="w-48 text-xs text-gray-600 shrink-0">{label}</div>
                                    <div className="flex-1 bg-gray-100 rounded h-4 overflow-hidden">
                                        {value !== null && componentScale > 0 && (
                                            <div className="h-4 bg-[#1A365D]" style={{ width: `${(value / componentScale) * 100}%` }} />
                                        )}
                                    </div>
                                    <div className="w-36 text-right text-xs font-medium shrink-0">
                                        <Figure value={value} formatted={naira(value)} absent={NOT_RECORDED} />
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </section>

                <section className="bg-white rounded-xl border border-gray-200 p-5">
                    <div className="flex items-center justify-between mb-4">
                        <h3 className="text-sm font-semibold text-[#1A365D]">Loss Distribution (Latest Simulation)</h3>
                        <span className="material-symbols-outlined text-gray-400 text-lg">bar_chart</span>
                    </div>
                    {lossDistData.labels.length > 0 ? (
                        <SeriesChart
                            labels={lossDistData.labels}
                            values={lossDistData.values}
                            ariaLabel="Loss distribution by percentile"
                            valueLabel={(value) => compactNaira(value) ?? ''}
                        />
                    ) : (
                        <p className="text-sm text-gray-400 italic py-12 text-center">
                            No completed simulation has stored a loss distribution.
                        </p>
                    )}
                </section>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <section className="lg:col-span-2 bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <header className="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                        <h3 className="text-sm font-semibold text-[#1A365D]">Recent Simulations</h3>
                        <Link href={route('risk.quantification.results')} className="text-xs text-[#1A365D] font-medium hover:underline">
                            View all
                        </Link>
                    </header>

                    {recentSimulations.length === 0 ? (
                        <p className="py-10 text-center text-gray-400 text-sm">
                            <span className="material-symbols-outlined text-3xl mb-2 block">calculate</span>
                            No simulations run yet
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="data-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Run</th>
                                        <th className="text-right">Iterations</th>
                                        <th className="text-right">VaR (95%)</th>
                                        {/* 99.9%, which is the column the engine stores. */}
                                        <th className="text-right">VaR (99.9%)</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {recentSimulations.map((run) => {
                                        const url = tryRoute('risk.quantification.show-results', run.id);

                                        return (
                                            <tr key={run.id}>
                                                <td className="text-xs text-gray-500">
                                                    {run.created_at
                                                        ? new Date(run.created_at).toLocaleDateString('en-GB', {
                                                              day: '2-digit',
                                                              month: 'short',
                                                              year: 'numeric',
                                                          })
                                                        : '—'}
                                                </td>
                                                <td className="font-medium text-[#1A365D]">
                                                    {url ? (
                                                        <Link href={url} className="hover:underline">
                                                            {run.simulation_reference}
                                                        </Link>
                                                    ) : (
                                                        run.simulation_reference
                                                    )}
                                                </td>
                                                <td className="text-right text-xs">{number(run.iterations)}</td>
                                                <td className="text-right text-xs font-medium">{naira(run.var_95) ?? '—'}</td>
                                                <td className="text-right text-xs font-medium">{naira(run.var_99_9) ?? '—'}</td>
                                                <td>
                                                    <StatusBadge status={run.status} />
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                <section className="bg-white rounded-xl border border-gray-200 p-5">
                    <h3 className="text-sm font-semibold text-[#1A365D] mb-4">ICAAP Summary</h3>

                    <div className="space-y-4">
                        <div className={`p-3 rounded-lg ${carBox}`}>
                            <p className="text-xs font-medium text-gray-600">Capital Adequacy Ratio</p>
                            <p className={`text-2xl font-bold ${carText}`}>
                                {capitalAdequacyRatio === null ? NOT_ASSESSED : percent(capitalAdequacyRatio)}
                            </p>
                            <p className="text-xs text-gray-500 mt-1">
                                CBN minimum: {trimmedPercent(minimumCar)}%
                                {capitalAdequacyRatio !== null && <> &middot; {carBasis}</>}
                            </p>
                        </div>

                        <div>
                            <p className="text-xs text-gray-500 mb-1">Total Qualifying Capital</p>
                            <p className="text-sm font-semibold">
                                <Figure value={totalCapital} formatted={naira(totalCapital)} absent={NOT_RECORDED} />
                            </p>
                        </div>
                        <div>
                            {/* These are the Pillar 2A columns and the Pillar 2B stress buffer.
                                They were labelled "Pillar 1" and "Pillar 2" here. */}
                            <p className="text-xs text-gray-500 mb-1">Pillar 2A Add-on</p>
                            <p className="text-sm font-semibold">
                                <Figure value={pillar2aCapital} formatted={naira(pillar2aCapital)} absent={NOT_RECORDED} />
                            </p>
                        </div>
                        <div>
                            <p className="text-xs text-gray-500 mb-1">Pillar 2B Stress Buffer</p>
                            <p className="text-sm font-semibold">
                                <Figure value={pillar2bCapital} formatted={naira(pillar2bCapital)} absent={NOT_RECORDED} />
                            </p>
                        </div>
                        <div>
                            <p className="text-xs text-gray-500 mb-1">Capital Buffer</p>
                            <p className="text-sm font-semibold">
                                <Figure value={capitalBuffer} formatted={naira(capitalBuffer)} absent={NOT_ASSESSED} />
                            </p>
                        </div>

                        {!hasAssessment && (
                            <p className="text-xs text-yellow-800 bg-yellow-50 border border-yellow-200 rounded-lg p-3">
                                No ICAAP assessment is on record. Every capital figure above is sourced from one, so
                                they read as absent rather than as zero.
                            </p>
                        )}

                        <Link
                            href={route('risk.quantification.icaap')}
                            className="block text-center text-xs text-[#1A365D] font-medium hover:underline mt-4"
                        >
                            View full ICAAP report
                        </Link>
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
