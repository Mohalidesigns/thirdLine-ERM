import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import KpiCard from '@thirdline/ui/Components/KpiCard';
import Figure from '@/Components/Quantification/Figure';
import ReportShell from '@/Components/Quantification/ReportShell';
import { NOT_ASSESSED, NOT_RECORDED, naira, number, percent, trimmedPercent } from '@/Components/Quantification/figures';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/** A verdict cell: pass, breach, or honestly not assessable. */
function Verdict({ meets }) {
    if (meets === null) {
        return <span className="px-2 py-0.5 rounded text-[11px] font-semibold bg-gray-100 text-gray-600">Not assessable</span>;
    }

    return meets ? (
        <span className="px-2 py-0.5 rounded text-[11px] font-semibold bg-green-100 text-green-700">Pass</span>
    ) : (
        <span className="px-2 py-0.5 rounded text-[11px] font-semibold bg-red-100 text-red-700">Breach</span>
    );
}

/**
 * Stress Testing Report (migration Phase 5.2).
 *
 * Rows are CONFIDENCE LEVELS, not scenario names, and they come only from the
 * run deliberately bound to the assessment. The long notice below explaining
 * why there is no fallback to the latest completed run is load-bearing: that
 * fallback is how a single-scenario operational calibration once reached a
 * board as a macroeconomic stress test.
 */
export default function StressTesting({
    icaap,
    rows,
    stressScenarios,
    stressSim,
    minimumCar,
    totalCapital,
    totalRwa,
    carComputed,
    carReported,
    hasBoundRun,
    hasRows,
}) {
    const date = (value) =>
        value ? new Date(value).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : null;

    const runUrl = stressSim ? tryRoute('risk.quantification.show-results', stressSim.id) : null;

    return (
        <AuthenticatedLayout title="Stress Testing Report">
            <Head title="Stress Testing Report" />

            <ReportShell
                title="Stress Testing Report"
                subtitle="Capital impact of the stress simulation bound to the latest ICAAP assessment"
            >
                <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                    <KpiCard
                        title="Pre-Stress CAR"
                        value={percent(carComputed)}
                        unavailable={carComputed === null}
                        icon="shield"
                        color={carComputed !== null && carComputed >= minimumCar ? 'success' : 'danger'}
                        subtitle={`CBN minimum: ${trimmedPercent(minimumCar)}%`}
                    />
                    <KpiCard
                        title="Total Qualifying Capital"
                        value={naira(totalCapital)}
                        unavailable={totalCapital === null}
                        unavailableLabel={NOT_RECORDED}
                        icon="account_balance"
                        color="primary"
                    />
                    <KpiCard
                        title="Total RWA"
                        value={naira(totalRwa)}
                        unavailable={totalRwa === null}
                        unavailableLabel={NOT_RECORDED}
                        icon="donut_large"
                        color="primary"
                    />
                    <KpiCard
                        title="Bound Stress Simulation"
                        value={stressSim?.simulation_reference}
                        unavailable={!hasBoundRun}
                        unavailableLabel="None bound"
                        icon="calculate"
                        color="info"
                        subtitle={date(stressSim?.completed_at) ?? stressSim?.status ?? 'Nothing bound to the assessment'}
                    />
                </div>

                {carComputed === null && carReported !== null && (
                    <div className="bg-gray-50 border border-gray-200 rounded-xl p-4 mb-6 text-sm text-gray-700">
                        The assessment reports a CAR of {percent(carReported)} but carries no total risk-weighted
                        assets, so neither the pre-stress CAR nor any post-stress CAR can be derived from it. Record
                        total RWA on the assessment for the table below to produce ratios.
                    </div>
                )}

                {!icaap && (
                    <div className="bg-yellow-50 border border-yellow-200 rounded-xl p-5 mb-6 text-sm text-yellow-900">
                        <p className="font-semibold mb-1">No ICAAP assessment exists for this organisation.</p>
                        <p className="leading-relaxed">
                            A stress test is measured against a capital position. Record an ICAAP assessment with total
                            qualifying capital and total risk-weighted assets, then bind a completed simulation to it
                            as its stress simulation. This report stays empty until both exist.
                        </p>
                    </div>
                )}

                {icaap && !hasBoundRun && (
                    <div className="bg-yellow-50 border border-yellow-200 rounded-xl p-5 mb-6 text-sm text-yellow-900">
                        <p className="font-semibold mb-1">No stress simulation is bound to the latest ICAAP assessment.</p>
                        <p className="leading-relaxed">
                            To populate this report: create or select the scenarios that represent the stress, run a
                            Monte Carlo simulation over them, then set that completed run as the assessment&apos;s
                            stress simulation (<code className="text-xs">stress_simulation_id</code>).
                        </p>
                        <p className="leading-relaxed mt-2">
                            This report deliberately does <span className="font-semibold">not</span> fall back to the
                            most recently completed simulation. That fallback used to be here, and it meant an
                            unrelated run — a single operational-risk calibration, for instance — could be presented
                            to a board and to the CBN as a macroeconomic stress test. There is no way to guess which
                            run a preparer intended, so it is not guessed.
                        </p>
                    </div>
                )}

                {icaap && hasBoundRun && !hasRows && (
                    <div className="bg-yellow-50 border border-yellow-200 rounded-xl p-5 mb-6 text-sm text-yellow-900">
                        <p className="font-semibold mb-1">The bound simulation has produced no results.</p>
                        <p className="leading-relaxed">
                            {stressSim.simulation_reference} is bound to the assessment but its status is{' '}
                            <span className="font-semibold">{stressSim.status}</span> and it has no aggregate loss
                            distribution to report against.
                        </p>
                    </div>
                )}

                {hasRows && (
                    <section className="bg-white rounded-xl border border-gray-200 overflow-hidden mb-6">
                        <header className="px-5 py-4 border-b border-gray-100">
                            <h3 className="text-sm font-semibold text-[#1A365D]">Capital Impact by Confidence Level</h3>
                            <p className="text-xs text-gray-500 mt-1">
                                Source:{' '}
                                {runUrl ? (
                                    <Link href={runUrl} className="text-[#1A365D] font-semibold hover:underline">
                                        {stressSim.simulation_reference}
                                    </Link>
                                ) : (
                                    <span className="font-semibold">{stressSim.simulation_reference}</span>
                                )}{' '}
                                &middot; {number(stressSim.iterations ?? 0)} iterations &middot; seed{' '}
                                {stressSim.random_seed ?? 'not recorded'} &middot; completed{' '}
                                {date(stressSim.completed_at) ?? '—'}
                            </p>
                        </header>
                        <div className="overflow-x-auto">
                            <table className="data-table">
                                <thead>
                                    <tr>
                                        <th>Confidence level</th>
                                        <th className="text-right">Capital impact (aggregate VaR)</th>
                                        <th className="text-right">Capital after stress</th>
                                        <th className="text-right">CAR before</th>
                                        <th className="text-right">CAR after</th>
                                        <th className="text-right">Shortfall to {trimmedPercent(minimumCar)}%</th>
                                        <th>Verdict</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((row) => (
                                        <tr key={row.confidence}>
                                            <td className="font-medium text-[#1A365D]">{trimmedPercent(row.confidence)}%</td>
                                            <td className="text-right text-red-600">-{naira(row.capital_impact)}</td>
                                            <td className="text-right">
                                                <Figure value={row.capital_after} formatted={naira(row.capital_after)} absent={NOT_ASSESSED} />
                                            </td>
                                            <td className="text-right">
                                                <Figure value={carComputed} formatted={percent(carComputed)} absent={NOT_ASSESSED} />
                                            </td>
                                            <td
                                                className={`text-right ${
                                                    row.car_after === null ? '' : row.meets_minimum ? 'text-green-600' : 'text-red-600'
                                                }`}
                                            >
                                                <Figure value={row.car_after} formatted={percent(row.car_after)} absent={NOT_ASSESSED} />
                                            </td>
                                            <td className={`text-right ${(row.shortfall ?? 0) > 0 ? 'text-red-600' : 'text-gray-500'}`}>
                                                {row.shortfall === null ? (
                                                    <span className="text-gray-400 italic">{NOT_ASSESSED}</span>
                                                ) : row.shortfall > 0 ? (
                                                    naira(row.shortfall)
                                                ) : (
                                                    '—'
                                                )}
                                            </td>
                                            <td>
                                                <Verdict meets={row.meets_minimum} />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                )}

                <section className="bg-white rounded-xl border border-gray-200 overflow-hidden mb-6">
                    <header className="px-5 py-4 border-b border-gray-100">
                        <h3 className="text-sm font-semibold text-[#1A365D]">Stress Scenarios Defined by This Institution</h3>
                        <p className="text-xs text-gray-500 mt-1">Scenarios carrying a CBN stress designation or typed as stress</p>
                    </header>
                    <div className="overflow-x-auto">
                        <table className="data-table">
                            <thead>
                                <tr>
                                    <th>Reference</th>
                                    <th>Scenario</th>
                                    <th>CBN category</th>
                                    <th>Stress designation</th>
                                    <th className="text-right">Expected annual loss</th>
                                    <th>In bound run?</th>
                                </tr>
                            </thead>
                            <tbody>
                                {stressScenarios.length === 0 ? (
                                    <tr>
                                        <td colSpan={6} className="text-center py-6 text-gray-500 text-sm">
                                            This organisation has not defined any stress scenarios. Create scenarios and
                                            mark them with a CBN stress designation for them to appear here.
                                        </td>
                                    </tr>
                                ) : (
                                    stressScenarios.map((scenario) => (
                                        <tr key={scenario.reference}>
                                            <td className="text-xs font-medium text-[#1A365D]">{scenario.reference}</td>
                                            <td className="font-medium">{scenario.name}</td>
                                            <td className="text-xs">{scenario.category ?? '—'}</td>
                                            <td className="text-xs">{scenario.cbn_stress_scenario ?? '—'}</td>
                                            <td className="text-right text-xs">{naira(scenario.expected_annual_loss) ?? '—'}</td>
                                            <td>
                                                {scenario.in_bound_run ? (
                                                    <span className="px-2 py-0.5 rounded text-[11px] font-semibold bg-green-100 text-green-700">Yes</span>
                                                ) : (
                                                    <span className="px-2 py-0.5 rounded text-[11px] font-semibold bg-gray-100 text-gray-600">No</span>
                                                )}
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section className="bg-white rounded-xl border border-gray-200 p-5">
                    <h3 className="text-sm font-semibold text-[#1A365D] mb-3">Methodology</h3>
                    <div className="text-sm text-gray-600 leading-relaxed space-y-3">
                        <p>
                            Every figure above is arithmetic on stored quantities. For each confidence level the
                            simulation engine computed:
                        </p>
                        <ul className="list-disc list-inside text-xs space-y-1 text-gray-600">
                            <li>capital impact = the bound run&apos;s aggregate Value at Risk at that confidence level</li>
                            <li>capital after stress = total qualifying capital − capital impact</li>
                            <li>CAR after stress = capital after stress ÷ total risk-weighted assets × 100</li>
                            <li>shortfall = max(0, (minimum CAR ÷ 100) × total RWA − capital after stress)</li>
                            <li>verdict = CAR after stress is at or above the resolved minimum of {trimmedPercent(minimumCar)}%</li>
                        </ul>
                        <p className="text-xs">
                            Rows are confidence levels, not scenario names. This report previously listed five
                            hardcoded scenarios — &apos;Severe Recession&apos;, &apos;Oil Price Shock&apos;,
                            &apos;Naira Devaluation&apos;, &apos;Cyber Attack + Market Crash&apos; and &apos;Liquidity
                            Squeeze&apos; — with fixed CAR drops of 3.5, 2.1, 2.8, 5.2 and 1.6 percentage points. Those
                            drops were literals independent of this institution&apos;s capital, RWA and portfolio, and
                            no bank using this product had defined those scenarios. A named macro scenario belongs in
                            the scenario library, calibrated by the institution, and reaches this report by being
                            included in the run bound to the assessment.
                        </p>
                        <p className="text-xs">
                            The minimum CAR of {trimmedPercent(minimumCar)}% is resolved from the ICAAP assessment,
                            then the organisation&apos;s quantification settings, then the CBN default of 10.0%. A bank
                            on international authorisation or designated a D-SIB is measured against 15.0% and must
                            configure it.
                        </p>
                    </div>
                </section>
            </ReportShell>
        </AuthenticatedLayout>
    );
}
